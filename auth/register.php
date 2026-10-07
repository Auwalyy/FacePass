<?php
/**
 * Student registration - performed by an administrator
 * (students cannot self-register in an examination system).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/students.php';

requireAdmin();

$data = ['level' => '', 'account_status' => 'active'];
$errors = [];

if (is_post()) {
    require_csrf();
    $data = student_input();
    $data['account_status'] = 'active';
    $errors = validate_student($data, null, true);

    if (!$errors) {
        $stmt = db()->prepare(
            "INSERT INTO users (student_id, first_name, last_name, email, phone, password, role, department, level, faculty)
             VALUES (?, ?, ?, ?, ?, ?, 'student', ?, ?, ?)"
        );
        $stmt->execute([
            $data['student_id'], $data['first_name'], $data['last_name'], $data['email'], $data['phone'],
            password_hash($_POST['password'], PASSWORD_DEFAULT), $data['department'], $data['level'], $data['faculty'],
        ]);
        $newId = (int) db()->lastInsertId();
        audit_log('STUDENT_CREATE', "Registered student {$data['student_id']} - " . full_name($data));

        // Automatically create (not-yet-cleared) eligibility rows for upcoming exams in the student's dept/level.
        $stmt = db()->prepare(
            "INSERT IGNORE INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason)
             SELECT ?, id, 0, 0, ? FROM examinations
             WHERE department = ? AND level = ? AND status IN ('scheduled','ongoing')"
        );
        $stmt->execute([$newId, 'Awaiting payment confirmation.', $data['department'], $data['level']]);

        flash('success', 'Student ' . $data['student_id'] . ' registered. Next step: enroll the student\'s face.');
        redirect('admin/student-view.php?id=' . $newId);
    }
}

$pageTitle = 'Register Student';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Students' => 'admin/students.php', 'Register' => null];
require __DIR__ . '/../includes/header.php';
?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>Please correct the highlighted fields.</div>
<?php endif; ?>
<div class="card">
    <div class="card-header"><i class="bi bi-person-plus me-2"></i>Student details</div>
    <div class="card-body">
        <form method="post" class="needs-validation" novalidate>
            <?= csrf_field() ?>
            <?php render_student_fields($data, $errors, false); ?>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="<?= e(url('admin/students.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Register Student</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
