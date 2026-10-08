<?php
/**
 * Attendance API.
 *
 * Admin:   GET exam_id[, limit]  -> recent verifications + summary for that exam (kiosk side panel)
 * Student: GET                   -> the student's own attendance records
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = requireLogin();
$pdo = db();

if ($user['role'] === 'student') {
    $stmt = $pdo->prepare(
        "SELECT e.course_code, e.course_title, e.exam_date, a.status, a.verification_method, a.verification_time
         FROM attendance a JOIN examinations e ON e.id = a.exam_id
         WHERE a.student_id = ? ORDER BY e.exam_date DESC"
    );
    $stmt->execute([$user['id']]);
    json_response(['success' => true, 'records' => $stmt->fetchAll()]);
}

$exam = find_exam(get_int('exam_id'));
if (!$exam) {
    json_response(['success' => false, 'message' => 'Examination not found.'], 404);
}
$limit = min(max(get_int('limit', 10), 1), 50);

$stmt = $pdo->prepare(
    "SELECT a.status, a.verification_method, a.verification_time, a.confidence_score,
            u.student_id, u.first_name, u.last_name
     FROM attendance a JOIN users u ON u.id = a.student_id
     WHERE a.exam_id = ? AND a.verification_time IS NOT NULL
     ORDER BY a.verification_time DESC LIMIT $limit"
);
$stmt->execute([$exam['id']]);
$records = array_map(fn($r) => [
    'student_id' => $r['student_id'],
    'name' => full_name($r),
    'status' => $r['status'],
    'method' => $r['verification_method'],
    'time' => date('g:i:s A', strtotime($r['verification_time'])),
    'confidence' => $r['confidence_score'] !== null ? (float) $r['confidence_score'] : null,
], $stmt->fetchAll());

$stmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM exam_eligibility WHERE exam_id = ?) AS registered,
        (SELECT COUNT(*) FROM attendance WHERE exam_id = ? AND status = 'present') AS present,
        (SELECT COUNT(*) FROM attendance WHERE exam_id = ? AND status = 'rejected') AS rejected"
);
$stmt->execute([$exam['id'], $exam['id'], $exam['id']]);
$summary = array_map('intval', $stmt->fetch());

json_response(['success' => true, 'exam' => $exam['course_code'], 'summary' => $summary, 'records' => $records]);
