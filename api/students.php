<?php
/**
 * Student search API (admin only) - used by the kiosk's manual verification.
 *
 * GET  q        student ID / name / e-mail fragment (min 2 chars)
 *      exam_id  optional: include eligibility + attendance for this exam
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();

$q = get_str('q', 60);
$examId = get_int('exam_id');
if (mb_strlen($q) < 2) {
    json_response(['success' => true, 'students' => []]);
}

$like = '%' . $q . '%';
$stmt = db()->prepare(
    "SELECT u.id, u.student_id, u.first_name, u.last_name, u.email, u.department, u.level,
            u.face_registered, u.account_status,
            el.school_fees_paid, el.eligible, a.status AS attendance
     FROM users u
     LEFT JOIN exam_eligibility el ON el.student_id = u.id AND el.exam_id = ?
     LEFT JOIN attendance a ON a.student_id = u.id AND a.exam_id = ?
     WHERE u.role = 'student'
       AND (u.student_id LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ?)
     ORDER BY u.student_id LIMIT 15"
);
$stmt->execute([$examId, $examId, $like, $like, $like]);

$students = array_map(fn($r) => [
    'id' => (int) $r['id'],
    'student_id' => $r['student_id'],
    'name' => full_name($r),
    'email' => $r['email'],
    'department' => $r['department'],
    'level' => $r['level'],
    'face_registered' => (bool) $r['face_registered'],
    'account_status' => $r['account_status'],
    'eligible' => (bool) ($r['school_fees_paid'] && $r['eligible']),
    'attendance' => $r['attendance'],
], $stmt->fetchAll());

json_response(['success' => true, 'students' => $students]);
