<?php
/**
 * View / edit a single student: profile, face registration, eligibility,
 * examination & attendance history.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/students.php';
require_once __DIR__ . '/../includes/python.php';

requireAdmin();
$pdo = db();

$id = is_post() ? post_int('id') : get_int('id');
$student = find_student($id);
if (!$student) {
    flash('danger', 'Student not found.');
    redirect('admin/students.php');
}

$errors = [];
$form = $student;
$tab = get_str('tab', 20) ?: 'overview';

if (is_post()) {
    require_csrf();
    $action = post_str('action', 20);

    if ($action === 'update') {
        $tab = 'edit';
        $form = student_input();
        $errors = validate_student($form, $id, false);
        if (!$errors) {
            $pdo->prepare(
                'UPDATE users SET student_id = ?, first_name = ?, last_name = ?, email = ?, phone = ?,
                    faculty = ?, department = ?, level = ?, account_status = ? WHERE id = ?'
            )->execute([
                $form['student_id'], $form['first_name'], $form['last_name'], $form['email'], $form['phone'],
                $form['faculty'], $form['department'], $form['level'], $form['account_status'], $id,
            ]);
            if (($_POST['password'] ?? '') !== '') {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $id]);
            }
            $changed = [];
            foreach (['student_id', 'first_name', 'last_name', 'email', 'phone', 'faculty', 'department', 'level', 'account_status'] as $f) {
                if ((string) $student[$f] !== (string) $form[$f]) {
                    $changed[] = "$f: '{$student[$f]}' → '{$form[$f]}'";
                }
            }
            if (($_POST['password'] ?? '') !== '') {
                $changed[] = 'password reset';
            }
            audit_log('STUDENT_UPDATE', "Updated student {$form['student_id']}" . ($changed ? ': ' . implode('; ', $changed) : ' (no changes)'));

            // Model labels carry the student ID / active status, so retrain when those change.
            if ((int) $student['face_registered'] && ($student['student_id'] !== $form['student_id'] || $student['account_status'] !== $form['account_status'])) {
                retrain_face_model();
            }
            flash('success', 'Student details updated.');
            redirect('admin/student-view.php?id=' . $id);
        }
    } elseif ($action === 'reset_face') {
        delete_face_files($id);
        $pdo->prepare('DELETE FROM student_faces WHERE student_id = ?')->execute([$id]);
        $pdo->prepare('UPDATE users SET face_registered = 0 WHERE id = ?')->execute([$id]);
        audit_log('FACE_RESET', "Removed face enrollment for {$student['student_id']}");
        $train = retrain_face_model();
        flash($train['ok'] ? 'success' : 'warning', 'Face enrollment removed.' . ($train['ok'] ? '' : ' Model retrain failed: ' . $train['message']));
        redirect('admin/student-view.php?id=' . $id . '&tab=face');
    }
}

// ---------------------------------------------------------------------
// Data for the tabs
// ---------------------------------------------------------------------
$faces = $pdo->prepare('SELECT * FROM student_faces WHERE student_id = ? ORDER BY id');
$faces->execute([$id]);
$faces = $faces->fetchAll();

$stmt = $pdo->prepare(
    "SELECT e.*, el.school_fees_paid, el.eligible, el.reason, el.verified_at,
            a.status AS att_status, a.verification_method, a.verification_time, a.confidence_score
     FROM examinations e
     LEFT JOIN exam_eligibility el ON el.exam_id = e.id AND el.student_id = ?
     LEFT JOIN attendance a ON a.exam_id = e.id AND a.student_id = ?
     WHERE (e.department = ? AND e.level = ?) OR el.id IS NOT NULL OR a.id IS NOT NULL
     ORDER BY e.exam_date DESC, e.start_time DESC"
);
$stmt->execute([$id, $id, $student['department'], $student['level']]);
$exams = $stmt->fetchAll();

$summary = ['present' => 0, 'absent' => 0, 'rejected' => 0, 'eligible' => 0];
foreach ($exams as $ex) {
    if ($ex['att_status']) {
        $summary[$ex['att_status']]++;
    }
    if ($ex['school_fees_paid'] && $ex['eligible']) {
        $summary['eligible']++;
    }
}

$pageTitle = full_name($student);
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Students' => 'admin/students.php', $student['student_id'] => null];
require __DIR__ . '/../includes/header.php';
?>

<div class="card student-header-card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="avatar-lg"><?= e(strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1))) ?></div>
        <div class="flex-grow-1">
            <h2 class="h5 mb-1"><?= e(full_name($student)) ?> <?= status_badge($student['account_status']) ?></h2>
            <div class="text-muted small">
                <i class="bi bi-person-vcard me-1"></i><?= e($student['student_id']) ?> ·
                <?= e($student['department']) ?> · <?= e($student['level']) ?> Level · <?= e($student['faculty']) ?>
            </div>
            <div class="text-muted small"><i class="bi bi-envelope me-1"></i><?= e($student['email']) ?> · <i class="bi bi-telephone me-1"></i><?= e($student['phone']) ?></div>
        </div>
        <div class="text-center px-3">
            <div class="mb-1"><?= yes_no_badge($student['face_registered'], 'Face registered', 'No face data') ?></div>
            <a href="<?= e(url('admin/enroll-face.php?id=' . $id)) ?>" class="btn btn-sm btn-primary"><i class="bi bi-camera me-1"></i>
                <?= $student['face_registered'] ? 'Re-enroll face' : 'Enroll face' ?></a>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([['Present', $summary['present'], 'success'], ['Absent', $summary['absent'], 'danger'],
                    ['Rejected', $summary['rejected'], 'warning'], ['Eligible exams', $summary['eligible'], 'primary']] as [$l, $v, $c]): ?>
        <div class="col-6 col-md-3">
            <div class="card stat-card"><div class="stat-icon bg-<?= $c ?>-soft"><i class="bi bi-bar-chart"></i></div>
                <div><div class="stat-value"><?= (int) $v ?></div><div class="stat-label"><?= e($l) ?></div></div></div>
        </div>
    <?php endforeach; ?>
</div>

<ul class="nav nav-tabs nav-tabs-status mb-0" role="tablist">
    <?php foreach (['overview' => 'Exams & Attendance', 'edit' => 'Edit Details', 'face' => 'Face Data'] as $key => $label): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $tab === $key ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button" role="tab"><?= e($label) ?></button>
        </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content card border-top-0 rounded-top-0">
    <!-- Exams, eligibility & attendance -->
    <div class="tab-pane fade<?= $tab === 'overview' ? ' show active' : '' ?>" id="tab-overview" role="tabpanel">
        <div class="table-responsive">
            <table class="table align-middle table-card">
                <thead><tr><th>Course</th><th>Date</th><th>Exam Status</th><th>Fees Paid</th><th>Eligible</th><th>Attendance</th><th>Method</th><th>Verified</th></tr></thead>
                <tbody>
                <?php foreach ($exams as $ex): ?>
                    <tr>
                        <td><strong><?= e($ex['course_code']) ?></strong><br><span class="small text-muted"><?= e($ex['course_title']) ?></span></td>
                        <td class="small"><?= e(fmt_date($ex['exam_date'])) ?></td>
                        <td><?= status_badge($ex['status']) ?></td>
                        <td><?= $ex['school_fees_paid'] === null ? '<span class="badge bg-light text-dark">No record</span>' : yes_no_badge($ex['school_fees_paid'], 'Paid', 'Not paid') ?></td>
                        <td><?= $ex['eligible'] === null ? '—' : yes_no_badge($ex['eligible']) ?>
                            <?php if ($ex['reason'] && !$ex['eligible']): ?><div class="small text-muted"><?= e($ex['reason']) ?></div><?php endif; ?></td>
                        <td><?= $ex['att_status'] ? status_badge($ex['att_status']) : '<span class="text-muted small">—</span>' ?></td>
                        <td><?= $ex['verification_method'] ? status_badge($ex['verification_method']) : '' ?></td>
                        <td class="small"><?= e(fmt_datetime($ex['verification_time'])) ?>
                            <?php if ($ex['confidence_score'] !== null): ?><div class="text-muted"><?= e(fmt_confidence($ex['confidence_score'])) ?></div><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$exams): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-calendar-x"></i>No examinations for this student's department/level yet.</div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body pt-0 small text-muted">Manage payment status on <a href="<?= e(url('admin/payments.php')) ?>">Payments &amp; Eligibility</a>; correct attendance on <a href="<?= e(url('admin/attendance.php?q=' . urlencode($student['student_id']))) ?>">Attendance</a>.</div>
    </div>

    <!-- Edit -->
    <div class="tab-pane fade<?= $tab === 'edit' ? ' show active' : '' ?>" id="tab-edit" role="tabpanel">
        <div class="card-body">
            <?php if ($errors): ?><div class="alert alert-danger">Please correct the highlighted fields.</div><?php endif; ?>
            <form method="post" class="needs-validation" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="action" value="update">
                <?php render_student_fields($form, $errors, true); ?>
                <div class="text-end mt-4"><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button></div>
            </form>
        </div>
    </div>

    <!-- Face data -->
    <div class="tab-pane fade<?= $tab === 'face' ? ' show active' : '' ?>" id="tab-face" role="tabpanel">
        <div class="card-body">
            <?php if ($faces): ?>
                <p class="text-muted small mb-2"><?= count($faces) ?> face sample(s) captured on <?= e(fmt_datetime($faces[0]['created_at'])) ?>. Images are served only to administrators.</p>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($faces as $f): ?>
                        <img class="face-sample" src="<?= e(url('admin/face-image.php?id=' . $f['id'])) ?>" alt="Face sample" loading="lazy">
                    <?php endforeach; ?>
                </div>
                <form method="post" data-confirm="Delete all face samples for this student? They will not be recognised until re-enrolled." data-confirm-label="Delete face data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="action" value="reset_face">
                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete face data</button>
                </form>
            <?php else: ?>
                <div class="empty-state"><i class="bi bi-person-bounding-box"></i>No face samples enrolled.
                    <div class="mt-2"><a class="btn btn-primary btn-sm" href="<?= e(url('admin/enroll-face.php?id=' . $id)) ?>">Enroll face now</a></div></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
