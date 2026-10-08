<?php
/**
 * Student dashboard.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/student-data.php';

$student = requireStudent();
$exams = student_exams($student);
$summary = attendance_summary($exams);
$today = date('Y-m-d');
$nowTime = date('H:i:s');

$todayExams = array_values(array_filter($exams, fn($e) => $e['exam_date'] === $today && $e['status'] !== 'cancelled'));
$next = null;
foreach ($exams as $e) {
    $later = $e['exam_date'] > $today || ($e['exam_date'] === $today && $e['start_time'] > $nowTime);
    if ($later && $e['status'] === 'scheduled' && !in_array($e, $todayExams, true)) {
        $next = $e;
        break;
    }
}
$completed = array_values(array_filter($exams, fn($e) => $e['att_state'] === 'present'));

$stmt = db()->prepare(
    "SELECT c.*, e.course_code FROM complaints c LEFT JOIN examinations e ON e.id = c.exam_id
     WHERE c.student_id = ? AND c.status IN ('pending', 'reviewed') ORDER BY c.created_at DESC"
);
$stmt->execute([$student['id']]);
$openComplaints = $stmt->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="card welcome-banner mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h2 class="h4 mb-1">Welcome, <?= e(full_name($student)) ?></h2>
            <div class="text-muted-light small"><?= e($student['student_id']) ?> · <?= e($student['department']) ?> · <?= e($student['level']) ?> Level · <?= e($student['faculty']) ?></div>
        </div>
        <div class="text-end">
            <?php if ($student['face_registered']): ?>
                <span class="badge bg-success fs-6"><i class="bi bi-person-check me-1"></i>Face ID registered</span>
            <?php else: ?>
                <a class="btn btn-light btn-sm" href="<?= e(url('student/profile.php')) ?>"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Face ID not registered</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100 exam-highlight today">
            <div class="card-header"><i class="bi bi-calendar-day me-2"></i>Today's Examination</div>
            <div class="card-body">
                <?php if (!$todayExams): ?>
                    <p class="text-muted mb-0">You have no examination today.</p>
                <?php endif; ?>
                <?php foreach ($todayExams as $ex): ?>
                    <div class="mb-3">
                        <div class="course-code"><?= e($ex['course_code']) ?> <?= status_badge($ex['status']) ?></div>
                        <div class="fw-semibold"><?= e($ex['course_title']) ?></div>
                        <div class="small text-muted"><i class="bi bi-clock me-1"></i><?= e(fmt_time($ex['start_time'])) ?> – <?= e(fmt_time($ex['end_time'])) ?>
                            · <i class="bi bi-geo-alt me-1"></i><?= e($ex['venue']) ?></div>
                        <div class="mt-2 d-flex flex-wrap gap-2">
                            <?= eligibility_badge($ex) ?> <?= att_state_badge($ex['att_state']) ?>
                        </div>
                        <?php if ($ex['school_fees_paid'] !== null && !$ex['is_eligible']): ?>
                            <div class="alert alert-danger small mt-2 mb-0 py-2"><i class="bi bi-x-circle me-1"></i><?= e($ex['elig_reason'] ?: 'You are not eligible for this examination.') ?> Please contact the bursary / examinations office before the exam.</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100 exam-highlight">
            <div class="card-header"><i class="bi bi-calendar-event me-2"></i>Next Examination</div>
            <div class="card-body">
                <?php if ($next): ?>
                    <div class="course-code"><?= e($next['course_code']) ?></div>
                    <div class="fw-semibold"><?= e($next['course_title']) ?></div>
                    <div class="small text-muted"><i class="bi bi-calendar me-1"></i><?= e(fmt_date($next['exam_date'])) ?>
                        · <?= e(fmt_time($next['start_time'])) ?> – <?= e(fmt_time($next['end_time'])) ?></div>
                    <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= e($next['venue']) ?></div>
                    <div class="mt-2"><?= eligibility_badge($next) ?></div>
                <?php else: ?>
                    <p class="text-muted mb-0">No upcoming examinations scheduled.</p>
                <?php endif; ?>
                <a href="<?= e(url('student/timetable.php')) ?>" class="btn btn-sm btn-outline-primary mt-3">Full timetable</a>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Attendance Summary</div>
    <div class="row g-0 text-center">
        <?php foreach ([['Total examinations', $summary['total'], 'text-primary'], ['Present', $summary['present'], 'text-success'],
                        ['Absent', $summary['absent'], 'text-danger'], ['Pending', $summary['pending'], 'text-secondary']] as [$l, $v, $c]): ?>
            <div class="col-6 col-md-3 summary-tile border-end"><div class="value <?= $c ?>"><?= (int) $v ?></div><div class="label"><?= e($l) ?></div></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between"><span><i class="bi bi-journal-check me-2"></i>Completed Examinations</span>
                <a class="small" href="<?= e(url('student/examinations.php')) ?>">View all</a></div>
            <ul class="list-group list-group-flush">
                <?php foreach (array_slice(array_reverse($completed), 0, 5) as $ex): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><strong><?= e($ex['course_code']) ?></strong> · <?= e($ex['course_title']) ?><br>
                            <span class="small text-muted"><?= e(fmt_date($ex['exam_date'])) ?></span></span>
                        <span class="text-end small"><?= status_badge('present') ?><br><?= e(fmt_time($ex['verification_time'])) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$completed): ?><li class="list-group-item text-muted">No completed examinations yet.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between"><span><i class="bi bi-chat-left-dots me-2"></i>Open Complaints</span>
                <a class="small" href="<?= e(url('student/complaints.php')) ?>">New complaint</a></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($openComplaints as $c): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="small"><strong><?= e($c['subject']) ?></strong><?= $c['course_code'] ? '<br><span class="text-muted">' . e($c['course_code']) . '</span>' : '' ?></span>
                        <?= status_badge($c['status']) ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$openComplaints): ?><li class="list-group-item text-muted">No open complaints.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
