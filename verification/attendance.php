<?php
/**
 * Manual verification fallback (used when facial recognition fails).
 *
 * POST  student_id (users.id), exam_id, reason
 *
 * The examination officer has checked the student's ID card in person.
 * The same eligibility and duplicate rules as facial verification apply;
 * attendance is recorded with verification_method = 'manual' and audited.
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/verification.php';

requireAdmin();
if (!is_post()) {
    json_response(['success' => false, 'verified' => false, 'reason' => 'METHOD_NOT_ALLOWED'], 405);
}
require_csrf();

$exam = find_exam(post_int('exam_id'));
if ($problem = check_exam_open($exam)) {
    json_response(['success' => false, 'verified' => false] + $problem, 409);
}

$student = find_student(post_int('student_id'));
if (!$student) {
    json_response(['success' => false, 'verified' => false, 'reason' => 'STUDENT_NOT_FOUND', 'message' => 'Student not found.'], 404);
}

$reason = post_str('reason', 255);
if (mb_strlen($reason) < 3) {
    json_response(['success' => false, 'verified' => false, 'reason' => 'REASON_REQUIRED',
        'message' => 'Enter a short reason for manual verification (e.g. "Face not recognised - ID card checked").'], 422);
}

json_response(verify_and_record($student, $exam, 'manual', null, $reason));
