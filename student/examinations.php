<?php
/**
 * Examinations the student has written (attendance = present).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/student-data.php';

$student = requireStudent();
$completed = array_reverse(array_values(array_filter(student_exams($student), fn($e) => $e['att_state'] === 'present')));

$pageTitle = 'Completed Examinations';
$breadcrumbs = ['Dashboard' => 'student/dashboard.php', 'Completed Examinations' => null];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header"><?= count($completed) ?> examination(s) written</div>
    <div class="table-responsive">
        <table class="table align-middle table-card">
            <thead><tr><th>Course</th><th>Date</th><th>Venue</th><th>Attendance</th><th>Verification Method</th><th>Verification Time</th></tr></thead>
            <tbody>
            <?php foreach ($completed as $ex): ?>
                <tr>
                    <td><strong><?= e($ex['course_code']) ?></strong><br><span class="small text-muted"><?= e($ex['course_title']) ?></span></td>
                    <td class="text-nowrap"><?= e(fmt_date($ex['exam_date'])) ?></td>
                    <td><?= e($ex['venue']) ?></td>
                    <td><?= status_badge('present') ?></td>
                    <td><?= status_badge($ex['verification_method']) ?></td>
                    <td class="small"><?= e(fmt_datetime($ex['verification_time'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$completed): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="bi bi-journal"></i>You have not written any examinations yet.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-muted mt-3">Something missing? <a href="<?= e(url('student/complaints.php')) ?>">Submit an attendance complaint</a>.</p>
<?php require __DIR__ . '/../includes/footer.php'; ?>
