/**
 * Face enrollment: webcam preview, multi-frame capture and upload to api/enroll.php.
 * Face detection and quality checks happen on the server with Python/OpenCV.
 */
(function () {
    'use strict';

    const widget = document.getElementById('enrollWidget');
    if (!widget) return;

    const video = document.getElementById('enrollVideo');
    const canvas = document.getElementById('enrollCanvas');
    const overlay = document.getElementById('enrollOverlay');
    const startBtn = document.getElementById('enrollStart');
    const captureBtn = document.getElementById('enrollCapture');
    const statusBox = document.getElementById('enrollStatus');
    const thumbs = document.getElementById('enrollThumbs');
    const progressWrap = document.getElementById('enrollProgressWrap');
    const progress = document.getElementById('enrollProgress');
    const frame = document.getElementById('enrollCameraFrame');
    const totalFrames = parseInt(widget.dataset.frames, 10) || 8;
    let stream = null;

    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            EVS.alert(statusBox, 'danger', 'This browser cannot access the camera. Use Chrome/Edge/Firefox via http://localhost or HTTPS.');
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                audio: false,
            });
            video.srcObject = stream;
            await video.play();
            overlay.classList.add('d-none');
            captureBtn.disabled = false;
            statusBox.innerHTML = '';
        } catch (err) {
            const msg = err.name === 'NotAllowedError'
                ? 'Camera permission was denied. Allow camera access in the browser address bar and try again.'
                : err.name === 'NotFoundError' ? 'No camera was found on this computer.'
                : `Could not start the camera (${err.name}).`;
            EVS.alert(statusBox, 'danger', msg);
        }
    }

    /** Grab the current video frame as a JPEG Blob (un-mirrored, full resolution). */
    function grabFrame() {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
    }

    async function captureAndEnroll() {
        if (!stream || !video.videoWidth) return;
        captureBtn.disabled = true;
        thumbs.innerHTML = '';
        statusBox.innerHTML = '';
        progressWrap.classList.remove('d-none');
        frame.classList.add('scanning');

        const form = new FormData();
        form.append('user_id', widget.dataset.userId);

        for (let i = 0; i < totalFrames; i++) {
            const blob = await grabFrame();
            if (blob) {
                form.append('images[]', blob, `frame${i}.jpg`);
                const img = document.createElement('img');
                img.src = URL.createObjectURL(blob);
                img.alt = `Frame ${i + 1}`;
                thumbs.appendChild(img);
            }
            progress.style.width = `${Math.round(((i + 1) / totalFrames) * 60)}%`;
            await sleep(350);
        }

        statusBox.innerHTML = '<div class="alert alert-info"><span class="spinner-border spinner-border-sm me-2"></span>Detecting faces with OpenCV and training the model…</div>';
        progress.style.width = '80%';
        const res = await EVS.api('api/enroll.php', { method: 'POST', body: form });
        progress.style.width = '100%';
        frame.classList.remove('scanning');

        if (res.success) {
            EVS.alert(statusBox, res.model_trained ? 'success' : 'warning', res.message);
            stopCamera();
            if (widget.dataset.redirect) {
                const link = document.createElement('a');
                link.href = widget.dataset.redirect;
                link.className = 'btn btn-outline-primary btn-sm mt-1';
                link.textContent = 'Continue';
                statusBox.appendChild(link);
            }
        } else {
            let msg = res.message || 'Enrollment failed.';
            if (res.rejection_summary) {
                const labels = { no_face: 'no face', multiple_faces: 'more than one face', too_blurry: 'too blurry',
                    poor_lighting: 'poor lighting', unreadable: 'unreadable' };
                msg += ' Rejected frames: ' + Object.entries(res.rejection_summary)
                    .map(([k, v]) => `${labels[k] || k} ×${v}`).join(', ') + '.';
            }
            EVS.alert(statusBox, 'danger', msg);
            captureBtn.disabled = false;
        }
        setTimeout(() => progressWrap.classList.add('d-none'), 800);
    }

    function stopCamera() {
        if (stream) stream.getTracks().forEach((t) => t.stop());
        stream = null;
        captureBtn.disabled = true;
        overlay.classList.remove('d-none');
        overlay.querySelector('p').textContent = 'Camera stopped';
    }

    startBtn.addEventListener('click', startCamera);
    captureBtn.addEventListener('click', captureAndEnroll);
    window.addEventListener('beforeunload', stopCamera);
})();
