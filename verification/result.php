<?php
/**
 * Live verification log for the kiosk examination (or ?exam_id=N):
 * who has been verified, who was rejected, and failed recognition attempts today.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

$exam = get_int('exam_id') ? find_exam(get_int('exam_id')) : active_exam();

$records = [];
$pending = [];
$failed = [];
$summary = ['registered' => 0, 'present' => 0, 'rejected' => 0];

if ($exam) {
    $stmt = $pdo->prepare(
        "SELECT a.*, u.student_id AS matric, u.first_name, u.last_name
         FROM attendance a JOIN users u ON u.id = a.student_id
         WHERE a.exam_id = ? ORDER BY a.verification_time DESC"
    );
    $stmt->execute([$exam['id']]);
    $records = $stmt->fetchAll();

    // Registered students who have not been verified yet.
    $stmt = $pdo->prepare(
        "SELECT u.student_id AS matric, u.first_name, u.last_name, u.face_registered, el.school_fees_paid, el.eligible
         FROM exam_eligibility el JOIN users u ON u.id = el.student_id
         LEFT JOIN attendance a ON a.student_id = el.student_id AND a.exam_id = el.exam_id
         WHERE el.exam_id = ? AND a.id IS NULL ORDER BY u.student_id"
    );
    $stmt->execute([$exam['id']]);
    $pending = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM exam_eligibility WHERE exam_id = ?');
    $stmt->execute([$exam['id']]);
    $summary['registered'] = (int) $stmt->fetchColumn();
    foreach ($records as $r) {
        if (isset($summary[$r['status']])) {
            $summary[$r['status']]++;
        }
    }

    $stmt = $pdo->prepare(
        "SELECT description, created_at FROM audit_logs
         WHERE action IN ('FACE_NOT_RECOGNIZED', 'FACE_VERIFY_ERROR') AND description LIKE ? AND DATE(created_at) = CURDATE()
         ORDER BY id DESC LIMIT 10"
    );
    $stmt->execute([$exam['course_code'] . '%']);
    $failed = $stmt->fetchAll();
}

$exams = $pdo->query("SELECT id, course_code, exam_date FROM examinations WHERE status <> 'cancelled' ORDER BY exam_date DESC LIMIT 30")->fetchAll();

$pageTitle = 'Live Verification Log';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Verification' => 'verification/index.php', 'Log' => null];
$headerActions = '<a href="' . e(url('verification/index.php')) . '" class="btn btn-sm btn-primary"><i class="bi bi-person-bounding-box me-1"></i>Kiosk</a>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()"><i class="bi bi-arrow-clockwise"></i></button>'
    . '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i></button>';
require __DIR__ . '/../includes/header.php';
?>
<form method="get" class="filter-bar d-flex gap-2 align-items-center no-print">
    <label class="small text-muted" for="exam_id">Examination</label>
    <select class="form-select form-select-sm w-auto" id="exam_id" name="exam_id" data-autosubmit>
        <?php foreach ($exams as $ex): ?>
            <option value="<?= (int) $ex['id'] ?>" <?= $exam && (int) $exam['id'] === (int) $ex['id'] ? 'selected' : '' ?>><?= e($ex['course_code'] . ' · ' . date('d M Y', strtotime($ex['exam_date']))) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!$exam): ?>
    <div class="alert alert-warning">No examination selected.</div>
<?php else: ?>
    <h2 class="h5"><?= e($exam['course_code'] . ' – ' . $exam['course_title']) ?> <?= status_badge($exam['status']) ?></h2>
    <p class="text-muted small"><?= e(fmt_date($exam['exam_date'])) ?> · <?= e(fmt_time($exam['start_time'])) ?> – <?= e(fmt_time($exam['end_time'])) ?> · <?= e($exam['venue']) ?></p>

    <div class="row g-3 mb-4">
        <?php foreach ([['Registered', $summary['registered'], 'primary'], ['Present', $summary['present'], 'success'],
                        ['Rejected', $summary['rejected'], 'danger'], ['Not yet verified', count($pending), 'warning']] as [$l, $v, $c]): ?>
            <div class="col-6 col-md-3"><div class="card stat-card"><div class="stat-icon bg-<?= $c ?>-soft"><i class="bi bi-people"></i></div>
                <div><div class="stat-value"><?= (int) $v ?></div><div class="stat-label"><?= e($l) ?></div></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">Verification records</div>
                <div class="table-responsive">
                    <table class="table align-middle table-card">
                        <thead><tr><th>Time</th><th>Student</th><th>Method</th><th>Confidence</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td class="small"><?= e(fmt_datetime($r['verification_time'])) ?></td>
                                <td><?= e($r['matric']) ?><br><span class="small text-muted"><?= e(full_name($r)) ?></span></td>
                                <td><?= status_badge($r['verification_method']) ?></td>
                                <td><?= e(fmt_confidence($r['confidence_score'])) ?></td>
                                <td><?= status_badge($r['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$records): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>No verifications yet.</div></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header">Not yet verified (<?= count($pending) ?>)</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($pending as $p): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= e($p['matric']) ?> · <?= e(full_name($p)) ?></span>
                            <span><?= ($p['school_fees_paid'] && $p['eligible']) ? '<span class="badge bg-success">Eligible</span>' : '<span class="badge bg-danger">Not eligible</span>' ?>
                                <?= $p['face_registered'] ? '' : '<span class="badge bg-secondary" title="No face enrolled">No face</span>' ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$pending): ?><li class="list-group-item text-muted">Everyone registered has a record.</li><?php endif; ?>
                </ul>
            </div>
            <div class="card">
                <div class="card-header">Failed recognition attempts today</div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($failed as $f): ?>
                        <li class="list-group-item"><span class="text-muted"><?= e(date('g:i:s A', strtotime($f['created_at']))) ?></span> · <?= e($f['description']) ?></li>
                    <?php endforeach; ?>
                    <?php if (!$failed): ?><li class="list-group-item text-muted">None.</li><?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
