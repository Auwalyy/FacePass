<?php
/**
 * Entry point: send users to the right portal.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if ($user === null) {
    redirect('auth/login.php');
}
redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
