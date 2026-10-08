<?php
/**
 * Face enrollment endpoint.
 *
 * POST (multipart/form-data)
 *   user_id   target student (admins only; students always enroll themselves)
 *   images[]  ENROLL_MIN_FACES..12 webcam frames (JPEG/PNG)
 *
 * Steps: authorise -> validate every frame -> Python/OpenCV face detection &
 * quality checks (enroll.py detect) -> store crops in uploads/faces with random
 * names -> student_faces rows -> face_registered = 1 -> retrain LBPH model.
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/python.php';
require_once __DIR__ . '/../includes/students.php';

$user = requireLogin();
if (!is_post()) {
    json_response(['success' => false, 'message' => 'POST required.'], 405);
}
require_csrf();

$pdo = db();

// ---------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------
if ($user['role'] === 'admin') {
    $student = find_student(post_int('user_id'));
    if (!$student) {
        json_response(['success' => false, 'message' => 'Student not found.'], 404);
    }
} else {
    $student = $user;
    if (get_setting('allow_student_self_enrollment', '1') !== '1') {
        json_response(['success' => false, 'message' => 'Self-enrollment is disabled. Please visit the examinations office.'], 403);
    }
    if ((int) $student['face_registered'] === 1) {
        json_response(['success' => false, 'message' => 'Your face is already enrolled. Re-enrollment must be done by an administrator.'], 403);
    }
}

// ---------------------------------------------------------------------
// Validate frames
// ---------------------------------------------------------------------
$files = normalize_files($_FILES['images'] ?? null);
if (count($files) < ENROLL_MIN_FACES || count($files) > 12) {
    json_response(['success' => false, 'message' => 'Between ' . ENROLL_MIN_FACES . ' and 12 frames are required.'], 422);
}

// Validate every frame before storing anything.
$extensions = [];
foreach ($files as $file) {
    $check = validate_image_upload($file);
    if (!$check['ok']) {
        json_response(['success' => false, 'message' => $check['error']], 422);
    }
    $extensions[] = $check['ext'];
}

if (!is_dir(FACES_PATH)) {
    mkdir(FACES_PATH, 0750, true);
}

// ---------------------------------------------------------------------
// OpenCV face detection + cropping (nothing is stored unless enough frames pass)
// ---------------------------------------------------------------------
$tempPaths = [];
try {
    foreach ($files as $i => $file) {
        $tempPaths[] = store_temp_upload($file, $extensions[$i]);
    }
    $result = run_python('enroll.py', array_merge(
        ['detect', '--output-dir', FACES_PATH, '--min-faces', (string) ENROLL_MIN_FACES],
        $tempPaths
    ));
} finally {
    // Raw frames are never kept - only the cropped, normalised faces.
    foreach ($tempPaths as $p) {
        @unlink($p);
    }
}

if (!$result['ok']) {
    audit_log('FACE_ENROLL_FAILED', "Enrollment for {$student['student_id']} failed: {$result['error']}");
    json_response(['success' => false, 'message' => $result['error']], 500);
}
$data = $result['data'];
if (empty($data['success'])) {
    audit_log('FACE_ENROLL_FAILED', "Enrollment for {$student['student_id']} rejected: " . ($data['message'] ?? 'unknown'));
    json_response([
        'success' => false,
        'message' => $data['message'] ?? 'Face enrollment failed.',
        'rejection_summary' => $data['rejection_summary'] ?? null,
    ], 422);
}

// ---------------------------------------------------------------------
// Persist: replace any previous samples for this student
// ---------------------------------------------------------------------
$oldFiles = $pdo->prepare('SELECT face_image FROM student_faces WHERE student_id = ?');
$oldFiles->execute([$student['id']]);
$oldFiles = $oldFiles->fetchAll(PDO::FETCH_COLUMN);

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM student_faces WHERE student_id = ?')->execute([$student['id']]);
    $insert = $pdo->prepare('INSERT INTO student_faces (student_id, face_image, face_encoding) VALUES (?, ?, ?)');
    foreach ($data['faces'] as $face) {
        $name = basename((string) $face['file']);
        if (!preg_match('/^[a-f0-9]{32}\.png$/', $name)) {
            throw new RuntimeException('Unexpected face file name from enroll.py');
        }
        $insert->execute([$student['id'], $name, json_encode([
            'method' => $data['method'] ?? 'haar_cascade+lbph',
            'bbox' => $face['bbox'] ?? null,
            'sharpness' => $face['sharpness'] ?? null,
            'brightness' => $face['brightness'] ?? null,
            'size' => $data['face_size'] ?? [200, 200],
        ])]);
    }
    $pdo->prepare('UPDATE users SET face_registered = 1 WHERE id = ?')->execute([$student['id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    foreach ($data['faces'] as $face) {
        @unlink(FACES_PATH . DIRECTORY_SEPARATOR . basename((string) $face['file']));
    }
    throw $e;
}
foreach ($oldFiles as $old) {
    @unlink(FACES_PATH . DIRECTORY_SEPARATOR . basename($old));
}

$accepted = (int) $data['accepted'];
audit_log('FACE_ENROLL', "Enrolled face for {$student['student_id']} ($accepted samples"
    . ($oldFiles ? ', replaced ' . count($oldFiles) . ' old' : '') . ') by ' . $user['role']);

// ---------------------------------------------------------------------
// Retrain the recogniser so the student can be identified immediately
// ---------------------------------------------------------------------
$train = retrain_face_model();

json_response([
    'success' => true,
    'accepted' => $accepted,
    'rejected' => count($data['rejected'] ?? []),
    'model_trained' => $train['ok'],
    'message' => "Face enrolled with $accepted sample(s)." . ($train['ok']
        ? ' Recognition model updated.'
        : ' WARNING: the recognition model could not be retrained (' . $train['message'] . '). Retrain from Settings.'),
]);
