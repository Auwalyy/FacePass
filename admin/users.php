<?php
/**
 * Administrator / examination officer accounts.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$me = requireAdmin();
$pdo = db();
$errors = [];
$form = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];

if (is_post()) {
    require_csrf();
    $action = post_str('action', 20);

    if ($action === 'create') {
        $form = [
            'first_name' => post_str('first_name', 60), 'last_name' => post_str('last_name', 60),
            'email' => strtolower(post_str('email', 120)), 'phone' => post_str('phone', 20),
        ];
        $password = $_POST['password'] ?? '';
        if (mb_strlen($form['first_name']) < 2 || mb_strlen($form['last_name']) < 2) {
            $errors[] = 'First and last name are required.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid e-mail address.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$form['email']]);
            if ($stmt->fetch()) {
                $errors[] = 'That e-mail address is already in use.';
            }
        }
        if ($form['phone'] !== '' && !preg_match('/^\+?[0-9]{7,15}$/', $form['phone'])) {
            $errors[] = 'Enter a valid phone number.';
        }
        if (!is_string($password) || strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Administrator passwords must be at least 10 characters with letters and numbers.';
        } elseif ($password !== ($_POST['password_confirm'] ?? '')) {
            $errors[] = 'Passwords do not match.';
        }

        if (!$errors) {
            $pdo->prepare(
                "INSERT INTO users (first_name, last_name, email, phone, password, role, department)
                 VALUES (?, ?, ?, ?, ?, 'admin', 'Examinations Office')"
            )->execute([$form['first_name'], $form['last_name'], $form['email'], $form['phone'] ?: null, password_hash($password, PASSWORD_DEFAULT)]);
            audit_log('ADMIN_CREATE', "Created administrator {$form['email']}");
            flash('success', 'Administrator account created.');
            redirect('admin/users.php');
        }
    } elseif ($action === 'toggle') {
        $id = post_int('id');
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$id]);
        $admin = $stmt->fetch();
        if (!$admin || $id === (int) $me['id']) {
            flash('danger', 'You cannot change the status of your own account.');
        } else {
            $new = $admin['account_status'] === 'active' ? 'inactive' : 'active';
            $pdo->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$new, $id]);
            audit_log('ADMIN_UPDATE', "Set administrator {$admin['email']} to $new");
            flash('success', "{$admin['email']} is now $new.");
        }
        redirect('admin/users.php');
    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify((string) $current, $me['password'])) {
            flash('danger', 'Your current password is incorrect.');
        } elseif (!is_string($new) || strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            flash('danger', 'New password must be at least 10 characters with letters and numbers.');
        } elseif ($new !== ($_POST['new_password_confirm'] ?? '')) {
            flash('danger', 'New passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            audit_log('PASSWORD_CHANGE', 'Administrator changed own password');
            flash('success', 'Your password has been changed.');
        }
        redirect('admin/users.php');
    }
}

$admins = $pdo->query("SELECT * FROM users WHERE role = 'admin' ORDER BY created_at")->fetchAll();

$pageTitle = 'Administrators';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Administrators' => null];
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">Administrator / examination officer accounts</div>
            <div class="table-responsive">
                <table class="table align-middle table-card">
                    <thead><tr><th>Name</th><th>E-mail</th><th>Status</th><th>Created</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($admins as $a): ?>
                        <tr>
                            <td><?= e(full_name($a)) ?><?= (int) $a['id'] === (int) $me['id'] ? ' <span class="badge bg-primary-subtle text-primary-emphasis">You</span>' : '' ?></td>
                            <td><?= e($a['email']) ?></td>
                            <td><?= status_badge($a['account_status']) ?></td>
                            <td class="small"><?= e(fmt_date($a['created_at'])) ?></td>
                            <td class="text-end">
                                <?php if ((int) $a['id'] !== (int) $me['id']): ?>
                                    <form method="post" data-confirm="<?= e(($a['account_status'] === 'active' ? 'Deactivate ' : 'Activate ') . $a['email'] . '?') ?>" data-confirm-class="btn-primary">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                                        <button class="btn btn-sm btn-outline-<?= $a['account_status'] === 'active' ? 'warning' : 'success' ?>"><?= $a['account_status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">Change my password</div>
            <div class="card-body">
                <form method="post" class="row g-3 needs-validation" novalidate>
                    <?= csrf_field() ?><input type="hidden" name="action" value="password">
                    <div class="col-md-4"><label class="form-label" for="current_password">Current password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password"></div>
                    <div class="col-md-4"><label class="form-label" for="new_password">New password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" minlength="10" required autocomplete="new-password"></div>
                    <div class="col-md-4"><label class="form-label" for="new_password_confirm">Confirm</label>
                        <input type="password" class="form-control" id="new_password_confirm" name="new_password_confirm" data-match="#new_password" required autocomplete="new-password">
                        <div class="invalid-feedback">Passwords do not match.</div></div>
                    <div class="col-12 text-end"><button class="btn btn-outline-primary">Update password</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-plus me-2"></i>Add administrator</div>
            <div class="card-body">
                <?php if ($errors): ?><div class="alert alert-danger small"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
                <form method="post" class="needs-validation" novalidate>
                    <?= csrf_field() ?><input type="hidden" name="action" value="create">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label required" for="first_name">First name</label>
                            <input class="form-control" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" required maxlength="60"></div>
                        <div class="col-6"><label class="form-label required" for="last_name">Last name</label>
                            <input class="form-control" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" required maxlength="60"></div>
                        <div class="col-12"><label class="form-label required" for="email">E-mail</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?= e($form['email']) ?>" required maxlength="120"></div>
                        <div class="col-12"><label class="form-label" for="phone">Phone</label>
                            <input class="form-control" id="phone" name="phone" value="<?= e($form['phone']) ?>" maxlength="20"></div>
                        <div class="col-6"><label class="form-label required" for="password">Password</label>
                            <input type="password" class="form-control" id="password" name="password" minlength="10" required autocomplete="new-password"></div>
                        <div class="col-6"><label class="form-label required" for="password_confirm">Confirm</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" data-match="#password" required autocomplete="new-password">
                            <div class="invalid-feedback">Passwords do not match.</div></div>
                    </div>
                    <button class="btn btn-primary w-100 mt-3">Create administrator</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
