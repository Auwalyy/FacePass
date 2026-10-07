<?php
/**
 * Payments / eligibility API (admin only).
 *
 * POST  student_id, exam_id, field = school_fees_paid|eligible|reason, value
 *   -> creates the eligibility record if missing, updates it, returns the new state.
 *
 * Rules:
 *   - switching "school fees paid" OFF also makes the student not eligible
 *     (reason: payment requirement not satisfied);
 *   - switching it ON makes the student eligible unless another reason was recorded.
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/verification.php';

requireAdmin();

if (!is_post()) {
    json_response(['success' => false, 'message' => 'POST required.'], 405);
}
require_csrf();

$pdo = db();
$student = find_student(post_int('student_id'));
$exam = find_exam(post_int('exam_id'));
$field = post_str('field', 20);

if (!$student || !$exam || !in_array($field, ['school_fees_paid', 'eligible', 'reason'], true)) {
    json_response(['success' => false, 'message' => 'Invalid request.'], 422);
}

// Fetch or create the record.
$pdo->prepare(
    "INSERT IGNORE INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason) VALUES (?, ?, 0, 0, ?)"
)->execute([$student['id'], $exam['id'], 'Awaiting payment confirmation.']);
$stmt = $pdo->prepare('SELECT * FROM exam_eligibility WHERE student_id = ? AND exam_id = ?');
$stmt->execute([$student['id'], $exam['id']]);
$rec = $stmt->fetch();

$paid = (int) $rec['school_fees_paid'];
$eligible = (int) $rec['eligible'];
$reason = $rec['reason'];
$autoReasons = [MSG_NOT_PAID, 'Awaiting payment confirmation.'];

if ($field === 'school_fees_paid') {
    $paid = post_int('value') ? 1 : 0;
    if ($paid) {
        if ($reason === null || $reason === '' || in_array($reason, $autoReasons, true)) {
            $eligible = 1;
            $reason = null;
        }
    } else {
        $eligible = 0;
        $reason = MSG_NOT_PAID;
    }
} elseif ($field === 'eligible') {
    $eligible = post_int('value') ? 1 : 0;
    if ($eligible) {
        $reason = null;
    } elseif (!$reason) {
        $reason = 'Not cleared by the examinations office.';
    }
} else {
    $reason = post_str('value', 255) ?: null;
}

$pdo->prepare('UPDATE exam_eligibility SET school_fees_paid = ?, eligible = ?, reason = ? WHERE id = ?')
    ->execute([$paid, $eligible, $reason, $rec['id']]);

audit_log('PAYMENT_UPDATE', sprintf(
    '%s / %s: fees %s, %s%s',
    $student['student_id'], $exam['course_code'], $paid ? 'PAID' : 'NOT PAID',
    $eligible ? 'ELIGIBLE' : 'NOT ELIGIBLE', $reason ? " ($reason)" : ''
));

json_response([
    'success' => true,
    'school_fees_paid' => $paid,
    'eligible' => $eligible,
    'reason' => $reason,
    'result' => ($paid && $eligible) ? 'ELIGIBLE' : 'NOT ELIGIBLE',
    'message' => "Saved for {$student['student_id']}.",
]);
