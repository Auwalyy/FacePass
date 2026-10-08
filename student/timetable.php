<?php
/**
 * Student examination timetable (upcoming and past).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/student-data.php';

$student = requireStudent();
$exams = student_exams($student);
$today = date('Y-m-d');

$upcoming = array_values(array_filter($exams, fn($e) => $e['exam_date'] >= $today && !in_array($e['status'], ['completed'], true)));
$past = array_reverse(array_values(array_filter($exams, fn($e) => !in_array($e, $upcoming, true))));
$view = get_str('view', 10) === 'past' ? 'past' : 'upcoming';
$list = $view === 'past' ? $past : $upcoming;

$pageTitle = 'Examination Timetable';
$breadcrumbs = ['Dashboard' => 'student/dashboard.php', 'Timetable' => null];
$headerActions = '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>';
require __DIR__ . '/../includes/header.php';
?>
<ul class="nav nav-tabs mb-0">
    <li class="nav-item"><a class="nav-link<?= $view === 'upcoming' ? ' active' : '' ?>" href="?view=upcoming">Upcoming <span class="badge bg-primary"><?= count($upcoming) ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $view === 'past' ? ' active' : '' ?>" href="?view=past">Past <span class="badge bg-secondary"><?= count($past) ?></span></a></li>
</ul>
<div class="card rounded-top-0 border-top-0">
    <div class="table-responsive">
        <table class="table align-middle table-card">
            <thead><tr><th>Course Code</th><th>Course Title</th><th>Date</th><th>Start Time</th><th>End Time</th><th>Venue</th><th>Status</th><th>Eligibility</th></tr></thead>
            <tbody>
            <?php foreach ($list as $ex): ?>
                <tr class="<?= $ex['exam_date'] === $today ? 'table-warning' : '' ?>">
                    <td class="fw-semibold"><?= e($ex['course_code']) ?></td>
                    <td><?= e($ex['course_title']) ?></td>
                    <td class="text-nowrap"><?= e(fmt_date($ex['exam_date'])) ?><?= $ex['exam_date'] === $today ? ' <span class="badge bg-warning text-dark">Today</span>' : '' ?></td>
                    <td><?= e(fmt_time($ex['start_time'])) ?></td>
                    <td><?= e(fmt_time($ex['end_time'])) ?></td>
                    <td><?= e($ex['venue']) ?></td>
                    <td><?= status_badge($ex['status']) ?></td>
                    <td><?= eligibility_badge($ex) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-calendar-x"></i>No <?= e($view) ?> examinations.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i>Arrive early. Your face will be scanned at the hall entrance; you must be marked
    <strong>eligible</strong> (school fees paid and cleared) to be admitted.</p>
<?php require __DIR__ . '/../includes/footer.php'; ?>
