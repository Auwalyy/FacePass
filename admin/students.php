<?php
/**
 * Student management: search, filter, activate/deactivate, delete.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/students.php';
require_once __DIR__ . '/../includes/python.php';

requireAdmin();
$pdo = db();

// ---------------------------------------------------------------------
// Actions (POST + CSRF)
// ---------------------------------------------------------------------
if (is_post()) {
    require_csrf();
    $id = post_int('id');
    $action = post_str('action', 20);
    $student = find_student($id);

    if (!$student) {
        flash('danger', 'Student not found.');
    } elseif ($action === 'toggle_status') {
        $new = $student['account_status'] === 'active' ? 'inactive' : 'active';
        $pdo->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$new, $id]);
        audit_log('STUDENT_UPDATE', "Set account status of {$student['student_id']} to $new");
        // Inactive students are excluded from the recognition model.
        if ((int) $student['face_registered']) {
            $train = retrain_face_model();
            if (!$train['ok']) {
                flash('warning', 'Status changed, but the face model could not be retrained: ' . $train['message']);
            }
        }
        flash('success', "{$student['student_id']} is now $new.");
    } elseif ($action === 'delete') {
        delete_face_files($id);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'")->execute([$id]);
        audit_log('STUDENT_DELETE', "Deleted student {$student['student_id']} - " . full_name($student));
        if ((int) $student['face_registered']) {
            retrain_face_model();
        }
        flash('success', "Student {$student['student_id']} and all related records were deleted.");
    }
    redirect('admin/students.php');
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$q = get_str('q');
$dept = get_str('department');
$level = get_str('level', 10);
$status = get_str('status', 20);
$face = get_str('face', 1);

$where = ["role = 'student'"];
$params = [];
if ($q !== '') {
    $where[] = "(student_id LIKE ? OR email LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($dept !== '') {
    $where[] = 'department = ?';
    $params[] = $dept;
}
if (in_array($level, LEVELS, true)) {
    $where[] = 'level = ?';
    $params[] = $level;
}
if (in_array($status, ACCOUNT_STATUSES, true)) {
    $where[] = 'account_status = ?';
    $params[] = $status;
}
if ($face === '0' || $face === '1') {
    $where[] = 'face_registered = ?';
    $params[] = (int) $face;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
[$page, $perPage, $offset, $pages] = paginate($total, 15);

$stmt = $pdo->prepare(
    "SELECT u.*,
        (SELECT COUNT(*) FROM attendance a WHERE a.student_id = u.id AND a.status = 'present') AS exams_present
     FROM users u WHERE $whereSql
     ORDER BY u.student_id LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$students = $stmt->fetchAll();

$pageTitle = 'Students';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Students' => null];
$headerActions = '<a href="' . e(url('auth/register.php')) . '" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Add Student</a>';
require __DIR__ . '/../includes/header.php';
?>

<form class="filter-bar row g-2 align-items-end" method="get">
    <div class="col-md-4">
        <label class="form-label small mb-1" for="q">Search</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, student ID or e-mail">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="department">Department</label>
        <select class="form-select form-select-sm" id="department" name="department" data-autosubmit>
            <option value="">All</option>
            <?php foreach (departments() as $d): ?>
                <option value="<?= e($d) ?>" <?= $dept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="level">Level</label>
        <select class="form-select form-select-sm" id="level" name="level" data-autosubmit>
            <option value="">All</option>
            <?php foreach (LEVELS as $l): ?>
                <option value="<?= e($l) ?>" <?= $level === $l ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="face">Face ID</label>
        <select class="form-select form-select-sm" id="face" name="face" data-autosubmit>
            <option value="">All</option>
            <option value="1" <?= $face === '1' ? 'selected' : '' ?>>Registered</option>
            <option value="0" <?= $face === '0' ? 'selected' : '' ?>>Not registered</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small mb-1" for="status">Account</label>
        <select class="form-select form-select-sm" id="status" name="status" data-autosubmit>
            <option value="">All</option>
            <?php foreach (ACCOUNT_STATUSES as $s): ?>
                <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 d-flex gap-2 justify-content-end">
        <a href="<?= e(url('admin/students.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
        <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Search</button>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between">
        <span><?= number_format($total) ?> student<?= $total === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead>
            <tr><th>Student ID</th><th>Name</th><th>Department</th><th>Level</th><th>Face ID</th><th>Exams Attended</th><th>Account</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($students as $s): ?>
                <tr>
                    <td class="fw-semibold"><?= e($s['student_id']) ?></td>
                    <td><?= e(full_name($s)) ?><br><span class="small text-muted"><?= e($s['email']) ?></span></td>
                    <td><?= e($s['department']) ?><br><span class="small text-muted"><?= e($s['faculty']) ?></span></td>
                    <td><?= e($s['level']) ?></td>
                    <td><?= yes_no_badge($s['face_registered'], 'Registered', 'Not registered') ?></td>
                    <td><?= (int) $s['exams_present'] ?></td>
                    <td><?= status_badge($s['account_status']) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/student-view.php?id=' . $s['id'])) ?>" title="View / edit"><i class="bi bi-eye"></i></a>
                        <a class="btn btn-sm btn-outline-dark" href="<?= e(url('admin/enroll-face.php?id=' . $s['id'])) ?>" title="Enroll face"><i class="bi bi-camera"></i></a>
                        <form method="post" class="d-inline" data-confirm="<?= e(($s['account_status'] === 'active' ? 'Deactivate ' : 'Activate ') . $s['student_id'] . '?') ?>" data-confirm-class="btn-primary">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="action" value="toggle_status">
                            <button class="btn btn-sm btn-outline-<?= $s['account_status'] === 'active' ? 'warning' : 'success' ?>"
                                    title="<?= $s['account_status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                <i class="bi <?= $s['account_status'] === 'active' ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button>
                        </form>
                        <form method="post" class="d-inline" data-confirm="<?= e('Permanently delete ' . $s['student_id'] . '? This also deletes face data, eligibility, attendance and complaints. Consider deactivating instead.') ?>" data-confirm-label="Delete">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-person-x"></i>No students match your filters.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <div class="card-footer bg-white"><?= render_pagination($page, $pages) ?></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
