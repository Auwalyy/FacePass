<?php
/**
 * Facial recognition verification endpoint (used by the kiosk).
 *
 * POST multipart/form-data:  image (JPEG/PNG frame), exam_id
 *
 *  1. authorise the verification operator (admin session) + CSRF
 *  2. validate exam_id: must be the kiosk-selected exam AND ongoing
 *  3. validate the image (size, MIME, dimensions)
 *  4. save it temporarily with a random name
 *  5. run facial_recognition/recognize.py (OpenCV LBPH)
 *  6. find the student, check account, eligibility, duplicates
 *  7. record attendance + audit log
 *  8. return JSON
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/python.php';
require_once __DIR__ . '/../includes/verification.php';

requireAdmin();
if (!is_post()) {
    json_response(['success' => false, 'verified' => false, 'reason' => 'METHOD_NOT_ALLOWED'], 405);
}
require_csrf();

// 2. Examination must be the one currently being conducted.
$exam = find_exam(post_int('exam_id'));
if ($problem = check_exam_open($exam)) {
    json_response(['success' => false, 'verified' => false] + $problem, 409);
}

// 3. Image validation.
$check = validate_image_upload($_FILES['image'] ?? null);
if (!$check['ok']) {
    json_response(['success' => false, 'verified' => false, 'reason' => 'INVALID_IMAGE', 'message' => $check['error']], 422);
}

// 4-5. Temporary file -> Python/OpenCV.
$tmp = store_temp_upload($_FILES['image'], $check['ext']);
try {
    $result = run_python('recognize.py', [
        '--image', $tmp,
        '--model-dir', FR_MODEL_PATH,
        '--threshold', (string) recognition_threshold(),
    ]);
} finally {
    @unlink($tmp);   // camera frames are never kept
}

$examPayload = exam_payload($exam);

if (!$result['ok']) {
    audit_log('FACE_VERIFY_ERROR', "{$exam['course_code']}: recognition engine error - {$result['error']}");
    json_response(['success' => false, 'verified' => false, 'reason' => 'ENGINE_ERROR',
        'message' => $result['error'] . ' Use manual verification.', 'exam' => $examPayload], 500);
}

$r = $result['data'];

if (empty($r['matched'])) {
    $reason = $r['reason'] ?? 'NO_MATCH';
    $detail = isset($r['distance']) ? " (closest distance {$r['distance']}, threshold {$r['threshold']})" : '';
    audit_log('FACE_NOT_RECOGNIZED', "{$exam['course_code']}: $reason$detail");

    $messages = [
        'NO_FACE_DETECTED' => 'No face was detected. Please look directly at the camera.',
        'MULTIPLE_FACES' => 'More than one face detected. Only one student at a time, please.',
        'MODEL_NOT_TRAINED' => 'No faces have been enrolled yet.',
        'INVALID_IMAGE' => 'The camera image could not be read.',
        'OPENCV_MISSING' => $r['message'] ?? 'OpenCV is not installed.',
        'ENGINE_ERROR' => 'The face recognition engine failed.',
    ];
    json_response([
        'success' => false,
        'verified' => false,
        'reason' => in_array($reason, ['NO_FACE_DETECTED', 'MULTIPLE_FACES'], true) ? $reason : 'FACE_NOT_RECOGNIZED',
        'detail' => $reason,
        'message' => $messages[$reason] ?? 'Please contact examination staff.',
        'confidence' => $r['confidence'] ?? null,
        'exam' => $examPayload,
    ]);
}

// 6. Map the recognised label back to a real student record.
$student = find_student((int) $r['user_id']);
if (!$student || $student['student_id'] !== $r['student_id']) {
    audit_log('FACE_NOT_RECOGNIZED', "{$exam['course_code']}: model label {$r['user_id']} does not match any student (model may be stale)");
    json_response(['success' => false, 'verified' => false, 'reason' => 'FACE_NOT_RECOGNIZED',
        'message' => 'Recognised face is not linked to an active student. Retrain the model from Settings.', 'exam' => $examPayload]);
}

// 6-7. Eligibility, duplicates, attendance, audit.
$response = verify_and_record($student, $exam, 'facial_recognition', (float) $r['confidence']);
$response['distance'] = $r['distance'] ?? null;
json_response($response);
