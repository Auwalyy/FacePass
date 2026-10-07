<?php
/**
 * System settings: kiosk examination selection, recognition threshold,
 * enrollment policy, model retraining and Python/OpenCV diagnostics.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/python.php';

requireAdmin();
$pdo = db();
$diagnostics = null;

if (is_post()) {
    require_csrf();
    $action = post_str('action', 20);

    if ($action === 'kiosk') {
        $examId = post_int('exam_id');
        if ($examId === 0) {
            set_setting('active_exam_id', null);
            audit_log('KIOSK_EXAM_SELECTED', 'Verification kiosk cleared (no examination selected)');
            flash('success', 'Kiosk examination cleared.');
        } elseif ($exam = find_exam($examId)) {
            if ($exam['status'] === 'cancelled') {
                flash('danger', 'A cancelled examination cannot be selected.');
                redirect('admin/settings.php');
            }
            set_setting('active_exam_id', (string) $exam['id']);
            if (!empty($_POST['start_now']) && $exam['status'] !== 'ongoing') {
                $pdo->prepare("UPDATE examinations SET status = 'ongoing' WHERE id = ?")->execute([$exam['id']]);
                audit_log('EXAM_UPDATE', "Changed status of {$exam['course_code']} from {$exam['status']} to ongoing");
            }
            audit_log('KIOSK_EXAM_SELECTED', "Verification kiosk set to {$exam['course_code']} (exam #{$exam['id']})");
            flash('success', "Kiosk examination set to {$exam['course_code']}.");
        }
    } elseif ($action === 'recognition') {
        $threshold = filter_var($_POST['threshold'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($threshold === false || $threshold < 20 || $threshold > 150) {
            flash('danger', 'Threshold must be a number between 20 and 150.');
        } else {
            set_setting('recognition_threshold', (string) round($threshold, 1));
            set_setting('allow_student_self_enrollment', !empty($_POST['self_enroll']) ? '1' : '0');
            audit_log('SETTINGS_UPDATE', "Recognition threshold set to $threshold; student self-enrollment "
                . (!empty($_POST['self_enroll']) ? 'enabled' : 'disabled'));
            flash('success', 'Recognition settings saved.');
        }
    } elseif ($action === 'retrain') {
        $result = retrain_face_model();
        audit_log('MODEL_RETRAIN', 'Manual model retrain: ' . $result['message']);
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
    } elseif ($action === 'diagnose') {
        $diagnostics = run_python('selftest.py', [], 30);
    }
    if ($diagnostics === null) {
        redirect('admin/settings.php');
    }
}

$active = active_exam();
$exams = $pdo->query(
    "SELECT * FROM examinations WHERE status <> 'cancelled' AND exam_date >= (CURDATE() - INTERVAL 1 DAY)
     ORDER BY exam_date, start_time"
)->fetchAll();
$threshold = recognition_threshold();
$selfEnroll = get_setting('allow_student_self_enrollment', '1') === '1';

$modelFile = FR_MODEL_PATH . DIRECTORY_SEPARATOR . 'lbph_model.yml';
$labelsFile = FR_MODEL_PATH . DIRECTORY_SEPARATOR . 'labels.json';
$modelInfo = is_file($labelsFile) ? json_decode((string) file_get_contents($labelsFile), true) : null;
$faceRows = (int) $pdo->query('SELECT COUNT(*) FROM student_faces')->fetchColumn();

$pageTitle = 'Settings';
$breadcrumbs = ['Dashboard' => 'admin/dashboard.php', 'Settings' => null];
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-6">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-broadcast me-2"></i>Verification kiosk examination</div>
            <div class="card-body">
                <p class="small text-muted">The kiosk verifies students <strong>only</strong> against this examination, and only while its status is
                    <em>ongoing</em>. Students cannot choose an examination themselves.</p>
                <p>Current: <?= $active ? '<strong>' . e($active['course_code'] . ' – ' . $active['course_title']) . '</strong> ' . status_badge($active['status']) : '<span class="text-danger">None selected</span>' ?></p>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="kiosk">
                    <div class="col-12">
                        <select class="form-select" name="exam_id" aria-label="Examination">
                            <option value="0">— No examination —</option>
                            <?php foreach ($exams as $ex): ?>
                                <option value="<?= (int) $ex['id'] ?>" <?= $active && (int) $active['id'] === (int) $ex['id'] ? 'selected' : '' ?>>
                                    <?= e("{$ex['course_code']} – {$ex['course_title']} · " . date('D d M', strtotime($ex['exam_date'])) . ' ' . fmt_time($ex['start_time']) . " · {$ex['venue']} ({$ex['status']})") ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 form-check ms-1">
                        <input class="form-check-input" type="checkbox" name="start_now" id="start_now" value="1" checked>
                        <label class="form-check-label small" for="start_now">Also set the examination status to <strong>Ongoing</strong></label>
                    </div>
                    <div class="col-12"><button class="btn btn-primary">Save kiosk examination</button></div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-sliders me-2"></i>Recognition settings</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="recognition">
                    <label class="form-label" for="threshold">LBPH distance threshold: <strong id="thVal"><?= e($threshold) ?></strong></label>
                    <input type="range" class="form-range" min="30" max="120" step="1" id="threshold" name="threshold" value="<?= e($threshold) ?>"
                           oninput="document.getElementById('thVal').textContent=this.value">
                    <div class="d-flex justify-content-between small text-muted mb-2"><span>30 · strict</span><span>default 70</span><span>lenient · 120</span></div>
                    <p class="small text-muted">LBPH returns a <em>distance</em> (lower = more similar). A face is accepted only when distance ≤ threshold.
                        Lowering it reduces false acceptances but rejects more genuine students in poor lighting. The displayed
                        confidence is <code>1 − distance / (2 × threshold)</code>, so a match exactly at the threshold shows 50%.</p>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="self_enroll" name="self_enroll" value="1" <?= $selfEnroll ? 'checked' : '' ?>>
                        <label class="form-check-label" for="self_enroll">Allow students to enroll their own face once (from their profile)</label>
                        <div class="form-text">Supervised enrollment by staff is safer: a student could otherwise enroll someone else's face. Re-enrollment always requires an administrator.</div>
                    </div>
                    <button class="btn btn-primary">Save recognition settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-cpu me-2"></i>Face recognition model</div>
            <div class="card-body">
                <dl class="row small mb-3">
                    <dt class="col-5">Python interpreter</dt><dd class="col-7"><code><?= e(PYTHON_PATH) ?></code></dd>
                    <dt class="col-5">Model file</dt><dd class="col-7"><?= is_file($modelFile) ? '<span class="text-success">Present</span>' : '<span class="text-danger">Not trained yet</span>' ?></dd>
                    <dt class="col-5">Last trained</dt><dd class="col-7"><?= e($modelInfo['trained_at'] ?? '—') ?></dd>
                    <dt class="col-5">Students in model</dt><dd class="col-7"><?= (int) ($modelInfo['student_count'] ?? 0) ?></dd>
                    <dt class="col-5">Samples in model</dt><dd class="col-7"><?= (int) ($modelInfo['sample_count'] ?? 0) ?> (<?= $faceRows ?> in database)</dd>
                </dl>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retrain">
                        <button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Retrain model</button></form>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="diagnose">
                        <button class="btn btn-outline-secondary"><i class="bi bi-heart-pulse me-1"></i>Test Python / OpenCV</button></form>
                </div>
                <?php if ($diagnostics !== null): ?>
                    <div class="mt-3">
                        <?php if (!$diagnostics['ok']): ?>
                            <div class="alert alert-danger small mb-0"><?= e($diagnostics['error']) ?></div>
                        <?php else: $d = $diagnostics['data']; ?>
                            <div class="alert alert-<?= !empty($d['success']) ? 'success' : 'danger' ?> small mb-0">
                                <strong><?= !empty($d['success']) ? 'Python and OpenCV are working.' : 'Problem detected.' ?></strong>
                                <ul class="mb-0 mt-1">
                                    <?php foreach (($d['checks'] ?? []) as $name => $value): ?>
                                        <li><?= e($name) ?>: <code><?= e(is_bool($value) ? ($value ? 'yes' : 'no') : $value) ?></code></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (!empty($d['message'])): ?><div class="mt-1"><?= e($d['message']) ?></div><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>About this MVP</div>
            <div class="card-body small text-muted">
                Facial recognition uses OpenCV Haar cascades for detection and the LBPH (Local Binary Patterns Histograms)
                recognizer for identification. It is suitable for an academic prototype but is <strong>not</strong> spoof-proof
                and is affected by lighting, pose and camera quality. Examination staff must remain present and use
                manual verification whenever recognition fails.
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
