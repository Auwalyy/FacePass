<?php
/**
 * Queries shared by the student portal pages.
 */

defined('APP_INIT') or exit('No direct access.');

/**
 * All examinations relevant to a student: their department/level timetable,
 * plus any exam they have an eligibility or attendance record for.
 * Each row includes eligibility, attendance and a derived `att_state`:
 *   present | absent | pending | cancelled
 */
function student_exams(array $student): array
{
    $stmt = db()->prepare(
        "SELECT e.*, el.school_fees_paid, el.eligible, el.reason AS elig_reason,
                a.status AS att_status, a.verification_method, a.verification_time, a.confidence_score
         FROM examinations e
         LEFT JOIN exam_eligibility el ON el.exam_id = e.id AND el.student_id = ?
         LEFT JOIN attendance a ON a.exam_id = e.id AND a.student_id = ?
         WHERE (e.department = ? AND e.level = ?) OR el.id IS NOT NULL OR a.id IS NOT NULL
         ORDER BY e.exam_date, e.start_time"
    );
    $stmt->execute([$student['id'], $student['id'], $student['department'], $student['level']]);
    $today = date('Y-m-d');

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if ($row['status'] === 'cancelled') {
            $row['att_state'] = 'cancelled';
        } elseif ($row['att_status'] === 'present') {
            $row['att_state'] = 'present';
        } elseif (in_array($row['att_status'], ['absent', 'rejected'], true)) {
            $row['att_state'] = 'absent';
        } elseif ($row['status'] === 'completed' || $row['exam_date'] < $today) {
            $row['att_state'] = 'absent';      // exam is over and no verification exists
        } else {
            $row['att_state'] = 'pending';     // upcoming or in progress
        }
        $row['is_eligible'] = $row['school_fees_paid'] !== null && (int) $row['school_fees_paid'] && (int) $row['eligible'];
    }
    unset($row);
    return $rows;
}

/** Summary counts for a list from student_exams(). */
function attendance_summary(array $exams): array
{
    $summary = ['total' => 0, 'present' => 0, 'absent' => 0, 'pending' => 0];
    foreach ($exams as $ex) {
        if ($ex['att_state'] === 'cancelled') {
            continue;
        }
        $summary['total']++;
        $summary[$ex['att_state']]++;
    }
    return $summary;
}

function att_state_badge(string $state): string
{
    $map = ['present' => 'success', 'absent' => 'danger', 'pending' => 'secondary', 'cancelled' => 'light text-dark'];
    return '<span class="badge bg-' . ($map[$state] ?? 'secondary') . '">' . e(ucfirst($state)) . '</span>';
}

function eligibility_badge(array $exam): string
{
    if ($exam['school_fees_paid'] === null) {
        return '<span class="badge bg-light text-dark border">Not registered</span>';
    }
    return $exam['is_eligible']
        ? '<span class="badge bg-success">Eligible</span>'
        : '<span class="badge bg-danger" title="' . e($exam['elig_reason'] ?: 'Not eligible') . '">Not eligible</span>';
}
