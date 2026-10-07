<?php
/**
 * Read-only audit log viewer.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

$q = get_str('q');
$action = get_str('action', 50);
$type = get_str('type', 20);
$date = get_str('date', 10);

$where = ['1=1'];
$params = [];
if ($type === 'verification') {
    $where[] = 'l.action IN (' . implode(',', array_fill(0, count(VERIFY_ACTIONS), '?')) . ')';
    array_push($params, ...VERIFY_ACTIONS);
}
if ($action !== '') {
    $where[] = 'l.action = ?';
    $params[] = $action;
}
if ($q !== '') {
    $where[] = '(l.description LIKE ? OR u.email LIKE ? OR l.ip_address LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if (valid_date($date)) {
    $where[] = 'DATE(l.created_at) = ?';
    $params[] = $date;
}
$whereSql = implode(' AND ', $where);
$from = 'FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id';

$stmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $whereSql");
$stmt->execute($params);
[$page, $perPage, $offset, $pages] = paginate((int) $stmt->fetchColumn(), 25);

$stmt = $pdo->prepare("SELECT l.*, u.first_name, u.last_name, u.role $from WHERE $whereSql ORDER BY l.id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();
$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Logs';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Audit Logs' => null];
require __DIR__ . '/../includes/header.php';
?>
<form class="filter-bar row g-2 align-items-end" method="get">
    <div class="col-md-4"><label class="form-label small mb-1" for="q">Search</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Description, user e-mail or IP"></div>
    <div class="col-md-3"><label class="form-label small mb-1" for="action">Action</label>
        <select class="form-select form-select-sm" id="action" name="action" data-autosubmit><option value="">All</option>
            <?php foreach ($actions as $a): ?><option <?= $action === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-1" for="type">Type</label>
        <select class="form-select form-select-sm" id="type" name="type" data-autosubmit><option value="">All</option>
            <option value="verification" <?= $type === 'verification' ? 'selected' : '' ?>>Verification attempts</option></select></div>
    <div class="col-md-2"><label class="form-label small mb-1" for="date">Date</label>
        <input type="date" class="form-control form-control-sm" id="date" name="date" value="<?= e($date) ?>"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel"></i></button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle table-card">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Description</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(fmt_datetime($l['created_at'])) ?></td>
                    <td class="small"><?= $l['first_name'] ? e(full_name($l)) . '<br><span class="text-muted">' . e($l['role']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                    <td><span class="audit-action badge bg-light text-dark border"><?= e($l['action']) ?></span></td>
                    <td class="small"><?= e($l['description']) ?></td>
                    <td class="small text-muted"><?= e($l['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-journal"></i>No log entries.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?><div class="card-footer bg-white"><?= render_pagination($page, $pages) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
