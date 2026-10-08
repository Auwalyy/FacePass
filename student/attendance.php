<?php
/**
 * Student attendance overview.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/student-data.php';

$student = requireStudent();
$exams = array_reverse(student_exams($student));
$summary = attendance_summary($exams);

$pageTitle = 'My Attendance';
$breadcrumbs = ['Dashboard' => 'student/dashboard.php', 'Attendance' => null];
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-4">
    <?php foreach ([['Total examinations', $summary['total'], 'primary', 'bi-collection'], ['Present', $summary['present'], 'success', 'bi-check-circle'],
                    ['Absent', $summary['absent'], 'danger', 'bi-x-circle'], ['Pending', $summary['pending'], 'dark', 'bi-hourglass-split']] as [$l, $v, $c, $i]): ?>
        <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-<?= $c ?>-soft"><i class="bi <?= $i ?>"></i></div>
            <div><div class="stat-value"><?= (int) $v ?></div><div class="stat-label"><?= e($l) ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">Attendance records</div>
    <div class="table-responsive">
        <table class="table align-middle table-card">
            <thead><tr><th>Course</th><th>Date</th><th>Venue</th><th>Attendance</th><th>Method</th><th>Verified at</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($exams as $ex): ?>
                <tr>
                    <td><strong><?= e($ex['course_code']) ?></strong><br><span class="small text-muted"><?= e($ex['course_title']) ?></span></td>
                    <td class="text-nowrap"><?= e(fmt_date($ex['exam_date'])) ?></td>
                    <td><?= e($ex['venue']) ?></td>
                    <td><?= att_state_badge($ex['att_state']) ?>
                        <?php if ($ex['att_status'] === 'rejected'): ?><div class="small text-danger">Entry refused (not eligible)</div><?php endif; ?></td>
                    <td><?= $ex['verification_method'] && $ex['verification_time'] ? status_badge($ex['verification_method']) : '—' ?></td>
                    <td class="small"><?= e(fmt_datetime($ex['verification_time'])) ?></td>
                    <td class="text-end">
                        <?php if ($ex['att_state'] === 'absent'): ?>
                            <a class="btn btn-sm btn-outline-danger" href="<?= e(url('student/complaints.php?exam_id=' . $ex['id'])) ?>"><i class="bi bi-flag me-1"></i>Report problem</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$exams): ?>
                <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No examinations yet.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
