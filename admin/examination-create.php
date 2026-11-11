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
    'venue' => '', 'status' => 'scheduled', 'audience' => 'department',
];
$form['audience'] = $form['audience'] ?? 'department';
$candidatesRaw = '';
$errors = [];
$addStudents = [];   // users rows to register individually

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
        'audience' => post_str('audience', 20),
    ];
    $candidatesRaw = post_str('candidates', 5000);

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
    if (!in_array($form['audience'], EXAM_AUDIENCES, true)) {
        $errors['audience'] = 'Choose who sits this examination.';
    }
    if (!$isEdit && valid_date($form['exam_date']) && $form['exam_date'] < date('Y-m-d') && $form['status'] === 'scheduled') {
        $errors['exam_date'] = 'A scheduled examination cannot be in the past.';
    }

    // Individually added students (matric numbers).
    $unknown = $inactive = [];
    foreach (parse_matric_list($candidatesRaw) as $matric) {
        $s = find_student_by_matric($matric);
        if (!$s) {
            $unknown[] = $matric;
        } elseif ($s['account_status'] !== 'active') {
            $inactive[] = $matric;
        } else {
            $addStudents[] = $s;
        }
    }
    if ($unknown) {
        $errors['candidates'] = 'Unknown student ID(s): ' . implode(', ', $unknown) . '.';
    } elseif ($inactive) {
        $errors['candidates'] = 'Inactive account(s): ' . implode(', ', $inactive) . '. Activate them first.';
    } elseif ($form['audience'] === 'selected' && !$addStudents && !$isEdit) {
        $errors['candidates'] = 'Enter the student ID of at least one student who will sit this examination.';
    }
    if ($addStudents && in_array($form['status'], ['completed', 'cancelled'], true)) {
        $errors['candidates'] = 'Students cannot be added to a ' . $form['status'] . ' examination.';
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
        $values = [$form['course_code'], $form['course_title'], $form['department'], $form['level'], $form['audience'],
            $form['exam_date'], $form['start_time'], $form['end_time'], $form['venue'], $form['status']];

        if ($isEdit) {
            $pdo->prepare(
                'UPDATE examinations SET course_code = ?, course_title = ?, department = ?, level = ?, audience = ?,
                    exam_date = ?, start_time = ?, end_time = ?, venue = ?, status = ? WHERE id = ?'
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
            $message = "Examination {$form['course_code']} updated.";
        } else {
            $pdo->prepare(
                'INSERT INTO examinations (course_code, course_title, department, level, audience, exam_date, start_time, end_time, venue, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($values);
            $id = (int) $pdo->lastInsertId();
            audit_log('EXAM_CREATE', "Created examination {$form['course_code']} - {$form['course_title']} on {$form['exam_date']} at {$form['venue']}"
                . ($form['audience'] === 'selected' ? ' (selected students only)' : ''));
            $message = "Examination {$form['course_code']} created.";
        }

        // Register candidates.
        $exam = find_exam($id);
        $registered = 0;
        if ($form['audience'] === 'department' && !empty($_POST['generate_eligibility'])) {
            $registered += register_department_candidates($exam);
        }
        $problems = [];
        foreach ($addStudents as $s) {
            $res = register_candidate($s, $exam);
            if ($res['ok']) {
                $registered++;
            } else {
                $problems[] = $res['message'];
            }
        }
        flash('success', $message . ($registered ? " $registered student(s) registered - mark paid students below." : ''));
        foreach ($problems as $p) {
            flash('warning', $p);
        }
        redirect('admin/payments.php?exam_id=' . $id);
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
                <div class="col-12">
                    <label class="form-label required d-block">Who sits this examination?</label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="audience" id="aud_department" value="department" <?= $form['audience'] === 'department' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aud_department">All students of the department &amp; level above</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="audience" id="aud_selected" value="selected" <?= $form['audience'] === 'selected' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aud_selected">Only selected students <span class="text-muted small">(e.g. one student, carry-over or resit)</span></label>
                    </div>
                    <?= $err('audience') ?>
                </div>
                <div class="col-12" id="generateBox">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="generate_eligibility" name="generate_eligibility" value="1" <?= $isEdit ? '' : 'checked' ?>>
                        <label class="form-check-label" for="generate_eligibility">
                            Register <?= $isEdit ? 'any not-yet-registered' : 'all' ?> active students of this department and level
                            <span class="text-muted small">(marked <em>not paid</em> until you clear them)</span>
                        </label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="candidates" id="candidatesLabel"><?= $isEdit ? 'Add more students' : 'Add individual students' ?></label>
                    <textarea class="form-control<?= $cls('candidates') ?>" id="candidates" name="candidates" rows="3"
                              placeholder="Student IDs separated by commas or new lines, e.g. SWE/2026/001, CSC/2025/014"><?= e($candidatesRaw) ?></textarea>
                    <div class="form-text">Students from any department or level can be added. A student who already has another examination at the same time is skipped with a warning.
                        <?php if ($isEdit): ?>To remove students, use the <a href="<?= e(url('admin/payments.php?exam_id=' . $id)) ?>">candidates list</a>.<?php endif; ?></div>
                    <?= $err('candidates') ?>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <a href="<?= e(url('admin/examinations.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
                <button class="btn btn-primary"><i class="bi bi-save me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Examination' ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const box = document.getElementById('generateBox');
    const label = document.getElementById('candidatesLabel');
    const sync = () => {
        const selected = document.getElementById('aud_selected').checked;
        box.classList.toggle('d-none', selected);
        label.classList.toggle('required', selected && <?= $isEdit ? 'false' : 'true' ?>);
    };
    document.querySelectorAll('input[name="audience"]').forEach((r) => r.addEventListener('change', sync));
    sync();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
