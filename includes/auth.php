<?php
/**
 * Session authentication and role-based authorisation helpers.
 *
 *   requireLogin()    - any logged-in user
 *   requireAdmin()    - administrators only
 *   requireStudent()  - students only
 */

defined('APP_INIT') or exit('No direct access.');

/** Currently logged-in user row (fresh from the DB, cached per request). */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row && $row['account_status'] === 'active' && $row['role'] === ($_SESSION['role'] ?? '')) {
            $user = $row;
        } else {
            // Account deleted/deactivated or role changed - end the session.
            logout_user();
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/** Log the user in safely (prevents session fixation). */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf_token']); // fresh CSRF token for the new session
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    // Start a brand-new empty session so flash messages still work.
    session_start();
    session_regenerate_id(true);
}

/** Log out users who have been idle for too long. */
function enforce_session_timeout(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $last = $_SESSION['last_activity'] ?? time();
    if (time() - $last > SESSION_IDLE_TIMEOUT) {
        logout_user();
        flash('warning', 'You were logged out after a period of inactivity.');
        return;
    }
    $_SESSION['last_activity'] = time();
}

/** Respond with 401/403 (JSON for APIs, redirect for pages). */
function deny_access(int $status, string $message): void
{
    if (defined('API_REQUEST')) {
        json_response(['success' => false, 'reason' => $status === 401 ? 'UNAUTHENTICATED' : 'FORBIDDEN',
            'message' => $message], $status);
    }
    if ($status === 401) {
        flash('warning', $message);
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('auth/login.php');
    }
    http_response_code(403);
    flash('danger', $message);
    redirect(current_user()['role'] === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
}

function requireLogin(): array
{
    $user = current_user();
    if ($user === null) {
        deny_access(401, 'Please log in to continue.');
    }
    return $user;
}

function requireAdmin(): array
{
    $user = requireLogin();
    if ($user['role'] !== 'admin') {
        deny_access(403, 'You do not have permission to access that page.');
    }
    return $user;
}

function requireStudent(): array
{
    $user = requireLogin();
    if ($user['role'] !== 'student') {
        deny_access(403, 'That page is only available to students.');
    }
    return $user;
}

/** Number of failed logins from this IP within the lockout window. */
function recent_failed_logins(): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE action = 'LOGIN_FAILED' AND ip_address = ?
           AND created_at >= (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->execute([client_ip(), LOGIN_LOCKOUT_MINUTES]);
    return (int) $stmt->fetchColumn();
}
