<?php
/**
 * Examinations API.
 *
 * GET                    admin: all exams (optional ?status=) / student: own timetable
 * POST action=set_active admin: select the kiosk examination (exam_id)
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/bootstrap.php';

$user = requireLogin();
$pdo = db();

if (is_post()) {
    if ($user['role'] !== 'admin') {
        json_response(['success' => false, 'reason' => 'FORBIDDEN', 'message' => 'Administrators only.'], 403);
    }
    require_csrf();
    $exam = find_exam(post_int('exam_id'));
    if (post_str('action', 20) !== 'set_active' || !$exam || $exam['status'] === 'cancelled') {
        json_response(['success' => false, 'message' => 'Invalid examination.'], 422);
    }
    set_setting('active_exam_id', (string) $exam['id']);
    audit_log('KIOSK_EXAM_SELECTED', "Verification kiosk set to {$exam['course_code']} (exam #{$exam['id']}) via API");
    json_response(['success' => true, 'message' => "{$exam['course_code']} selected for verification.", 'ongoing' => $exam['status'] === 'ongoing']);
}

$fields = 'e.id, e.course_code, e.course_title, e.department, e.level, e.exam_date, e.start_time, e.end_time, e.venue, e.status';

if ($user['role'] === 'student') {
    $stmt = $pdo->prepare(
        "SELECT $fields FROM examinations e
         LEFT JOIN exam_eligibility el ON el.exam_id = e.id AND el.student_id = ?
         WHERE (e.department = ? AND e.level = ?) OR el.id IS NOT NULL
         ORDER BY e.exam_date, e.start_time"
    );
    $stmt->execute([$user['id'], $user['department'], $user['level']]);
} else {
    $status = get_str('status', 20);
    if (in_array($status, EXAM_STATUSES, true)) {
        $stmt = $pdo->prepare("SELECT $fields FROM examinations e WHERE e.status = ? ORDER BY e.exam_date, e.start_time");
        $stmt->execute([$status]);
    } else {
        $stmt = $pdo->query("SELECT $fields FROM examinations e ORDER BY e.exam_date, e.start_time");
    }
}

json_response(['success' => true, 'active_exam_id' => (int) get_setting('active_exam_id', '0'), 'examinations' => $stmt->fetchAll()]);
