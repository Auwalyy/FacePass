<?php
/**
 * Attendance management: search/filter, manual corrections (audited),
 * add records, mark absentees, CSV export.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

// ---------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------
if (is_post()) {
    require_csrf();
    $action = post_str('action', 20);
    $reason = post_str('reason', 255);

    if ($action === 'correct') {
        $stmt = $pdo->prepare(
            'SELECT a.*, u.student_id AS matric, e.course_code FROM attendance a
             JOIN users u ON u.id = a.student_id JOIN examinations e ON e.id = a.exam_id WHERE a.id = ?'
        );
        $stmt->execute([post_int('id')]);
        $rec = $stmt->fetch();
        $status = post_str('status', 20);

        if (!$rec || !in_array($status, ATTENDANCE_STATUSES, true)) {
            flash('danger', 'Invalid attendance record or status.');
        } elseif (mb_strlen($reason) < 5) {
            flash('danger', 'A reason (at least 5 characters) is required for every manual correction.');
        } elseif ($status === $rec['status']) {
            flash('info', 'Status unchanged.');
        } else {
            $pdo->prepare(
                "UPDATE attendance SET status = ?, verification_method = 'manual',
                    verification_time = COALESCE(verification_time, NOW()) WHERE id = ?"
            )->execute([$status, $rec['id']]);
            audit_log('ATTENDANCE_MODIFIED', "{$rec['matric']} / {$rec['course_code']}: {$rec['status']} → $status. Reason: $reason");
            flash('success', "Attendance for {$rec['matric']} ({$rec['course_code']}) changed to $status.");
        }
    } elseif ($action === 'add') {
        $matric = strtoupper(post_str('matric', 30));
        $exam = find_exam(post_int('exam_id'));
        $status = post_str('status', 20);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE student_id = ? AND role = 'student'");
        $stmt->execute([$matric]);
        $student = $stmt->fetch();

        if (!$student || !$exam || !in_array($status, ATTENDANCE_STATUSES, true)) {
            flash('danger', 'Student ID or examination not found.');
        } elseif (mb_strlen($reason) < 5) {
            flash('danger', 'A reason (at least 5 characters) is required.');
        } else {
            try {
                $pdo->prepare(
                    "INSERT INTO attendance (student_id, exam_id, verification_method, verification_time, status)
                     VALUES (?, ?, 'manual', NOW(), ?)"
                )->execute([$student['id'], $exam['id'], $status]);
                audit_log('ATTENDANCE_MODIFIED', "Added manual record {$student['student_id']} / {$exam['course_code']}: $status. Reason: $reason");
                flash('success', "Attendance recorded for {$student['student_id']}.");
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                flash('warning', 'This student already has an attendance record for that examination — use Correct instead.');
            }
        }
    } elseif ($action === 'mark_absent') {
        $exam = find_exam(post_int('exam_id'));
        if (!$exam || !in_array($exam['status'], ['ongoing', 'completed'], true)) {
            flash('danger', 'Absentees can only be marked for an ongoing or completed examination.');
        } else {
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO attendance (student_id, exam_id, verification_method, verification_time, status)
                 SELECT el.student_id, el.exam_id, 'manual', NULL, 'absent'
                 FROM exam_eligibility el WHERE el.exam_id = ?"
            );
            $stmt->execute([$exam['id']]);
            audit_log('ATTENDANCE_MODIFIED', "Marked {$stmt->rowCount()} unverified student(s) absent for {$exam['course_code']}");
            flash('success', $stmt->rowCount() . ' student(s) without attendance were marked absent.');
        }
    }
    redirect('admin/attendance.php?' . (string) post_str('return', 500));
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$f = [
    'q' => get_str('q'),
    'exam_id' => get_int('exam_id'),
    'date' => get_str('date', 10),
    'department' => get_str('department'),
    'level' => get_str('level', 10),
    'status' => get_str('status', 20),
    'method' => get_str('method', 30),
];
$where = ['1=1'];
$params = [];
if ($f['q'] !== '') {
    $where[] = "(u.student_id LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
    array_push($params, "%{$f['q']}%", "%{$f['q']}%");
}
if ($f['exam_id']) {
    $where[] = 'a.exam_id = ?';
    $params[] = $f['exam_id'];
}
if (valid_date($f['date'])) {
    $where[] = 'e.exam_date = ?';
    $params[] = $f['date'];
}
if ($f['department'] !== '') {
    $where[] = 'u.department = ?';
    $params[] = $f['department'];
}
if (in_array($f['level'], LEVELS, true)) {
    $where[] = 'u.level = ?';
    $params[] = $f['level'];
}
if (in_array($f['status'], ATTENDANCE_STATUSES, true)) {
    $where[] = 'a.status = ?';
    $params[] = $f['status'];
}
if (in_array($f['method'], ['facial_recognition', 'manual'], true)) {
    $where[] = 'a.verification_method = ?';
    $params[] = $f['method'];
}
$whereSql = implode(' AND ', $where);
$from = 'FROM attendance a JOIN users u ON u.id = a.student_id JOIN examinations e ON e.id = a.exam_id';
$select = "SELECT a.*, u.student_id AS matric, u.first_name, u.last_name, u.department, u.level,
                  e.course_code, e.course_title, e.exam_date $from WHERE $whereSql
           ORDER BY e.exam_date DESC, a.verification_time DESC";

// CSV export of the current filter.
if (get_str('export', 5) === 'csv') {
    $stmt = $pdo->prepare($select);
    $stmt->execute($params);
    audit_log('ATTENDANCE_EXPORT', 'Exported attendance CSV');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Name', 'Department', 'Level', 'Course', 'Exam Date', 'Method', 'Verification Time', 'Status', 'Confidence']);
    while ($r = $stmt->fetch()) {
        // Prefix values that spreadsheets could treat as formulas.
        $row = [$r['matric'], full_name($r), $r['department'], $r['level'], $r['course_code'], $r['exam_date'],
            $r['verification_method'], $r['verification_time'], $r['status'], $r['confidence_score']];
        fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, $row));
    }
    fclose($out);
    exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) $from WHERE $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();
[$page, $perPage, $offset, $pages] = paginate($total, 20);
$stmt = $pdo->prepare("$select LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$records = $stmt->fetchAll();

$exams = $pdo->query('SELECT id, course_code, exam_date, status FROM examinations ORDER BY exam_date DESC')->fetchAll();
$returnQuery = http_build_query(array_filter($_GET, fn($k) => $k !== 'export', ARRAY_FILTER_USE_KEY));

$pageTitle = 'Attendance';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Attendance' => null];
$pageScripts = ['admin.js'];
$headerActions = '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add record</button>'
    . '<a class="btn btn-sm btn-outline-secondary" href="?' . e(http_build_query(array_merge($_GET, ['export' => 'csv']))) . '"><i class="bi bi-download me-1"></i>CSV</a>';
require __DIR__ . '/../includes/header.php';
?>

<form class="filter-bar row g-2 align-items-end" method="get">
    <div class="col-md-3"><label class="form-label small mb-1" for="q">Student</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q" value="<?= e($f['q']) ?>" placeholder="Name or student ID"></div>
    <div class="col-md-3"><label class="form-label small mb-1" for="exam_id">Examination</label>
        <select class="form-select form-select-sm" id="exam_id" name="exam_id" data-autosubmit><option value="">All</option>
            <?php foreach ($exams as $ex): ?><option value="<?= (int) $ex['id'] ?>" <?= $f['exam_id'] === (int) $ex['id'] ? 'selected' : '' ?>><?= e($ex['course_code'] . ' · ' . date('d M Y', strtotime($ex['exam_date']))) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1" for="date">Exam date</label>
        <input type="date" class="form-control form-control-sm" id="date" name="date" value="<?= e($f['date']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1" for="department">Department</label>
        <select class="form-select form-select-sm" id="department" name="department" data-autosubmit><option value="">All</option>
            <?php foreach (departments() as $d): ?><option <?= $f['department'] === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-2"><label class="form-label small mb-1" for="level">Level</label>
        <select class="form-select form-select-sm" id="level" name="level" data-autosubmit><option value="">All</option>
            <?php foreach (LEVELS as $l): ?><option <?= $f['level'] === $l ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-2"><label class="form-label small mb-1" for="status">Status</label>
        <select class="form-select form-select-sm" id="status" name="status" data-autosubmit><option value="">All</option>
            <?php foreach (ATTENDANCE_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-2"><label class="form-label small mb-1" for="method">Method</label>
        <select class="form-select form-select-sm" id="method" name="method" data-autosubmit><option value="">All</option>
            <option value="facial_recognition" <?= $f['method'] === 'facial_recognition' ? 'selected' : '' ?>>Facial recognition</option>
            <option value="manual" <?= $f['method'] === 'manual' ? 'selected' : '' ?>>Manual</option></select></div>
    <div class="col-md-8 d-flex gap-2 justify-content-end">
        <a href="<?= e(url('admin/attendance.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
    </div>
</form>

<?php if ($f['exam_id']): $selExam = find_exam($f['exam_id']); ?>
    <?php if ($selExam && in_array($selExam['status'], ['ongoing', 'completed'], true)): ?>
        <form method="post" class="mb-3 text-end" data-confirm="Mark every registered student without an attendance record as ABSENT for <?= e($selExam['course_code']) ?>?" data-confirm-class="btn-warning" data-confirm-label="Mark absent">
            <?= csrf_field() ?><input type="hidden" name="exam_id" value="<?= (int) $selExam['id'] ?>"><input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <button class="btn btn-sm btn-outline-warning" name="action" value="mark_absent"><i class="bi bi-person-x me-1"></i>Mark remaining students absent</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="card-header"><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle table-card">
            <thead><tr><th>Student</th><th>Student ID</th><th>Exam</th><th>Method</th><th>Time</th><th>Status</th><th>Confidence</th><th class="text-end">Action</th></tr></thead>
            <tbody>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= e(full_name($r)) ?><br><span class="small text-muted"><?= e($r['department']) ?> · <?= e($r['level']) ?>L</span></td>
                    <td class="fw-semibold"><?= e($r['matric']) ?></td>
                    <td><?= e($r['course_code']) ?><br><span class="small text-muted"><?= e(fmt_date($r['exam_date'])) ?></span></td>
                    <td><?= status_badge($r['verification_method']) ?></td>
                    <td class="small text-nowrap"><?= e(fmt_datetime($r['verification_time'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td><?= e(fmt_confidence($r['confidence_score'])) ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#correctModal"
                                data-fill='<?= e(json_encode(['#correctId' => $r['id'], '#correctStatus' => $r['status'],
                                    '#correctWho' => $r['matric'] . ' – ' . full_name($r) . ' · ' . $r['course_code']])) ?>'>
                            <i class="bi bi-pencil-square me-1"></i>Correct</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$records): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No attendance records match your filters.</div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?><div class="card-footer bg-white"><?= render_pagination($page, $pages) ?></div><?php endif; ?>
</div>

<!-- Correct attendance modal -->
<div class="modal fade" id="correctModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content needs-validation" method="post" novalidate>
        <div class="modal-header"><h5 class="modal-title">Manual attendance correction</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="correct"><input type="hidden" name="id" id="correctId">
            <input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <p class="fw-semibold mb-3" id="correctWho"></p>
            <div class="mb-3"><label class="form-label" for="correctStatus">New status</label>
                <select class="form-select" name="status" id="correctStatus">
                    <?php foreach (ATTENDANCE_STATUSES as $s): ?><option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label required" for="correctReason">Reason for correction</label>
                <textarea class="form-control" name="reason" id="correctReason" rows="3" minlength="5" maxlength="255" required placeholder="e.g. Complaint #12 verified with invigilator's sign-in sheet"></textarea>
                <div class="invalid-feedback">A reason is required.</div>
                <div class="form-text">The change, your account and this reason are written to the audit log.</div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save correction</button></div>
    </form></div>
</div>

<!-- Add attendance modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content needs-validation" method="post" novalidate>
        <div class="modal-header"><h5 class="modal-title">Add attendance record</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add"><input type="hidden" name="return" value="<?= e($returnQuery) ?>">
            <div class="mb-3"><label class="form-label required" for="addMatric">Student ID</label>
                <input class="form-control text-uppercase" name="matric" id="addMatric" required maxlength="30" placeholder="SWE/2026/001"></div>
            <div class="mb-3"><label class="form-label required" for="addExam">Examination</label>
                <select class="form-select" name="exam_id" id="addExam" required><option value="">Select…</option>
                    <?php foreach ($exams as $ex): ?><option value="<?= (int) $ex['id'] ?>"><?= e($ex['course_code'] . ' · ' . date('d M Y', strtotime($ex['exam_date'])) . ' (' . $ex['status'] . ')') ?></option><?php endforeach; ?></select></div>
            <div class="mb-3"><label class="form-label" for="addStatus">Status</label>
                <select class="form-select" name="status" id="addStatus"><?php foreach (ATTENDANCE_STATUSES as $s): ?><option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label required" for="addReason">Reason</label>
                <textarea class="form-control" name="reason" id="addReason" rows="2" minlength="5" maxlength="255" required></textarea></div>
            <div class="form-text mt-2">For live verification at the hall entrance, use the kiosk's <em>Manual verification</em> option (it enforces eligibility).</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save record</button></div>
    </form></div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
