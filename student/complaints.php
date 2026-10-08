<?php
/**
 * Student attendance complaints: submit + track status/response.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/student-data.php';

$student = requireStudent();
$pdo = db();
$exams = array_reverse(array_values(array_filter(student_exams($student), fn($e) => $e['status'] !== 'cancelled')));
$examIds = array_map(fn($e) => (int) $e['id'], $exams);

$errors = [];
$form = ['exam_id' => get_int('exam_id'), 'subject' => '', 'description' => ''];

if (is_post()) {
    require_csrf();
    $form = [
        'exam_id' => post_int('exam_id'),
        'subject' => post_str('subject', 150),
        'description' => post_str('description', 2000),
    ];
    if ($form['exam_id'] !== 0 && !in_array($form['exam_id'], $examIds, true)) {
        $errors['exam_id'] = 'Select one of your examinations.';
    }
    if (mb_strlen($form['subject']) < 5) {
        $errors['subject'] = 'Subject must be at least 5 characters.';
    }
    if (mb_strlen($form['description']) < 15) {
        $errors['description'] = 'Please describe the problem (at least 15 characters).';
    }
    if (!$errors && $form['exam_id']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE student_id = ? AND exam_id = ? AND status IN ('pending','reviewed')");
        $stmt->execute([$student['id'], $form['exam_id']]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors['exam_id'] = 'You already have an open complaint for this examination.';
        }
    }
    if (!$errors) {
        $pdo->prepare('INSERT INTO complaints (student_id, exam_id, subject, description) VALUES (?, ?, ?, ?)')
            ->execute([$student['id'], $form['exam_id'] ?: null, $form['subject'], $form['description']]);
        audit_log('COMPLAINT_SUBMIT', "{$student['student_id']} submitted complaint #" . $pdo->lastInsertId() . ": {$form['subject']}");
        flash('success', 'Your complaint has been submitted. You will see the response here.');
        redirect('student/complaints.php');
    }
}

$stmt = $pdo->prepare(
    'SELECT c.*, e.course_code, e.course_title FROM complaints c LEFT JOIN examinations e ON e.id = c.exam_id
     WHERE c.student_id = ? ORDER BY c.created_at DESC'
);
$stmt->execute([$student['id']]);
$complaints = $stmt->fetchAll();

$pageTitle = 'Complaints';
$breadcrumbs = ['Dashboard' => 'student/dashboard.php', 'Complaints' => null];
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-flag me-2"></i>Submit an attendance complaint</div>
            <div class="card-body">
                <form method="post" class="needs-validation" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="exam_id">Examination</label>
                        <select class="form-select<?= isset($errors['exam_id']) ? ' is-invalid' : '' ?>" id="exam_id" name="exam_id">
                            <option value="0">General (not about a specific exam)</option>
                            <?php foreach ($exams as $ex): ?>
                                <option value="<?= (int) $ex['id'] ?>" <?= $form['exam_id'] === (int) $ex['id'] ? 'selected' : '' ?>>
                                    <?= e($ex['course_code'] . ' – ' . $ex['course_title'] . ' (' . date('d M Y', strtotime($ex['exam_date'])) . ', ' . $ex['att_state'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['exam_id'])): ?><div class="invalid-feedback"><?= e($errors['exam_id']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required" for="subject">Subject</label>
                        <input class="form-control<?= isset($errors['subject']) ? ' is-invalid' : '' ?>" id="subject" name="subject" value="<?= e($form['subject']) ?>"
                               required minlength="5" maxlength="150" placeholder="e.g. Attendance not showing for SWE401">
                        <div class="invalid-feedback"><?= e($errors['subject'] ?? 'Subject must be at least 5 characters.') ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required" for="description">Description</label>
                        <textarea class="form-control<?= isset($errors['description']) ? ' is-invalid' : '' ?>" id="description" name="description" rows="5"
                                  required minlength="15" maxlength="2000" placeholder="I attended the SWE401 examination but my attendance is not showing. I sat in…"><?= e($form['description']) ?></textarea>
                        <div class="invalid-feedback"><?= e($errors['description'] ?? 'Please describe the problem (at least 15 characters).') ?></div>
                    </div>
                    <button class="btn btn-primary w-100"><i class="bi bi-send me-1"></i>Submit complaint</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">My complaints</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($complaints as $c): ?>
                    <li class="list-group-item py-3">
                        <div class="d-flex justify-content-between gap-2">
                            <div><strong><?= e($c['subject']) ?></strong>
                                <div class="small text-muted"><?= $c['course_code'] ? e($c['course_code'] . ' – ' . $c['course_title']) . ' · ' : '' ?>Submitted <?= e(fmt_datetime($c['created_at'])) ?></div></div>
                            <div><?= status_badge($c['status']) ?></div>
                        </div>
                        <p class="small mt-2 mb-2" style="white-space:pre-wrap"><?= e($c['description']) ?></p>
                        <?php if ($c['admin_response']): ?>
                            <div class="complaint-response small"><strong>Examinations office:</strong> <?= e($c['admin_response']) ?>
                                <div class="text-muted">Updated <?= e(fmt_datetime($c['updated_at'])) ?></div></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$complaints): ?><li class="list-group-item"><div class="empty-state"><i class="bi bi-chat-square"></i>You have not submitted any complaints.</div></li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
