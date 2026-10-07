<?php
/**
 * CSRF protection.
 *
 * Forms:  <?= csrf_field() ?>   then call require_csrf() when handling POST.
 * AJAX:   send header "X-CSRF-Token" (value from <meta name="csrf-token">).
 */

defined('APP_INIT') or exit('No direct access.');

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

/** Abort the request if the CSRF token is missing or wrong. */
function require_csrf(): void
{
    if (csrf_valid()) {
        return;
    }
    if (defined('API_REQUEST')) {
        json_response(['success' => false, 'reason' => 'CSRF_FAILED',
            'message' => 'Security token expired. Please refresh the page.'], 419);
    }
    flash('danger', 'Your session security token expired. Please try again.');
    $back = $_SERVER['HTTP_REFERER'] ?? '';
    // Only redirect back within this site.
    if ($back !== '' && parse_url($back, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
        header('Location: ' . $back);
        exit;
    }
    redirect('index.php');
}
