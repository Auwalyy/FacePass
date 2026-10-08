<?php
/**
 * Examination verification kiosk - displayed on a computer at the hall entrance.
 * Operated by a logged-in administrator / examination officer.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$operator = requireAdmin();
$exam = active_exam();
$ready = $exam && $exam['status'] === 'ongoing';

$pageTitle = 'Examination Verification';
$layout = 'kiosk';
$bodyClass = 'kiosk-body';
$skipFlashes = true;
$pageScripts = ['verification.js'];
require __DIR__ . '/../includes/header.php';
?>
<div class="kiosk-header">
    <div class="d-flex align-items-center gap-3">
        <img src="<?= e(url('assets/images/logo.svg')) ?>" alt="" width="40" height="40">
        <div>
            <h1>EXAMINATION VERIFICATION</h1>
            <div class="small text-secondary-emphasis" style="color:#94A3B8"><?= e(INSTITUTION_NAME) ?></div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <span class="fs-5 fw-semibold" id="kioskClock" aria-live="off"></span>
        <span class="small" style="color:#94A3B8"><i class="bi bi-person-badge me-1"></i>Officer: <?= e(full_name($operator)) ?></span>
        <a href="<?= e(url('verification/result.php')) ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-clipboard-check me-1"></i>Log</a>
        <a href="<?= e(url('admin/dashboard.php')) ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
    </div>
</div>

<div class="kiosk-main container-fluid">
    <?php foreach (get_flashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if (!$exam): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>
            No examination has been selected for verification. <a href="<?= e(url('admin/settings.php')) ?>" class="alert-link">Select the examination in Settings</a>.</div>
    <?php elseif (!$ready): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>
            <?= e($exam['course_code']) ?> is <strong><?= e($exam['status']) ?></strong>. Verification is only possible while the examination is <strong>ongoing</strong>.
            <a href="<?= e(url('admin/settings.php')) ?>" class="alert-link">Start it in Settings</a>.</div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <?php if ($exam): ?>
                <div class="kiosk-exam-banner mb-3 d-flex flex-wrap justify-content-between gap-2">
                    <div><span class="code"><?= e($exam['course_code']) ?></span> · <?= e($exam['course_title']) ?>
                        <div class="small"><?= e(fmt_date($exam['exam_date'])) ?> · <?= e(fmt_time($exam['start_time'])) ?> – <?= e(fmt_time($exam['end_time'])) ?> · <?= e($exam['venue']) ?></div></div>
                    <div class="text-end"><?= status_badge($exam['status']) ?>
                        <div class="small mt-1"><span id="presentCount">–</span> verified</div></div>
                </div>
            <?php endif; ?>

            <div class="camera-frame" id="kioskCameraFrame">
                <video id="kioskVideo" autoplay playsinline muted></video>
                <div class="face-guide"></div>
                <div class="camera-overlay" id="kioskOverlay">
                    <i class="bi bi-camera-video fs-1 mb-2"></i>
                    <p class="mb-3" id="kioskOverlayText">Starting camera…</p>
                    <button type="button" class="btn btn-light d-none" id="kioskStartCamera">Start camera</button>
                </div>
            </div>
            <canvas id="kioskCanvas" class="d-none"></canvas>
            <p class="text-center mt-2 mb-3" style="color:#94A3B8">Student: look at the camera, alone, inside the oval.</p>

            <button type="button" class="btn btn-primary btn-verify w-100" id="verifyBtn" <?= $ready ? '' : 'disabled' ?>
                    data-exam-id="<?= (int) ($exam['id'] ?? 0) ?>">
                <i class="bi bi-person-check me-2"></i>VERIFY STUDENT
            </button>
            <div class="d-flex justify-content-between mt-2">
                <span class="small" style="color:#94A3B8">Shortcut: press <kbd>Space</kbd> to verify</span>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#manualModal" <?= $ready ? '' : 'disabled' ?>>
                    <i class="bi bi-person-vcard me-1"></i>Manual verification
                </button>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="result-panel result-waiting" id="resultPanel" aria-live="assertive">
                <div class="result-head" id="resultHead">Status</div>
                <div class="result-body" id="resultBody">
                    <p class="fs-5 mb-0 text-muted">Waiting for verification…</p>
                </div>
            </div>

            <div class="mt-4">
                <h2 class="h6 text-uppercase" style="color:#94A3B8;letter-spacing:.06em">Recent verifications</h2>
                <ul class="list-group list-group-flush kiosk-log small" id="recentLog">
                    <li class="list-group-item">Loading…</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Manual verification modal -->
<div class="modal fade" id="manualModal" tabindex="-1" aria-labelledby="manualModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="manualModalLabel"><i class="bi bi-person-vcard me-2"></i>Manual verification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">Use only when facial recognition fails. Check the student's
                    <strong>ID card</strong> in person. Eligibility rules still apply and the action is recorded in the audit log.</div>
                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control" id="manualSearch" placeholder="Search by student ID or name (min. 2 characters)" autocomplete="off">
                </div>
                <div id="manualResults" class="list-group mb-3"></div>
                <div id="manualSelected" class="d-none">
                    <div class="border rounded p-3 bg-light mb-3" id="manualSelectedInfo"></div>
                    <label class="form-label required" for="manualReason">Reason</label>
                    <select class="form-select mb-2" id="manualReasonPreset">
                        <option value="Face not recognised - ID card checked">Face not recognised – ID card checked</option>
                        <option value="Face not enrolled - ID card checked">Face not enrolled – ID card checked</option>
                        <option value="Camera unavailable - ID card checked">Camera unavailable – ID card checked</option>
                        <option value="">Other (type below)…</option>
                    </select>
                    <input type="text" class="form-control" id="manualReason" maxlength="255" value="Face not recognised - ID card checked">
                </div>
                <div id="manualAlert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="manualVerifyBtn" disabled><i class="bi bi-check2-circle me-1"></i>Verify manually</button>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
