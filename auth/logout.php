<?php
/**
 * Logout (POST with CSRF token, so other sites cannot log users out).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    redirect('index.php');
}
require_csrf();

$user = current_user();
if ($user) {
    audit_log('LOGOUT', ucfirst($user['role']) . ' logged out: ' . $user['email'], (int) $user['id']);
}
logout_user();
flash('success', 'You have been logged out.');
redirect('auth/login.php');
