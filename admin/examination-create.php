<?php
/**
 * Create or edit an examination (?id=N to edit).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

requireAdmin();
$pdo = db();

$id = is_post() ? post_int('id') : get_int('id');
$existing = $id ? find_exam($id) : null;
if ($id && !$existing) {
    flash('danger', 'Examination not found.');
    redirect('admin/examinations.php');
}
$isEdit = $existing !== null;

$form = $existing ?? [
    'course_code' => '', 'course_title' => '', 'department' => '', 'level' => '',
    'exam_date' => date('Y-m-d', strtotime('+1 day')), 'start_time' => '09:00', 'end_time' => '12:00',
    'venue' => '', 'status' => 'scheduled',
];
$errors = [];

if (is_post()) {
    require_csrf();
    $form = [
        'course_code' => strtoupper(str_replace(' ', '', post_str('course_code', 20))),
        'course_title' => post_str('course_title', 150),
        'department' => post_str('department', 100),
        'level' => post_str('level', 10),
        'exam_date' => post_str('exam_date', 10),
        'start_time' => post_str('start_time', 8),
        'end_time' => post_str('end_time', 8),
        'venue' => post_str('venue', 120),
        'status' => post_str('status', 20),
    ];

    if (!preg_match('/^[A-Z]{2,5}\d{3,4}[A-Z]?$/', $form['course_code'])) {
        $errors['course_code'] = 'Course code must look like SWE401.';
    }
    if (mb_strlen($form['course_title']) < 3) {
        $errors['course_title'] = 'Course title is required.';
    }
    if (mb_strlen($form['department']) < 2) {
        $errors['department'] = 'Department is required.';
    }
    if (!in_array($form['level'], LEVELS, true)) {
        $errors['level'] = 'Select a level.';
    }
    if (!valid_date($form['exam_date'])) {
        $errors['exam_date'] = 'Enter a valid date.';
    }
    if (!valid_time($form['start_time'])) {
        $errors['start_time'] = 'Enter a valid start time.';
    }
    if (!valid_time($form['end_time'])) {
        $errors['end_time'] = 'Enter a valid end time.';
    } elseif (!isset($errors['start_time']) && strtotime($form['end_time']) <= strtotime($form['start_time'])) {
        $errors['end_time'] = 'End time must be after the start time.';
    }
    if (mb_strlen($form['venue']) < 2) {
        $errors['venue'] = 'Venue is required.';
    }
    if (!in_array($form['status'], EXAM_STATUSES, true)) {
        $errors['status'] = 'Select a status.';
    }

    // Venue clash: same venue, same date, overlapping time (ignoring cancelled exams).
    if (!$errors) {
        $stmt = $pdo->prepare(
            "SELECT course_code FROM examinations
             WHERE venue = ? AND exam_date = ? AND status <> 'cancelled' AND id <> ?
               AND start_time < ? AND end_time > ? LIMIT 1"
        );
        $stmt->execute([$form['venue'], $form['exam_date'], $id, $form['end_time'], $form['start_time']]);
        if ($clash = $stmt->fetchColumn()) {
            $errors['venue'] = "Venue clash: $clash is already scheduled in this venue at that time.";
        }
    }

    if (!$errors) {
        $values = [$form['course_code'], $form['course_title'], $form['department'], $form['level'], $form['exam_date'],
            $form['start_time'], $form['end_time'], $form['venue'], $form['status']];

        if ($isEdit) {
            $pdo->prepare(
                'UPDATE examinations SET course_code = ?, course_title = ?, department = ?, level = ?, exam_date = ?,
                    start_time = ?, end_time = ?, venue = ?, status = ? WHERE id = ?'
            )->execute([...$values, $id]);
            $changes = [];
            foreach ($form as $k => $v) {
                $old = in_array($k, ['start_time', 'end_time'], true) ? substr((string) $existing[$k], 0, 5) : (string) $existing[$k];
                $new = in_array($k, ['start_time', 'end_time'], true) ? substr($v, 0, 5) : $v;
                if ($old !== $new) {
                    $changes[] = "$k: '$old' → '$new'";
                }
            }
            audit_log('EXAM_UPDATE', "Updated examination {$form['course_code']}" . ($changes ? ': ' . implode('; ', $changes) : ' (no changes)'));
            flash('success', "Examination {$form['course_code']} updated.");
        } else {
            $pdo->prepare(
                'INSERT INTO examinations (course_code, course_title, department, level, exam_date, start_time, end_time, venue, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($values);
            $id = (int) $pdo->lastInsertId();
            audit_log('EXAM_CREATE', "Created examination {$form['course_code']} - {$form['course_title']} on {$form['exam_date']} at {$form['venue']}");

            $generated = 0;
            if (!empty($_POST['generate_eligibility'])) {
                // One eligibility row per active student in that department & level (not yet cleared).
                $stmt = $pdo->prepare(
                    "INSERT IGNORE INTO exam_eligibility (student_id, exam_id, school_fees_paid, eligible, reason)
                     SELECT id, ?, 0, 0, 'Awaiting payment confirmation.' FROM users
                     WHERE role = 'student' AND account_status = 'active' AND department = ? AND level = ?"
                );
                $stmt->execute([$id, $form['department'], $form['level']]);
                $generated = $stmt->rowCount();
            }
            flash('success', "Examination {$form['course_code']} created" . ($generated ? " with $generated eligibility record(s). Mark paid students on Payments & Eligibility." : '.'));
        }
        redirect('admin/examinations.php');
    }
}

$err = fn(string $f) => isset($errors[$f]) ? '<div class="invalid-feedback d-block">' . e($errors[$f]) . '</div>' : '';
$cls = fn(string $f) => isset($errors[$f]) ? ' is-invalid' : '';

$pageTitle = $isEdit ? 'Edit Examination' : 'Create Examination';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Examinations' => 'admin/examinations.php', ($isEdit ? 'Edit' : 'Create') => null];
require __DIR__ . '/../includes/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger">Please correct the highlighted fields.</div><?php endif; ?>
<div class="card">
    <div class="card-header"><i class="bi bi-calendar-plus me-2"></i>Examination details</div>
    <div class="card-body">
        <form method="post" class="needs-validation" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label required" for="course_code">Course Code</label>
                    <input class="form-control text-uppercase<?= $cls('course_code') ?>" id="course_code" name="course_code" value="<?= e($form['course_code']) ?>" required maxlength="20" placeholder="SWE401">
                    <?= $err('course_code') ?>
                </div>
                <div class="col-md-9">
                    <label class="form-label required" for="course_title">Course Title</label>
                    <input class="form-control<?= $cls('course_title') ?>" id="course_title" name="course_title" value="<?= e($form['course_title']) ?>" required maxlength="150">
                    <?= $err('course_title') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label required" for="department">Department</label>
                    <input class="form-control<?= $cls('department') ?>" id="department" name="department" list="deptList" value="<?= e($form['department']) ?>" required maxlength="100">
                    <datalist id="deptList"><?php foreach (departments() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
                    <?= $err('department') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label required" for="level">Level</label>
                    <select class="form-select<?= $cls('level') ?>" id="level" name="level" required>
                        <option value="">Select…</option>
                        <?php foreach (LEVELS as $l): ?><option value="<?= e($l) ?>" <?= $form['level'] === $l ? 'selected' : '' ?>><?= e($l) ?> Level</option><?php endforeach; ?>
                    </select>
                    <?= $err('level') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label required" for="status">Status</label>
                    <select class="form-select<?= $cls('status') ?>" id="status" name="status" required>
                        <?php foreach (EXAM_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
                    </select>
                    <?= $err('status') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label required" for="exam_date">Examination Date</label>
                    <input type="date" class="form-control<?= $cls('exam_date') ?>" id="exam_date" name="exam_date" value="<?= e($form['exam_date']) ?>" required>
                    <?= $err('exam_date') ?>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label required" for="start_time">Start Time</label>
                    <input type="time" class="form-control<?= $cls('start_time') ?>" id="start_time" name="start_time" value="<?= e(substr($form['start_time'], 0, 5)) ?>" required>
                    <?= $err('start_time') ?>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label required" for="end_time">End Time</label>
                    <input type="time" class="form-control<?= $cls('end_time') ?>" id="end_time" name="end_time" value="<?= e(substr($form['end_time'], 0, 5)) ?>" required>
                    <?= $err('end_time') ?>
                </div>
                <div class="col-12">
                    <label class="form-label required" for="venue">Venue</label>
                    <input class="form-control<?= $cls('venue') ?>" id="venue" name="venue" value="<?= e($form['venue']) ?>" required maxlength="120" placeholder="Main Examination Hall A">
                    <?= $err('venue') ?>
                </div>
                <?php if (!$isEdit): ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="generate_eligibility" name="generate_eligibility" value="1" checked>
                            <label class="form-check-label" for="generate_eligibility">
                                Create eligibility records for all active students in this department and level
                                <span class="text-muted small">(marked <em>not paid</em> until you clear them)</span>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="<?= e(url('admin/examinations.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                <button class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Examination' ?></button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
