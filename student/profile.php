<?php
/**
 * Student profile: personal details, password change, Face ID status / self-enrollment.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$student = requireStudent();
$pdo = db();

if (is_post()) {
    require_csrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    if (!password_verify((string) $current, $student['password'])) {
        flash('danger', 'Your current password is incorrect.');
    } elseif (!is_string($new) || strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        flash('danger', 'New password must be at least 8 characters and contain letters and numbers.');
    } elseif ($new !== ($_POST['new_password_confirm'] ?? '')) {
        flash('danger', 'New passwords do not match.');
    } else {
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $student['id']]);
        audit_log('PASSWORD_CHANGE', "{$student['student_id']} changed their password");
        flash('success', 'Your password has been changed.');
    }
    redirect('student/profile.php');
}

$canSelfEnroll = !$student['face_registered'] && get_setting('allow_student_self_enrollment', '1') === '1';
$enrollUserId = (int) $student['id'];
$enrollRedirect = 'student/profile.php';

$pageTitle = 'Profile & Face ID';
$breadcrumbs = ['Dashboard' => 'student/dashboard.php', 'Profile' => null];
$pageScripts = $canSelfEnroll ? ['enroll.js'] : [];
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-person-badge me-2"></i>My details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Student ID</dt><dd class="col-7"><?= e($student['student_id']) ?></dd>
                    <dt class="col-5">Name</dt><dd class="col-7"><?= e(full_name($student)) ?></dd>
                    <dt class="col-5">E-mail</dt><dd class="col-7"><?= e($student['email']) ?></dd>
                    <dt class="col-5">Phone</dt><dd class="col-7"><?= e($student['phone']) ?></dd>
                    <dt class="col-5">Faculty</dt><dd class="col-7"><?= e($student['faculty']) ?></dd>
                    <dt class="col-5">Department</dt><dd class="col-7"><?= e($student['department']) ?></dd>
                    <dt class="col-5">Level</dt><dd class="col-7"><?= e($student['level']) ?></dd>
                    <dt class="col-5">Face ID</dt><dd class="col-7"><?= yes_no_badge($student['face_registered'], 'Registered', 'Not registered') ?></dd>
                </dl>
                <p class="small text-muted mt-3 mb-0">To correct your details, contact the examinations office.</p>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-key me-2"></i>Change password</div>
            <div class="card-body">
                <form method="post" class="needs-validation" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-2"><label class="form-label" for="current_password">Current password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password"></div>
                    <div class="mb-2"><label class="form-label" for="new_password">New password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required autocomplete="new-password"></div>
                    <div class="mb-3"><label class="form-label" for="new_password_confirm">Confirm new password</label>
                        <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm" data-match="#new_password" required autocomplete="new-password">
                        <div class="invalid-feedback">Passwords do not match.</div></div>
                    <button class="btn btn-outline-primary w-100">Update password</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-bounding-box me-2"></i>Face ID</div>
            <div class="card-body">
                <?php if ($student['face_registered']): ?>
                    <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i>Your face is registered. You will be identified automatically at the examination hall entrance.
                        If you have changed your appearance significantly, ask the examinations office to re-enroll you.</div>
                <?php elseif ($canSelfEnroll): ?>
                    <p class="small text-muted">Register your face once so the examination kiosk can recognise you. Your images are stored securely and are only visible to examination administrators.</p>
                    <?php require __DIR__ . '/../includes/enroll-widget.php'; ?>
                <?php else: ?>
                    <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Your face is not registered. Please visit the examinations office with your ID card to complete face enrollment before your first examination.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
