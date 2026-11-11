<?php
/**
 * Examination candidates (registration) helpers.
 *
 * A student "sits" an examination when an exam_eligibility row exists for
 * them. Who gets a row is decided by the examination's audience:
 *
 *   department - every active student of the exam's department + level
 *                (rows are generated in bulk; new students are added
 *                automatically when they are registered / moved);
 *   selected   - ONLY the students the administrator adds one by one
 *                (carry-over / resit / special examinations, or a single student).
 *
 * Individual students can be added to (or removed from) either kind of exam.
 */

defined('APP_INIT') or exit('No direct access.');

const EXAM_AUDIENCES = ['department', 'selected'];
const MSG_AWAITING_PAYMENT = 'Awaiting payment confirmation.';

/**
 * Add the examinations.audience column to databases created before it existed.
 * Runs at most once per request; failures are logged, never fatal.
 */
function ensure_exam_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo = db();
        if (!$pdo->query("SHOW COLUMNS FROM examinations LIKE 'audience'")->fetch()) {
            $pdo->exec(
                "ALTER TABLE examinations
                 ADD COLUMN audience ENUM('department','selected') NOT NULL DEFAULT 'department' AFTER level"
            );
        }
    } catch (PDOException $e) {
        error_log('[' . date('c') . '] ensure_exam_schema: ' . $e->getMessage());
    }
}

/**
 * Register every active student of the exam's department/level (department-audience exams only).
 * Returns the number of new registrations.
 */
function register_department_candidates(array $exam): int
{
    if (($exam['audience'] ?? 'department') !== 'department') {
        return 0;
    }
    $stmt = db()->prepare(
        "INSERT IGNORE INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason)
         SELECT id, ?, 0, 0, ? FROM users
         WHERE role = 'student' AND account_status = 'active' AND department = ? AND level = ?"
    );
    $stmt->execute([$exam['id'], MSG_AWAITING_PAYMENT, $exam['department'], $exam['level']]);
    return $stmt->rowCount();
}

/**
 * Register a student for every upcoming department-audience exam of their department/level
 * (used after a student is created or moved to another department/level).
 */
function register_student_for_department_exams(int $studentId, string $department, string $level): int
{
    $stmt = db()->prepare(
        "INSERT IGNORE INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason)
         SELECT ?, id, 0, 0, ? FROM examinations
         WHERE audience = 'department' AND department = ? AND level = ?
           AND status IN ('scheduled','ongoing') AND exam_date >= CURDATE()"
    );
    $stmt->execute([$studentId, MSG_AWAITING_PAYMENT, $department, $level]);
    return $stmt->rowCount();
}

/** Another (non-cancelled) exam the student is registered for that overlaps $exam, or null. */
function exam_clash_for_student(int $studentId, array $exam): ?array
{
    $stmt = db()->prepare(
        "SELECT e.* FROM examinations e
         JOIN exam_eligibility el ON el.exam_id = e.id AND el.student_id = ?
         WHERE e.id <> ? AND e.status <> 'cancelled' AND e.exam_date = ?
           AND e.start_time < ? AND e.end_time > ?
         LIMIT 1"
    );
    $stmt->execute([$studentId, $exam['id'], $exam['exam_date'], $exam['end_time'], $exam['start_time']]);
    return $stmt->fetch() ?: null;
}

/**
 * Register one student for one examination.
 *
 * @param bool $cleared  true = mark school fees paid + eligible straight away
 * @return array{ok: bool, message: string}
 */
function register_candidate(array $student, array $exam, bool $cleared = false): array
{
    $pdo = db();
    if ($exam['status'] === 'cancelled' || $exam['status'] === 'completed') {
        return ['ok' => false, 'message' => "{$exam['course_code']} is {$exam['status']}; students can no longer be added."];
    }
    if ($student['account_status'] !== 'active') {
        return ['ok' => false, 'message' => "{$student['student_id']} is {$student['account_status']}. Activate the account first."];
    }
    $stmt = $pdo->prepare('SELECT id FROM exam_eligibility WHERE student_id = ? AND exam_id = ?');
    $stmt->execute([$student['id'], $exam['id']]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'message' => "{$student['student_id']} is already registered for {$exam['course_code']}."];
    }
    if ($clash = exam_clash_for_student((int) $student['id'], $exam)) {
        return ['ok' => false, 'message' => "{$student['student_id']} already has {$clash['course_code']} on "
            . fmt_date($clash['exam_date']) . ' at ' . fmt_time($clash['start_time']) . ' – ' . fmt_time($clash['end_time'])
            . ', which overlaps with this examination.'];
    }

    $pdo->prepare(
        'INSERT INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason) VALUES (?, ?, ?, ?, ?)'
    )->execute([$student['id'], $exam['id'], $cleared ? 1 : 0, $cleared ? 1 : 0, $cleared ? null : MSG_AWAITING_PAYMENT]);

    audit_log('EXAM_CANDIDATE_ADD', "Registered {$student['student_id']} for {$exam['course_code']} (exam #{$exam['id']})"
        . ($cleared ? ' as paid & eligible' : ''));
    return ['ok' => true, 'message' => "{$student['student_id']} registered for {$exam['course_code']}"
        . ($cleared ? ' (paid & eligible).' : '. Confirm payment on Payments & Eligibility.')];
}

/**
 * Remove a student's registration from an examination.
 * Not allowed once the student has been marked present.
 *
 * @return array{ok: bool, message: string}
 */
function unregister_candidate(array $student, array $exam): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT 1 FROM attendance WHERE student_id = ? AND exam_id = ? AND status = 'present'");
    $stmt->execute([$student['id'], $exam['id']]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'message' => "{$student['student_id']} has already been marked present for {$exam['course_code']} and cannot be removed."];
    }
    $stmt = $pdo->prepare('DELETE FROM exam_eligibility WHERE student_id = ? AND exam_id = ?');
    $stmt->execute([$student['id'], $exam['id']]);
    if (!$stmt->rowCount()) {
        return ['ok' => false, 'message' => "{$student['student_id']} is not registered for {$exam['course_code']}."];
    }
    // Drop any rejected/absent trail for an exam the student no longer sits.
    $pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND exam_id = ? AND status <> 'present'")
        ->execute([$student['id'], $exam['id']]);

    audit_log('EXAM_CANDIDATE_REMOVE', "Removed {$student['student_id']} from {$exam['course_code']} (exam #{$exam['id']})");
    return ['ok' => true, 'message' => "{$student['student_id']} removed from {$exam['course_code']}."];
}

/** Look up a student by matriculation number (users.student_id). */
function find_student_by_matric(string $matric): ?array
{
    $stmt = db()->prepare("SELECT * FROM users WHERE role = 'student' AND student_id = ?");
    $stmt->execute([strtoupper(trim($matric))]);
    return $stmt->fetch() ?: null;
}

/** Split a textarea/comma list of matric numbers into unique upper-case values. */
function parse_matric_list(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique($parts));
}

/** Human label for an exam's audience. */
function exam_audience_label(array $exam): string
{
    return ($exam['audience'] ?? 'department') === 'selected'
        ? 'Selected students'
        : "All {$exam['department']} {$exam['level']}L";
}
