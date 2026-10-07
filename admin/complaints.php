<?php
/**
 * Complaint management: review, respond, resolve or reject.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

if (is_post()) {
    require_csrf();
    $stmt = $pdo->prepare('SELECT c.*, u.student_id AS matric FROM complaints c JOIN users u ON u.id = c.student_id WHERE c.id = ?');
    $stmt->execute([post_int('id')]);
    $complaint = $stmt->fetch();
    $status = post_str('status', 20);
    $response = post_str('admin_response', 2000);

    if (!$complaint || !in_array($status, COMPLAINT_STATUSES, true)) {
        flash('danger', 'Invalid complaint or status.');
    } elseif (in_array($status, ['resolved', 'rejected'], true) && mb_strlen($response) < 5) {
        flash('danger', 'Please write a response to the student before resolving or rejecting the complaint.');
    } else {
        $pdo->prepare('UPDATE complaints SET status = ?, admin_response = ? WHERE id = ?')
            ->execute([$status, $response !== '' ? $response : null, $complaint['id']]);
        $action = in_array($status, ['resolved', 'rejected'], true) ? 'COMPLAINT_RESOLUTION' : 'COMPLAINT_UPDATE';
        audit_log($action, "Complaint #{$complaint['id']} from {$complaint['matric']} set to $status"
            . ($response !== '' ? ': ' . mb_substr($response, 0, 200) : ''));
        flash('success', "Complaint #{$complaint['id']} updated to " . ucfirst($status) . '.');
    }
    redirect('admin/complaints.php?' . post_str('return', 500));
}

$status = get_str('status', 20);
$q = get_str('q');
$where = ['1=1'];
$params = [];
if (in_array($status, COMPLAINT_STATUSES, true)) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = "(u.student_id LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR c.subject LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$whereSql = implode(' AND ', $where);
$from = 'FROM complaints c JOIN users u ON u.id = c.student_id LEFT JOIN examinations e ON e.id = c.exam_id';

$stmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $whereSql");
$stmt->execute($params);
[$page, $perPage, $offset, $pages] = paginate((int) $stmt->fetchColumn(), 15);

$stmt = $pdo->prepare(
    "SELECT c.*, u.student_id AS matric, u.first_name, u.last_name, e.course_code, e.exam_date,
            (SELECT a.status FROM attendance a WHERE a.student_id = c.student_id AND a.exam_id = c.exam_id) AS att_status
     $from WHERE $whereSql
     ORDER BY FIELD(c.status, 'pending', 'reviewed', 'resolved', 'rejected'), c.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$complaints = $stmt->fetchAll();

$counts = $pdo->query('SELECT status, COUNT(*) AS n FROM complaints GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$returnQuery = http_build_query($_GET);

$pageTitle = 'Complaints';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Complaints' => null];
$pageScripts = ['admin.js'];
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-2">
    <ul class="nav nav-tabs nav-tabs-status border-bottom-0">
        <li class="nav-item"><a class="nav-link<?= $status === '' ? ' active' : '' ?>" href="?">All <span class="badge rounded-pill bg-light text-dark border"><?= array_sum($counts) ?></span></a></li>
        <?php foreach (COMPLAINT_STATUSES as $s): ?>
            <li class="nav-item"><a class="nav-link<?= $status === $s ? ' active' : '' ?>" href="?status=<?= e($s) ?>"><?= e(ucfirst($s)) ?>
                <span class="badge rounded-pill bg-light text-dark border"><?= (int) ($counts[$s] ?? 0) ?></span></a></li>
        <?php endforeach; ?>
    </ul>
    <form class="d-flex gap-2 mb-2" method="get">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <input type="search" class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Student or subject">
        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
    </form>
</div>

<div class="card rounded-top-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead><tr><th>#</th><th>Student</th><th>Exam</th><th>Complaint</th><th>Current attendance</th><th>Submitted</th><th>Status</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php foreach ($complaints as $c): ?>
                <tr>
                    <td class="text-muted"><?= (int) $c['id'] ?></td>
                    <td><?= e(full_name($c)) ?><br><span class="small text-muted"><?= e($c['matric']) ?></span></td>
                    <td><?= $c['course_code'] ? e($c['course_code']) . '<br><span class="small text-muted">' . e(fmt_date($c['exam_date'])) . '</span>' : '<span class="text-muted">General</span>' ?></td>
                    <td style="max-width:340px"><strong class="small"><?= e($c['subject']) ?></strong>
                        <div class="small text-muted text-truncate"><?= e($c['description']) ?></div></td>
                    <td><?= $c['course_code'] ? ($c['att_status'] ? status_badge($c['att_status']) : '<span class="badge bg-light text-dark">No record</span>') : '—' ?></td>
                    <td class="small text-nowrap"><?= e(fmt_datetime($c['created_at'])) ?></td>
                    <td><?= status_badge($c['status']) ?></td>
                    <td class="text-end text-nowrap">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#respondModal"
                                data-fill='<?= e(json_encode([
                                    '#cId' => $c['id'], '#cStatus' => $c['status'] === 'pending' ? 'reviewed' : $c['status'],
                                    '#cResponse' => $c['admin_response'], '#cSubject' => $c['subject'], '#cDescription' => $c['description'],
                                    '#cWho' => $c['matric'] . ' – ' . full_name($c) . ($c['course_code'] ? ' · ' . $c['course_code'] : ''),
                                ])) ?>'><i class="bi bi-reply me-1"></i>Review</button>
                        <?php if ($c['course_code']): ?>
                            <a class="btn btn-sm btn-outline-secondary" title="Open attendance for this student/exam"
                               href="<?= e(url('admin/attendance.php?' . http_build_query(['q' => $c['matric'], 'exam_id' => $c['exam_id']]))) ?>"><i class="bi bi-check2-square"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$complaints): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-chat-square"></i>No complaints found.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?><div class="card-footer bg-white"><?= render_pagination($page, $pages) ?></div><?php endif; ?>
</div>

<div class="modal fade" id="respondModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><form class="modal-content" method="post">
        <div class="modal-header"><h5 class="modal-title">Review complaint</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="cId"><input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <p class="small text-muted mb-1" id="cWho"></p>
            <h6 id="cSubject"></h6>
            <p class="bg-light border rounded p-2 small" id="cDescription" style="white-space:pre-wrap"></p>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="cStatus">Status</label>
                    <select class="form-select" name="status" id="cStatus">
                        <?php foreach (COMPLAINT_STATUSES as $s): ?><option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label" for="cResponse">Response to student</label>
                    <textarea class="form-control" name="admin_response" id="cResponse" rows="4" maxlength="2000" placeholder="Explain the outcome. If attendance was wrong, correct it on the Attendance page first."></textarea>
                    <div class="form-text">A response is required to resolve or reject. The student sees it in their portal.</div></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div>
    </form></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
