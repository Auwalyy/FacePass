<?php
/**
 * Supervised face enrollment by an administrator / examination officer.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();

$student = find_student(get_int('id'));
if (!$student) {
    flash('danger', 'Student not found.');
    redirect('admin/students.php');
}

$enrollUserId = (int) $student['id'];
$enrollRedirect = 'admin/student-view.php?id=' . $student['id'] . '&tab=face';

$pageTitle = 'Face Enrollment';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Students' => 'admin/students.php',
    $student['student_id'] => 'admin/student-view.php?id=' . $student['id'], 'Face Enrollment' => null];
$pageScripts = ['enroll.js'];
require __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span><i class="bi bi-person-bounding-box me-2"></i><?= e(full_name($student)) ?> · <?= e($student['student_id']) ?></span>
        <?= yes_no_badge($student['face_registered'], 'Currently registered', 'Not registered') ?>
    </div>
    <div class="card-body">
        <?php if ($student['face_registered']): ?>
            <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>
                This student already has face data. A successful new enrollment <strong>replaces</strong> the existing samples.</div>
        <?php endif; ?>
        <div class="alert alert-light border small"><i class="bi bi-shield-lock me-1"></i>
            Verify the student's identity card <strong>before</strong> enrolling. The enrolled face is what the examination kiosk will trust.</div>
        <?php require __DIR__ . '/../includes/enroll-widget.php'; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
