<?php
/**
 * Examination verification rules - shared by facial recognition
 * (verification/verify.php) and manual fallback (verification/attendance.php).
 *
 * Order of checks after a student has been identified:
 *   1. account is an active student
 *   2. examination is the selected kiosk exam and is ongoing
 *   3. eligibility record exists, school fees paid AND eligible
 *   4. not already marked present
 *   5. record attendance (present) + audit log
 */

defined('APP_INIT') or exit('No direct access.');

const MSG_NOT_PAID = 'School fees/payment requirement has not been satisfied.';

/**
 * Confirm the exam can be used for verification right now.
 * Returns null when valid, otherwise a failure array.
 */
function check_exam_open(?array $exam): ?array
{
    $active = active_exam();
    if ($exam === null || $active === null || (int) $active['id'] !== (int) $exam['id']) {
        return ['reason' => 'EXAM_NOT_SELECTED',
            'message' => 'This examination is not the one selected for verification.'];
    }
    if ($exam['status'] !== 'ongoing') {
        return ['reason' => 'EXAM_NOT_ONGOING',
            'message' => 'Verification is only allowed while the examination is ongoing.'];
    }
    return null;
}

/** Public-facing details of the exam for JSON responses. */
function exam_payload(array $exam): array
{
    return [
        'id' => (int) $exam['id'],
        'course_code' => $exam['course_code'],
        'course_title' => $exam['course_title'],
        'date' => fmt_date($exam['exam_date']),
        'time' => fmt_time($exam['start_time']) . ' – ' . fmt_time($exam['end_time']),
        'venue' => $exam['venue'],
    ];
}

function student_payload(array $student): array
{
    return [
        'id' => $student['student_id'],
        'name' => full_name($student),
        'department' => $student['department'],
        'level' => $student['level'],
    ];
}

/**
 * Apply the eligibility + duplicate rules and record attendance.
 *
 * @param array       $student     users row (role student)
 * @param array       $exam        examinations row (already checked with check_exam_open)
 * @param string      $method      'facial_recognition' | 'manual'
 * @param float|null  $confidence  0..1 recognition score (null for manual)
 * @param string      $note        extra text for the audit log (e.g. manual reason)
 * @return array JSON-ready response
 */
function verify_and_record(array $student, array $exam, string $method, ?float $confidence, string $note = ''): array
{
    $pdo = db();
    $label = $student['student_id'] . ' (' . full_name($student) . ')';
    $examLabel = $exam['course_code'];
    $base = [
        'student' => student_payload($student),
        'exam' => exam_payload($exam),
        'method' => $method,
        'confidence' => $confidence,
    ];

    // 1. Account must be active.
    if ($student['role'] !== 'student' || $student['account_status'] !== 'active') {
        audit_log('VERIFY_ACCOUNT_INACTIVE', "$label rejected for $examLabel: account {$student['account_status']}");
        return $base + ['success' => false, 'verified' => false, 'reason' => 'ACCOUNT_INACTIVE',
            'message' => 'This student account is not active. Please contact examination staff.'];
    }

    $pdo->beginTransaction();
    try {
        // 2. Lock any existing attendance row for this student/exam.
        $stmt = $pdo->prepare('SELECT * FROM attendance WHERE student_id = ? AND exam_id = ? FOR UPDATE');
        $stmt->execute([$student['id'], $exam['id']]);
        $existing = $stmt->fetch();

        if ($existing && $existing['status'] === 'present') {
            $pdo->commit();
            audit_log('VERIFY_DUPLICATE', "$label already verified for $examLabel (" . strtoupper($method) . ' attempt)');
            return $base + ['success' => false, 'verified' => false, 'reason' => 'ALREADY_VERIFIED',
                'message' => 'This student has already been marked present for this examination.',
                'attendance' => ['status' => 'present', 'time' => fmt_datetime($existing['verification_time']),
                    'method' => $existing['verification_method']]];
        }

        // 3. Eligibility / payment.
        $stmt = $pdo->prepare('SELECT * FROM exam_eligibility WHERE student_id = ? AND exam_id = ?');
        $stmt->execute([$student['id'], $exam['id']]);
        $elig = $stmt->fetch();

        $failReason = null;
        if (!$elig) {
            $failReason = 'No eligibility/registration record exists for this examination.';
        } elseif (!(int) $elig['school_fees_paid']) {
            $failReason = MSG_NOT_PAID;
        } elseif (!(int) $elig['eligible']) {
            $failReason = $elig['reason'] ?: 'The student has not been cleared to sit this examination.';
        }

        $now = date('Y-m-d H:i:s');

        if ($failReason !== null) {
            // Keep a 'rejected' attendance trail (never overwrite a 'present' row - handled above).
            upsert_attendance($pdo, $existing, (int) $student['id'], (int) $exam['id'], $method, 'rejected', $confidence, $now);
            $pdo->commit();
            audit_log('ELIGIBILITY_REJECTED', "$label rejected for $examLabel: $failReason" . ($note ? " | $note" : ''));
            return $base + ['success' => false, 'verified' => false, 'reason' => 'NOT_ELIGIBLE',
                'message' => $failReason, 'eligibility' => 'NOT ELIGIBLE'];
        }

        // 4/5. Record attendance.
        upsert_attendance($pdo, $existing, (int) $student['id'], (int) $exam['id'], $method, 'present', $confidence, $now);
        $pdo->prepare('UPDATE exam_eligibility SET verified_at = ? WHERE id = ?')->execute([$now, $elig['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // 23000 = unique constraint: a parallel request recorded it first.
        if ($e->getCode() === '23000') {
            return $base + ['success' => false, 'verified' => false, 'reason' => 'ALREADY_VERIFIED',
                'message' => 'This student has already been marked present for this examination.'];
        }
        throw $e;
    }

    if ($method === 'manual') {
        audit_log('MANUAL_VERIFICATION', "Manually verified $label for $examLabel" . ($note ? " | Reason: $note" : ''));
    } else {
        audit_log('FACE_VERIFY_SUCCESS', "$label verified for $examLabel (confidence "
            . number_format((float) $confidence, 4) . ')');
    }

    return $base + [
        'success' => true,
        'verified' => true,
        'reason' => 'VERIFIED',
        'message' => 'You may proceed to the examination hall.',
        'eligibility' => 'ELIGIBLE',
        'attendance' => ['status' => 'present', 'recorded' => true, 'time' => fmt_datetime($now)],
    ];
}

function upsert_attendance(PDO $pdo, $existing, int $studentId, int $examId, string $method,
                           string $status, ?float $confidence, string $time): void
{
    if ($existing) {
        $pdo->prepare(
            'UPDATE attendance SET verification_method = ?, verification_time = ?, status = ?, confidence_score = ?
             WHERE id = ?'
        )->execute([$method, $time, $status, $confidence, $existing['id']]);
    } else {
        $pdo->prepare(
            'INSERT INTO attendance (student_id, exam_id, verification_method, verification_time, status, confidence_score)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$studentId, $examId, $method, $time, $status, $confidence]);
    }
}
