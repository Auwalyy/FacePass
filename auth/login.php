<?php
/**
 * Login for students (student ID or e-mail) and administrators (e-mail).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
$identifier = '';

if (is_post()) {
    require_csrf();
    $identifier = post_str('identifier', 120);
    $password = $_POST['password'] ?? '';

    if (recent_failed_logins() >= LOGIN_MAX_ATTEMPTS) {
        $error = 'Too many failed attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } elseif ($identifier === '' || !is_string($password) || $password === '') {
        $error = 'Please enter your Student ID / e-mail and password.';
    } else {
        // Students may use their student ID or e-mail; admins use e-mail.
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? OR (student_id = ? AND role = \'student\') LIMIT 1');
        $stmt->execute([$identifier, strtoupper($identifier)]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['account_status'] !== 'active') {
                $error = 'Your account is ' . $user['account_status'] . '. Please contact the examinations office.';
                audit_log('LOGIN_BLOCKED', "Login attempt on {$user['account_status']} account {$user['email']}", (int) $user['id']);
            } else {
                // Upgrade the hash transparently if the algorithm/cost changed.
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                }
                login_user($user);
                audit_log('LOGIN_SUCCESS', ucfirst($user['role']) . ' logged in: ' . $user['email'], (int) $user['id']);

                $intended = $_SESSION['intended_url'] ?? null;
                unset($_SESSION['intended_url']);
                $home = $user['role'] === 'admin' ? 'admin/' : 'student/';
                // Only honour an intended URL inside this application and the user's own portal.
                if (is_string($intended) && str_starts_with($intended, base_url() . '/' . $home)) {
                    header('Location: ' . $intended);
                    exit;
                }
                redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
            }
        } else {
            $error = 'Invalid credentials. Please check your details and try again.';
            audit_log('LOGIN_FAILED', 'Failed login for identifier: ' . $identifier, null);
        }
    }
}

$pageTitle = 'Sign in';
$layout = 'plain';
$skipFlashes = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrapper">
    <div class="card auth-card">
        <div class="card-body p-4 p-md-5">
            <div class="auth-brand">
                <img src="<?= e(url('assets/images/logo.svg')) ?>" alt="FacePass logo" width="56" height="56">
                <h1>Examination Verification System</h1>
                <p class="text-muted small mb-0"><?= e(INSTITUTION_NAME) ?></p>
            </div>

            <?php foreach (get_flashes() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> py-2 small" role="alert"><?= e($flash['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small" role="alert"><i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="needs-validation" novalidate autocomplete="on">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="identifier" class="form-label">Student ID or E-mail</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="identifier" name="identifier"
                               value="<?= e($identifier) ?>" placeholder="e.g. SWE/2026/001 or admin@buk.edu.ng"
                               required maxlength="120" autofocus>
                        <div class="invalid-feedback">Enter your student ID or e-mail.</div>
                    </div>
                    <div class="form-text">Administrators sign in with their e-mail address.</div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                        <button class="btn btn-outline-secondary" type="button" data-toggle-password="#password" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        <div class="invalid-feedback">Enter your password.</div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign in
                </button>
            </form>
            <p class="text-center text-muted small mt-4 mb-0">
                Students are registered by the Examinations Office.<br>Forgot your password? Contact the administrator.
            </p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
