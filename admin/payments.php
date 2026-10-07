<?php
/**
 * Payment / eligibility management for one examination at a time.
 * Toggles are saved instantly through api/payments.php (AJAX);
 * bulk actions are normal POST forms.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

$exams = $pdo->query(
    "SELECT id, course_code, course_title, exam_date, status, department, level FROM examinations
     ORDER BY (status IN ('ongoing','scheduled')) DESC, exam_date DESC"
)->fetchAll();

$examId = is_post() ? post_int('exam_id') : get_int('exam_id');
if (!$examId) {
    $active = active_exam();
    $examId = $active ? (int) $active['id'] : (int) ($exams[0]['id'] ?? 0);
}
$exam = $examId ? find_exam($examId) : null;

// ---------------------------------------------------------------------
// Bulk actions
// ---------------------------------------------------------------------
if (is_post() && $exam) {
    require_csrf();
    $action = post_str('action', 30);
    if ($action === 'generate') {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason)
             SELECT id, ?, 0, 0, 'Awaiting payment confirmation.' FROM users
             WHERE role = 'student' AND account_status = 'active' AND department = ? AND level = ?"
        );
        $stmt->execute([$exam['id'], $exam['department'], $exam['level']]);
        audit_log('PAYMENT_UPDATE', "Generated {$stmt->rowCount()} eligibility record(s) for {$exam['course_code']}");
        flash('success', $stmt->rowCount() . ' eligibility record(s) created.');
    } elseif ($action === 'all_eligible') {
        $stmt = $pdo->prepare('UPDATE exam_eligibility SET school_fees_paid = 1, eligible = 1, reason = NULL WHERE exam_id = ?');
        $stmt->execute([$exam['id']]);
        audit_log('PAYMENT_UPDATE', "Bulk-marked all {$stmt->rowCount()} student(s) as paid & eligible for {$exam['course_code']}");
        flash('success', 'All registered students marked as paid and eligible.');
    }
    redirect('admin/payments.php?exam_id=' . $exam['id']);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = get_str('q');
$filter = get_str('filter', 20);
$rows = [];
$totals = ['registered' => 0, 'paid' => 0, 'eligible' => 0, 'missing' => 0];

if ($exam) {
    // Students registered for the exam + students of the exam's dept/level without a record yet.
    $where = "(el.id IS NOT NULL OR (u.department = ? AND u.level = ? AND u.account_status = 'active'))";
    $params = [$exam['id'], $exam['department'], $exam['level']];
    if ($q !== '') {
        $where .= " AND (u.student_id LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        array_push($params, "%$q%", "%$q%");
    }
    $stmt = $pdo->prepare(
        "SELECT u.id, u.student_id, u.first_name, u.last_name, u.department, u.level, u.face_registered, u.account_status,
                el.id AS elig_id, el.school_fees_paid, el.eligible, el.reason, el.verified_at
         FROM users u
         LEFT JOIN exam_eligibility el ON el.student_id = u.id AND el.exam_id = ?
         WHERE u.role = 'student' AND $where
         ORDER BY u.student_id"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['elig_id']) {
            $totals['registered']++;
            $totals['paid'] += (int) $r['school_fees_paid'];
            $totals['eligible'] += (int) ($r['school_fees_paid'] && $r['eligible']);
        } else {
            $totals['missing']++;
        }
        $ok = $r['elig_id'] && $r['school_fees_paid'] && $r['eligible'];
        if (($filter === 'eligible' && !$ok) || ($filter === 'not_eligible' && $ok)) {
            continue;
        }
        $rows[] = $r;
    }
}

$pageTitle = 'Payments & Eligibility';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Payments & Eligibility' => null];
$pageScripts = ['admin.js'];
require __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1 text-primary"></i>
    A student can only be verified at the examination hall when <strong>School fees paid</strong> <em>and</em>
    <strong>Eligible</strong> are both switched on for that examination. Changes save immediately and are recorded in the audit log.
    No real payment gateway is used in this MVP — the bursary/exams office confirms payment here.
</div>

<form class="filter-bar row g-2 align-items-end" method="get">
    <div class="col-md-5">
        <label class="form-label small mb-1" for="exam_id">Examination</label>
        <select class="form-select form-select-sm" id="exam_id" name="exam_id" data-autosubmit>
            <?php foreach ($exams as $ex): ?>
                <option value="<?= (int) $ex['id'] ?>" <?= $examId === (int) $ex['id'] ? 'selected' : '' ?>>
                    <?= e("{$ex['course_code']} – {$ex['course_title']} (" . date('d M Y', strtotime($ex['exam_date'])) . ", {$ex['status']})") ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small mb-1" for="q">Student</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or student ID">
    </div>
    <div class="col-md-2">
        <label class="form-label small mb-1" for="filter">Show</label>
        <select class="form-select form-select-sm" id="filter" name="filter" data-autosubmit>
            <option value="">All</option>
            <option value="eligible" <?= $filter === 'eligible' ? 'selected' : '' ?>>Eligible</option>
            <option value="not_eligible" <?= $filter === 'not_eligible' ? 'selected' : '' ?>>Not eligible</option>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply</button></div>
</form>

<?php if (!$exam): ?>
    <div class="empty-state card"><i class="bi bi-calendar-x"></i>Create an examination first.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <?php foreach ([['Registered', $totals['registered'], 'primary'], ['Fees paid', $totals['paid'], 'info'],
                        ['Eligible (paid + cleared)', $totals['eligible'], 'success'], ['Without record', $totals['missing'], 'warning']] as [$l, $v, $c]): ?>
            <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-<?= $c ?>-soft"><i class="bi bi-people"></i></div>
                <div><div class="stat-value" data-total="<?= e($l) ?>"><?= (int) $v ?></div><div class="stat-label"><?= e($l) ?></div></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span><?= e($exam['course_code'] . ' – ' . $exam['course_title']) ?> · <?= e($exam['department']) ?> <?= e($exam['level']) ?>L</span>
            <div class="d-flex gap-2">
                <?php if ($totals['missing'] > 0): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                        <button class="btn btn-sm btn-outline-primary" name="action" value="generate"><i class="bi bi-plus-circle me-1"></i>Create missing records</button></form>
                <?php endif; ?>
                <form method="post" data-confirm="Mark ALL registered students as paid and eligible for this examination?" data-confirm-class="btn-success" data-confirm-label="Mark all">
                    <?= csrf_field() ?><input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
                    <button class="btn btn-sm btn-outline-success" name="action" value="all_eligible"><i class="bi bi-check-all me-1"></i>Mark all eligible</button></form>
            </div>
        </div>
        <div id="paymentAlert" class="px-3 pt-3"></div>
        <div class="table-responsive">
            <table class="table align-middle table-card" id="eligibilityTable" data-exam-id="<?= (int) $exam['id'] ?>">
                <thead><tr><th>Student</th><th>Face ID</th><th>School Fees</th><th>Eligible</th><th>Reason (if not eligible)</th><th>Result</th><th>Last verified</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $ok = $r['elig_id'] && $r['school_fees_paid'] && $r['eligible']; ?>
                    <tr data-student-id="<?= (int) $r['id'] ?>">
                        <td><a href="<?= e(url('admin/student-view.php?id=' . $r['id'])) ?>" class="fw-semibold text-decoration-none"><?= e($r['student_id']) ?></a><br>
                            <span class="small text-muted"><?= e(full_name($r)) ?></span>
                            <?php if ($r['account_status'] !== 'active'): ?><?= status_badge($r['account_status']) ?><?php endif; ?></td>
                        <td><?= yes_no_badge($r['face_registered'], 'Yes', 'No') ?></td>
                        <td>
                            <div class="form-check form-switch eligibility-switch">
                                <input class="form-check-input js-elig" type="checkbox" role="switch" data-field="school_fees_paid"
                                       aria-label="School fees paid" <?= $r['school_fees_paid'] ? 'checked' : '' ?>>
                                <span class="small js-paid-label"><?= $r['school_fees_paid'] ? 'Paid' : 'Not paid' ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="form-check form-switch eligibility-switch">
                                <input class="form-check-input js-elig" type="checkbox" role="switch" data-field="eligible"
                                       aria-label="Eligible" <?= $r['eligible'] ? 'checked' : '' ?>>
                            </div>
                        </td>
                        <td style="min-width:220px">
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control js-reason" maxlength="255" value="<?= e($r['reason']) ?>" placeholder="e.g. Outstanding library fine">
                                <button class="btn btn-outline-secondary js-save-reason" type="button" title="Save reason"><i class="bi bi-save"></i></button>
                            </div>
                        </td>
                        <td class="js-result"><?= $r['elig_id'] ? ($ok ? '<span class="badge bg-success">ELIGIBLE</span>' : '<span class="badge bg-danger">NOT ELIGIBLE</span>') : '<span class="badge bg-light text-dark">No record</span>' ?></td>
                        <td class="small text-muted"><?= e(fmt_datetime($r['verified_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-people"></i>No students found for this examination.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
