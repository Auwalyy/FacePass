<?php
/**
 * Examination management: Upcoming / Ongoing / Completed / Cancelled views,
 * status changes, kiosk selection, delete.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

if (is_post()) {
    require_csrf();
    $exam = find_exam(post_int('id'));
    $action = post_str('action', 20);

    if (!$exam) {
        flash('danger', 'Examination not found.');
    } elseif ($action === 'status') {
        $status = post_str('status', 20);
        if (in_array($status, EXAM_STATUSES, true)) {
            $pdo->prepare('UPDATE examinations SET status = ? WHERE id = ?')->execute([$status, $exam['id']]);
            audit_log('EXAM_UPDATE', "Changed status of {$exam['course_code']} from {$exam['status']} to $status");
            flash('success', "{$exam['course_code']} is now " . ucfirst($status) . '.');
            if ($status === 'ongoing' && (int) get_setting('active_exam_id', '0') !== (int) $exam['id']) {
                flash('info', 'Tip: use "Use on kiosk" to make this the examination being verified at the hall entrance.');
            }
        }
    } elseif ($action === 'set_active') {
        set_setting('active_exam_id', (string) $exam['id']);
        audit_log('KIOSK_EXAM_SELECTED', "Verification kiosk set to {$exam['course_code']} (exam #{$exam['id']})");
        flash('success', "{$exam['course_code']} selected for the verification kiosk."
            . ($exam['status'] !== 'ongoing' ? ' Remember to set its status to Ongoing when the exam starts.' : ''));
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE exam_id = ? AND status = 'present'");
        $stmt->execute([$exam['id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash('danger', 'This examination already has attendance records. Cancel it instead of deleting.');
        } else {
            $pdo->prepare('DELETE FROM examinations WHERE id = ?')->execute([$exam['id']]);
            if ((int) get_setting('active_exam_id', '0') === (int) $exam['id']) {
                set_setting('active_exam_id', null);
            }
            audit_log('EXAM_DELETE', "Deleted examination {$exam['course_code']} - {$exam['course_title']} ({$exam['exam_date']})");
            flash('success', 'Examination deleted.');
        }
    }
    redirect('admin/examinations.php?' . http_build_query(['view' => post_str('view', 20)]));
}

// ---------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------
$views = [
    'all' => ['All', '1=1'],
    'upcoming' => ['Upcoming', "e.status = 'scheduled' AND e.exam_date >= CURDATE()"],
    'ongoing' => ['Ongoing', "e.status = 'ongoing'"],
    'completed' => ['Completed', "e.status = 'completed'"],
    'cancelled' => ['Cancelled', "e.status = 'cancelled'"],
];
$view = array_key_exists(get_str('view', 20), $views) ? get_str('view', 20) : 'all';
$q = get_str('q');

$counts = [];
foreach ($views as $key => [, $cond]) {
    $counts[$key] = (int) $pdo->query("SELECT COUNT(*) FROM examinations e WHERE $cond")->fetchColumn();
}

$where = [$views[$view][1]];
$params = [];
if ($q !== '') {
    $where[] = '(e.course_code LIKE ? OR e.course_title LIKE ? OR e.venue LIKE ? OR e.department LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM examinations e WHERE $whereSql");
$stmt->execute($params);
[$page, $perPage, $offset, $pages] = paginate((int) $stmt->fetchColumn(), 15);

$stmt = $pdo->prepare(
    "SELECT e.*,
        (SELECT COUNT(*) FROM exam_eligibility el WHERE el.exam_id = e.id) AS registered,
        (SELECT COUNT(*) FROM exam_eligibility el WHERE el.exam_id = e.id AND el.school_fees_paid = 1 AND el.eligible = 1) AS eligible,
        (SELECT COUNT(*) FROM attendance a WHERE a.exam_id = e.id AND a.status = 'present') AS present
     FROM examinations e WHERE $whereSql
     ORDER BY e.exam_date " . ($view === 'completed' ? 'DESC' : 'ASC') . ", e.start_time
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$exams = $stmt->fetchAll();
$activeId = (int) get_setting('active_exam_id', '0');

$pageTitle = 'Examinations';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Examinations' => null];
$headerActions = '<a href="' . e(url('admin/examination-create.php')) . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Create Examination</a>';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-0">
    <ul class="nav nav-tabs nav-tabs-status border-bottom-0">
        <?php foreach ($views as $key => [$label]): ?>
            <li class="nav-item">
                <a class="nav-link<?= $view === $key ? ' active' : '' ?>" href="?view=<?= e($key) ?>"><?= e($label) ?>
                    <span class="badge rounded-pill bg-light text-dark border"><?= $counts[$key] ?></span></a>
            </li>
        <?php endforeach; ?>
    </ul>
    <form class="d-flex gap-2 mb-2" method="get">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <input type="search" class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Search course, venue…">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="card rounded-top-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead><tr><th>Course</th><th>Dept / Level</th><th>Date &amp; Time</th><th>Venue</th><th>Eligible / Registered</th><th>Present</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($exams as $ex): ?>
                <tr>
                    <td><strong><?= e($ex['course_code']) ?></strong>
                        <?php if ($activeId === (int) $ex['id']): ?><span class="badge bg-primary ms-1" title="Selected on the verification kiosk"><i class="bi bi-broadcast"></i> Kiosk</span><?php endif; ?>
                        <br><span class="small text-muted"><?= e($ex['course_title']) ?></span></td>
                    <td class="small"><?= e($ex['department']) ?><br><?= e($ex['level']) ?> Level</td>
                    <td class="small text-nowrap"><?= e(fmt_date($ex['exam_date'])) ?><br><?= e(fmt_time($ex['start_time'])) ?> – <?= e(fmt_time($ex['end_time'])) ?></td>
                    <td class="small"><?= e($ex['venue']) ?></td>
                    <td><?= (int) $ex['eligible'] ?> / <?= (int) $ex['registered'] ?></td>
                    <td><?= (int) $ex['present'] ?></td>
                    <td><?= status_badge($ex['status']) ?></td>
                    <td class="text-end text-nowrap">
                        <div class="btn-group">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/examination-create.php?id=' . $ex['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">More</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($activeId !== (int) $ex['id'] && $ex['status'] !== 'cancelled'): ?>
                                    <li><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $ex['id'] ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
                                        <button class="dropdown-item" name="action" value="set_active"><i class="bi bi-broadcast me-2"></i>Use on kiosk</button></form></li>
                                <?php endif; ?>
                                <li><a class="dropdown-item" href="<?= e(url('admin/payments.php?exam_id=' . $ex['id'])) ?>"><i class="bi bi-cash-coin me-2"></i>Eligibility list</a></li>
                                <li><a class="dropdown-item" href="<?= e(url('admin/attendance.php?exam_id=' . $ex['id'])) ?>"><i class="bi bi-check2-square me-2"></i>Attendance</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <?php foreach (EXAM_STATUSES as $st): if ($st === $ex['status']) continue; ?>
                                    <li><form method="post" <?= $st === 'cancelled' ? 'data-confirm="Cancel ' . e($ex['course_code']) . '?"' : '' ?>>
                                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $ex['id'] ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="view" value="<?= e($view) ?>">
                                        <button class="dropdown-item" name="status" value="<?= e($st) ?>">Mark as <?= e(ucfirst($st)) ?></button></form></li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><form method="post" data-confirm="<?= e('Delete ' . $ex['course_code'] . '? Eligibility records for it will also be deleted.') ?>" data-confirm-label="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $ex['id'] ?>"><input type="hidden" name="view" value="<?= e($view) ?>">
                                    <button class="dropdown-item text-danger" name="action" value="delete"><i class="bi bi-trash me-2"></i>Delete</button></form></li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$exams): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-calendar-x"></i>No examinations in this view.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?><div class="card-footer bg-white"><?= render_pagination($page, $pages) ?></div><?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
