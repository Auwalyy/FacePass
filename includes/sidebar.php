<?php
/**
 * Role-aware sidebar navigation (offcanvas on small screens).
 */

defined('APP_INIT') or exit('No direct access.');

$current = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

if (($user['role'] ?? '') === 'admin') {
    $menu = [
        'Overview' => [
            ['admin/dashboard.php', 'bi-speedometer2', 'Dashboard'],
        ],
        'Examination Day' => [
            ['verification/index.php', 'bi-person-bounding-box', 'Verification Kiosk'],
            ['verification/result.php', 'bi-clipboard-check', 'Live Verification Log'],
        ],
        'Management' => [
            ['admin/students.php', 'bi-people', 'Students'],
            ['admin/examinations.php', 'bi-calendar-event', 'Examinations'],
            ['admin/payments.php', 'bi-cash-coin', 'Payments & Eligibility'],
            ['admin/attendance.php', 'bi-check2-square', 'Attendance'],
            ['admin/complaints.php', 'bi-chat-left-text', 'Complaints'],
        ],
        'System' => [
            ['admin/users.php', 'bi-shield-lock', 'Administrators'],
            ['admin/audit-logs.php', 'bi-journal-text', 'Audit Logs'],
            ['admin/settings.php', 'bi-gear', 'Settings'],
        ],
    ];
    // Highlight parent menu for sub-pages.
    $aliases = [
        'admin/student-view.php' => 'admin/students.php',
        'admin/enroll-face.php' => 'admin/students.php',
        'auth/register.php' => 'admin/students.php',
        'admin/examination-create.php' => 'admin/examinations.php',
    ];
} else {
    $menu = [
        'My Portal' => [
            ['student/dashboard.php', 'bi-house', 'Dashboard'],
            ['student/timetable.php', 'bi-calendar3', 'Exam Timetable'],
            ['student/examinations.php', 'bi-journal-check', 'Completed Exams'],
            ['student/attendance.php', 'bi-check2-circle', 'My Attendance'],
            ['student/complaints.php', 'bi-chat-left-dots', 'Complaints'],
            ['student/profile.php', 'bi-person-badge', 'Profile & Face ID'],
        ],
    ];
    $aliases = [];
}

$isActive = function (string $path) use ($current, $aliases): bool {
    foreach ($aliases as $child => $parent) {
        if ($parent === $path && str_ends_with($current, '/' . $child)) {
            return true;
        }
    }
    return str_ends_with($current, '/' . $path);
};
?>
<aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel">
    <div class="offcanvas-header border-bottom border-secondary-subtle">
        <a class="sidebar-brand" id="appSidebarLabel" href="<?= e(url('index.php')) ?>">
            <img src="<?= e(url('assets/images/logo.svg')) ?>" alt="" width="32" height="32">
            <span>FacePass<small>Exam Verification</small></span>
        </a>
        <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas"
                data-bs-target="#appSidebar" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body flex-column p-0">
        <div class="sidebar-desktop-brand d-none d-lg-block">
            <a class="sidebar-brand" href="<?= e(url('index.php')) ?>">
                <img src="<?= e(url('assets/images/logo.svg')) ?>" alt="" width="32" height="32">
                <span>FacePass<small>Exam Verification</small></span>
            </a>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($menu as $section => $items): ?>
                <div class="sidebar-section"><?= e($section) ?></div>
                <?php foreach ($items as [$path, $icon, $label]): ?>
                    <a class="sidebar-link<?= $isActive($path) ? ' active' : '' ?>" href="<?= e(url($path)) ?>">
                        <i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer small">
            Signed in as<br><strong><?= e(full_name($user)) ?></strong>
        </div>
    </div>
</aside>
