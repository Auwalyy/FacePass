<?php
/**
 * Application bootstrap - include this at the top of EVERY page/endpoint:
 *
 *     require_once __DIR__ . '/../includes/bootstrap.php';
 *
 * JSON endpoints should `define('API_REQUEST', true);` BEFORE including it,
 * so errors and auth failures are returned as JSON instead of HTML.
 */

define('APP_INIT', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------
// Error handling: never show raw PHP errors to users, log them instead.
// ---------------------------------------------------------------------
if (!is_dir(LOG_PATH)) {
    @mkdir(LOG_PATH, 0750, true);
}
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . DIRECTORY_SEPARATOR . 'php-error.log');

set_exception_handler(function (Throwable $e): void {
    error_log('[' . date('c') . '] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
    }

    $isDbError = $e instanceof PDOException;
    $message = $isDbError
        ? 'The database is unavailable. Please make sure MySQL is running and the database has been imported.'
        : 'An unexpected error occurred. Please try again or contact the administrator.';

    if (defined('API_REQUEST')) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'reason'  => 'SERVER_ERROR',
            'message' => APP_DEBUG ? $e->getMessage() : $message,
        ]);
        exit;
    }

    $detail = APP_DEBUG ? '<pre class="small mt-3">' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>' : '';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="bg-light"><div class="container py-5" style="max-width:640px">'
        . '<div class="alert alert-danger"><h5 class="alert-heading">Something went wrong</h5>'
        . htmlspecialchars($message, ENT_QUOTES) . $detail . '</div>'
        . '<a href="javascript:history.back()" class="btn btn-outline-secondary">Go back</a></div></body></html>';
    exit;
});

// ---------------------------------------------------------------------
// Security headers
// ---------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
}

// ---------------------------------------------------------------------
// Online access through a tunnel running on this machine (ngrok / cloudflared)
// ---------------------------------------------------------------------

/**
 * True when the request was forwarded by a tunnel running on this computer:
 * it comes from the loopback address AND carries a forwarding header
 * (Cloudflare: CF-Connecting-IP, ngrok: X-Forwarded-For).
 */
function via_local_tunnel(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_X_FORWARDED_FOR']));
}

/** HTTPS directly, or HTTPS terminated by the tunnel. */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return via_local_tunnel() && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// ---------------------------------------------------------------------
// Session (hardened cookie settings)
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

enforce_session_timeout();
