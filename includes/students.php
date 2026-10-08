<?php
/**
 * Student form validation + reusable form markup
 * (used by auth/register.php and admin/student-view.php).
 */

defined('APP_INIT') or exit('No direct access.');

const STUDENT_ID_PATTERN = '/^[A-Z0-9][A-Z0-9\/\-]{2,29}$/';

/** Collect the student fields from $_POST. */
function student_input(): array
{
    return [
        'student_id' => strtoupper(post_str('student_id', 30)),
        'first_name' => post_str('first_name', 60),
        'last_name' => post_str('last_name', 60),
        'email' => strtolower(post_str('email', 120)),
        'phone' => post_str('phone', 20),
        'faculty' => post_str('faculty', 100),
        'department' => post_str('department', 100),
        'level' => post_str('level', 10),
        'account_status' => post_str('account_status', 20) ?: 'active',
    ];
}

/**
 * Validate student data. Returns an array of field => error message.
 *
 * @param int|null $excludeId  user id being edited (for the uniqueness checks)
 * @param bool     $passwordRequired  true when creating a student
 */
function validate_student(array $data, ?int $excludeId, bool $passwordRequired): array
{
    $errors = [];

    if (!preg_match(STUDENT_ID_PATTERN, $data['student_id'])) {
        $errors['student_id'] = 'Student ID must be 3-30 characters: letters, numbers, "/" or "-" (e.g. SWE/2021/001).';
    }
    foreach (['first_name' => 'First name', 'last_name' => 'Last name'] as $field => $label) {
        if (!preg_match("/^[\p{L}][\p{L}' .\-]{0,59}$/u", $data[$field])) {
            $errors[$field] = "$label is required (letters, spaces, apostrophes and hyphens only).";
        }
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid e-mail address.';
    }
    if (!preg_match('/^\+?[0-9]{7,15}$/', $data['phone'])) {
        $errors['phone'] = 'Enter a valid phone number (7-15 digits, optional leading +).';
    }
    foreach (['faculty' => 'Faculty', 'department' => 'Department'] as $field => $label) {
        if (mb_strlen($data[$field]) < 2) {
            $errors[$field] = "$label is required.";
        }
    }
    if (!in_array($data['level'], LEVELS, true)) {
        $errors['level'] = 'Select a valid level.';
    }
    if (!in_array($data['account_status'], ACCOUNT_STATUSES, true)) {
        $errors['account_status'] = 'Select a valid account status.';
    }

    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';
    if ($passwordRequired || $password !== '') {
        if (!is_string($password) || strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'Password must be at least 8 characters and contain letters and numbers.';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
    }

    // Uniqueness checks.
    if (!isset($errors['student_id'])) {
        $stmt = db()->prepare('SELECT id FROM users WHERE student_id = ? AND id <> ?');
        $stmt->execute([$data['student_id'], $excludeId ?? 0]);
        if ($stmt->fetch()) {
            $errors['student_id'] = 'This Student ID is already registered.';
        }
    }
    if (!isset($errors['email'])) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$data['email'], $excludeId ?? 0]);
        if ($stmt->fetch()) {
            $errors['email'] = 'This e-mail address is already in use.';
        }
    }

    return $errors;
}

/** Bootstrap "is-invalid" helper. */
function invalid_class(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}

function field_error(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback d-block">' . e($errors[$field]) . '</div>' : '';
}

/**
 * Render the student form fields (without <form> tag).
 *
 * @param array $data    current values
 * @param array $errors  validation errors
 * @param bool  $isEdit  editing an existing student (password optional, status shown)
 */
function render_student_fields(array $data, array $errors, bool $isEdit): void
{
    $faculties = db()->query("SELECT DISTINCT faculty FROM users WHERE faculty IS NOT NULL ORDER BY faculty")->fetchAll(PDO::FETCH_COLUMN);
    $depts = departments();
    ?>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label required" for="student_id">Student ID (Matric No.)</label>
            <input type="text" class="form-control text-uppercase<?= invalid_class($errors, 'student_id') ?>" id="student_id" name="student_id"
                   value="<?= e($data['student_id'] ?? '') ?>" required maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9\/\-]{2,29}" placeholder="SWE/2021/001">
            <?= field_error($errors, 'student_id') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="first_name">First Name</label>
            <input type="text" class="form-control<?= invalid_class($errors, 'first_name') ?>" id="first_name" name="first_name"
                   value="<?= e($data['first_name'] ?? '') ?>" required maxlength="60">
            <?= field_error($errors, 'first_name') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="last_name">Last Name</label>
            <input type="text" class="form-control<?= invalid_class($errors, 'last_name') ?>" id="last_name" name="last_name"
                   value="<?= e($data['last_name'] ?? '') ?>" required maxlength="60">
            <?= field_error($errors, 'last_name') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label required" for="email">E-mail</label>
            <input type="email" class="form-control<?= invalid_class($errors, 'email') ?>" id="email" name="email"
                   value="<?= e($data['email'] ?? '') ?>" required maxlength="120">
            <?= field_error($errors, 'email') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label required" for="phone">Phone</label>
            <input type="tel" class="form-control<?= invalid_class($errors, 'phone') ?>" id="phone" name="phone"
                   value="<?= e($data['phone'] ?? '') ?>" required maxlength="20" pattern="\+?[0-9]{7,15}" placeholder="08012345678">
            <?= field_error($errors, 'phone') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="faculty">Faculty</label>
            <input type="text" class="form-control<?= invalid_class($errors, 'faculty') ?>" id="faculty" name="faculty" list="facultyList"
                   value="<?= e($data['faculty'] ?? '') ?>" required maxlength="100">
            <datalist id="facultyList"><?php foreach ($faculties as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
            <?= field_error($errors, 'faculty') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label required" for="department">Department</label>
            <input type="text" class="form-control<?= invalid_class($errors, 'department') ?>" id="department" name="department" list="deptList"
                   value="<?= e($data['department'] ?? '') ?>" required maxlength="100">
            <datalist id="deptList"><?php foreach ($depts as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
            <?= field_error($errors, 'department') ?>
        </div>
        <div class="col-md-<?= $isEdit ? '2' : '4' ?>">
            <label class="form-label required" for="level">Level</label>
            <select class="form-select<?= invalid_class($errors, 'level') ?>" id="level" name="level" required>
                <option value="">Select…</option>
                <?php foreach (LEVELS as $lvl): ?>
                    <option value="<?= e($lvl) ?>" <?= ($data['level'] ?? '') === $lvl ? 'selected' : '' ?>><?= e($lvl) ?> Level</option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'level') ?>
        </div>
        <?php if ($isEdit): ?>
            <div class="col-md-2">
                <label class="form-label required" for="account_status">Status</label>
                <select class="form-select<?= invalid_class($errors, 'account_status') ?>" id="account_status" name="account_status">
                    <?php foreach (ACCOUNT_STATUSES as $st): ?>
                        <option value="<?= e($st) ?>" <?= ($data['account_status'] ?? '') === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label<?= $isEdit ? '' : ' required' ?>" for="password">Password</label>
            <input type="password" class="form-control<?= invalid_class($errors, 'password') ?>" id="password" name="password"
                   minlength="8" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password">
            <div class="form-text"><?= $isEdit ? 'Leave blank to keep the current password. ' : '' ?>Min. 8 characters with letters and numbers.</div>
            <?= field_error($errors, 'password') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label<?= $isEdit ? '' : ' required' ?>" for="password_confirm">Confirm Password</label>
            <input type="password" class="form-control<?= invalid_class($errors, 'password_confirm') ?>" id="password_confirm" name="password_confirm"
                   data-match="#password" <?= $isEdit ? '' : 'required' ?> autocomplete="new-password">
            <div class="invalid-feedback">Passwords do not match.</div>
            <?= field_error($errors, 'password_confirm') ?>
        </div>
    </div>
    <?php
}

/** Remove a student's face sample files from disk (DB rows are removed separately / by cascade). */
function delete_face_files(int $userId): void
{
    $stmt = db()->prepare('SELECT face_image FROM student_faces WHERE student_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $path = FACES_PATH . DIRECTORY_SEPARATOR . basename($file);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
