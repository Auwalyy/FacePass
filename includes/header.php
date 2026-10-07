<?php
/**
 * Page header + layout opening.
 *
 * Variables a page may set BEFORE including this file:
 *   $pageTitle   (string)  title shown in <title> and the page heading
 *   $breadcrumbs (array)   ['Label' => 'relative/url.php' or null for current]
 *   $layout      (string)  'app' (default, sidebar layout) or 'plain' (login etc.)
 *   $pageScripts (array)   extra JS files (relative to assets/js/)
 *   $bodyClass   (string)  extra class on <body>
 *   $skipFlashes (bool)    page renders flash messages itself
 */

defined('APP_INIT') or exit('No direct access.');

$pageTitle = $pageTitle ?? APP_NAME;
$layout = $layout ?? 'app';
$breadcrumbs = $breadcrumbs ?? [];
$bodyClass = $bodyClass ?? '';
$user = current_user();
$role = $user['role'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(base_url()) ?>">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(url('assets/images/logo.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <?php if ($role === 'admin'): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>">
    <?php elseif ($role === 'student'): ?>
        <link rel="stylesheet" href="<?= e(url('assets/css/student.css')) ?>">
    <?php endif; ?>
</head>
<body class="<?= e(trim("layout-$layout $bodyClass")) ?>">

<?php if ($layout === 'app' && $user): ?>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <nav class="navbar navbar-expand bg-white border-bottom app-topbar sticky-top">
            <div class="container-fluid">
                <button class="btn btn-outline-secondary btn-sm d-lg-none me-2" type="button"
                        data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar"
                        aria-label="Open menu">
                    <i class="bi bi-list"></i>
                </button>
                <span class="navbar-text fw-semibold text-secondary-emphasis d-none d-sm-inline">
                    <?= e(INSTITUTION_NAME) ?> · Examinations
                </span>
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="avatar-circle"><?= e(strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1))) ?></span>
                            <span class="d-none d-md-inline"><?= e(full_name($user)) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li><span class="dropdown-item-text small text-muted">
                                <?= $role === 'admin' ? 'Administrator' : e($user['student_id']) ?></span></li>
                            <?php if ($role === 'student'): ?>
                                <li><a class="dropdown-item" href="<?= e(url('student/profile.php')) ?>"><i class="bi bi-person me-2"></i>My Profile</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="<?= e(url('admin/settings.php')) ?>"><i class="bi bi-gear me-2"></i>Settings</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form action="<?= e(url('auth/logout.php')) ?>" method="post" class="m-0">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Log out</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>

        <main class="app-content container-fluid">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <?php if ($breadcrumbs): ?>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb small mb-1">
                                <?php foreach ($breadcrumbs as $label => $link): ?>
                                    <?php if ($link): ?>
                                        <li class="breadcrumb-item"><a href="<?= e(url($link)) ?>"><?= e($label) ?></a></li>
                                    <?php else: ?>
                                        <li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    <?php endif; ?>
                    <h1 class="h4 mb-0 page-title"><?= e($pageTitle) ?></h1>
                </div>
                <?php if (!empty($headerActions)): ?>
                    <div class="d-flex gap-2"><?= $headerActions /* trusted HTML built by the page */ ?></div>
                <?php endif; ?>
            </div>
<?php else: ?>
<main class="plain-content">
<?php endif; ?>

<?php foreach (empty($skipFlashes) ? get_flashes() : [] as $flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
