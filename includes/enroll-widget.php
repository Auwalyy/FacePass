<?php
/**
 * Reusable webcam enrollment widget (markup only - behaviour in assets/js/enroll.js).
 *
 * Expects: $enrollUserId (int) - student being enrolled
 *          $enrollRedirect (string) - relative URL to open after success
 */

defined('APP_INIT') or exit('No direct access.');
?>
<div class="row g-4" id="enrollWidget"
     data-user-id="<?= (int) $enrollUserId ?>"
     data-frames="<?= (int) ENROLL_FRAMES ?>"
     data-redirect="<?= e(url($enrollRedirect)) ?>">
    <div class="col-lg-7">
        <div class="camera-frame" id="enrollCameraFrame">
            <video id="enrollVideo" autoplay playsinline muted></video>
            <div class="face-guide"></div>
            <div class="camera-overlay" id="enrollOverlay">
                <i class="bi bi-camera-video fs-1 mb-2"></i>
                <p class="mb-3">Camera is off</p>
                <button type="button" class="btn btn-light" id="enrollStart"><i class="bi bi-camera-video me-1"></i>Start camera</button>
            </div>
        </div>
        <canvas id="enrollCanvas" class="d-none"></canvas>
    </div>
    <div class="col-lg-5">
        <h2 class="h6">Instructions</h2>
        <ol class="small text-muted ps-3">
            <li>Only <strong>one person</strong> should be in front of the camera.</li>
            <li>Use good, even lighting on the face; avoid strong light behind the student.</li>
            <li>Remove caps or sunglasses. Keep the face inside the oval.</li>
            <li>Press <strong>Capture &amp; Enroll</strong> and <strong>hold still</strong>, looking at the camera, while <?= (int) ENROLL_FRAMES ?> frames are taken (about 3 seconds). Movement causes blurry frames.</li>
        </ol>
        <p class="small text-muted">Each frame is checked by OpenCV: it must contain exactly one sharp, well-lit face.
            At least <?= (int) ENROLL_MIN_FACES ?> good frames are required, otherwise nothing is stored.</p>
        <button type="button" class="btn btn-primary w-100 mb-3" id="enrollCapture" disabled>
            <i class="bi bi-camera me-1"></i>Capture &amp; Enroll
        </button>
        <div class="progress mb-2 d-none" id="enrollProgressWrap" role="progressbar" aria-label="Capture progress">
            <div class="progress-bar" id="enrollProgress" style="width:0%"></div>
        </div>
        <div class="enroll-thumbs d-flex flex-wrap gap-1 mb-3" id="enrollThumbs"></div>
        <div id="enrollStatus" aria-live="polite"></div>
    </div>
</div>
