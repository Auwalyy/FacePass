/**
 * Verification kiosk:
 *   - webcam preview (navigator.mediaDevices.getUserMedia)
 *   - capture one frame -> POST verification/verify.php (PHP -> Python/OpenCV)
 *   - show the result (verified / not recognised / not eligible / already verified)
 *   - manual verification fallback (search student -> verification/attendance.php)
 *   - recent verifications list (api/attendance.php)
 */
(function () {
    'use strict';

    const video = document.getElementById('kioskVideo');
    if (!video) return;

    const canvas = document.getElementById('kioskCanvas');
    const overlay = document.getElementById('kioskOverlay');
    const overlayText = document.getElementById('kioskOverlayText');
    const startCameraBtn = document.getElementById('kioskStartCamera');
    const frame = document.getElementById('kioskCameraFrame');
    const verifyBtn = document.getElementById('verifyBtn');
    const panel = document.getElementById('resultPanel');
    const head = document.getElementById('resultHead');
    const body = document.getElementById('resultBody');
    const recentLog = document.getElementById('recentLog');
    const presentCount = document.getElementById('presentCount');
    const examId = verifyBtn.dataset.examId;
    const esc = (v) => EVS.escape(v);
    let stream = null;
    let busy = false;
    let resetTimer = null;

    // ------------------------------------------------------------------
    // Clock
    // ------------------------------------------------------------------
    const clock = document.getElementById('kioskClock');
    const tick = () => { clock.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }); };
    tick();
    setInterval(tick, 1000);

    // ------------------------------------------------------------------
    // Camera
    // ------------------------------------------------------------------
    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            overlayText.textContent = 'Camera API unavailable. Open the kiosk via http://localhost or HTTPS in Chrome/Edge/Firefox.';
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' }, audio: false,
            });
            video.srcObject = stream;
            await video.play();
            overlay.classList.add('d-none');
        } catch (err) {
            overlayText.textContent = err.name === 'NotAllowedError'
                ? 'Camera permission denied. Allow camera access, then press Start camera.'
                : `Camera error (${err.name}). Use manual verification if the camera is unavailable.`;
            startCameraBtn.classList.remove('d-none');
        }
    }
    startCameraBtn.addEventListener('click', () => { startCameraBtn.classList.add('d-none'); startCamera(); });

    function grabFrame() {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
    }

    // ------------------------------------------------------------------
    // Result panel rendering
    // ------------------------------------------------------------------
    function setPanel(kind, title, html) {
        panel.className = `result-panel result-${kind}`;
        head.textContent = title;
        body.innerHTML = html;
        clearTimeout(resetTimer);
        if (kind !== 'waiting') {
            // Return to the waiting state so the next student starts fresh.
            resetTimer = setTimeout(() => setPanel('waiting', 'Status', '<p class="fs-5 mb-0 text-muted">Waiting for verification…</p>'), 12000);
        }
    }

    function details(res, extra = []) {
        const rows = [];
        if (res.student) {
            rows.push(['Student', res.student.name], ['Student ID', res.student.id]);
        }
        if (res.exam) {
            rows.push(['Course', res.exam.course_code], ['Exam', res.exam.course_title], ['Date', res.exam.date], ['Time', res.exam.time]);
        }
        rows.push(...extra);
        return '<dl>' + rows.map(([k, v]) => `<dt>${esc(k)}</dt><dd>${v}</dd>`).join('') + '</dl>';
    }

    function render(res) {
        const method = res.method === 'manual' ? 'Manual (ID card checked)' : 'Facial recognition';
        const conf = typeof res.confidence === 'number' ? ` · ${(res.confidence * 100).toFixed(1)}% confidence` : '';

        switch (res.reason) {
            case 'VERIFIED':
                setPanel('success', '✓ VERIFICATION SUCCESSFUL', details(res, [
                    ['Eligibility', '<span class="badge bg-success fs-6">ELIGIBLE</span>'],
                    ['Attendance', '<span class="badge bg-success fs-6">RECORDED</span>'],
                    ['Method', esc(method + conf)],
                    ['Time', esc(res.attendance.time)],
                ]) + '<p class="result-message text-success">You may proceed to the examination hall.</p>');
                break;
            case 'ALREADY_VERIFIED':
                setPanel('warning', '⚠ ALREADY VERIFIED', details(res, res.attendance ? [['Verified at', esc(res.attendance.time)]] : [])
                    + '<p class="result-message text-warning-emphasis">This student has already been marked present for this examination.</p>');
                break;
            case 'NOT_ELIGIBLE':
                setPanel('danger', '✗ NOT ELIGIBLE', details(res, [
                    ['Eligibility', '<span class="badge bg-danger fs-6">NOT ELIGIBLE</span>'],
                    ['Reason', `<span class="text-danger">${esc(res.message)}</span>`],
                ]) + '<p class="result-message">Entry denied. Please see the examinations office.</p>');
                break;
            case 'ACCOUNT_INACTIVE':
                setPanel('danger', '✗ ACCOUNT INACTIVE', details(res) + `<p class="result-message">${esc(res.message)}</p>`);
                break;
            case 'FACE_NOT_RECOGNIZED':
            case 'NO_FACE_DETECTED':
            case 'MULTIPLE_FACES':
                setPanel('danger', '✗ STUDENT NOT RECOGNIZED',
                    `<p class="result-message mt-0">${esc(res.message || '')}</p>
                     <p class="mb-2">Please contact examination staff.</p>
                     <p class="small text-muted mb-3">Staff: retry once with better lighting, or use manual verification after checking the ID card.</p>
                     <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#manualModal">
                        <i class="bi bi-person-vcard me-1"></i>Manual verification</button>`);
                break;
            case 'EXAM_NOT_ONGOING':
            case 'EXAM_NOT_SELECTED':
                setPanel('warning', '⚠ VERIFICATION CLOSED', `<p class="result-message mt-0">${esc(res.message)}</p>`);
                break;
            default:
                setPanel('danger', '✗ VERIFICATION ERROR', `<p class="result-message mt-0">${esc(res.message || 'Unexpected error.')}</p>
                    <p class="mb-0">Use manual verification if the problem continues.</p>`);
        }
    }

    // ------------------------------------------------------------------
    // Facial verification
    // ------------------------------------------------------------------
    async function verify() {
        if (busy || verifyBtn.disabled) return;
        if (!stream || !video.videoWidth) {
            setPanel('danger', '✗ CAMERA NOT READY', '<p class="result-message mt-0">The camera is not running. Start it or use manual verification.</p>');
            return;
        }
        busy = true;
        verifyBtn.disabled = true;
        frame.classList.add('scanning');
        setPanel('waiting', 'Verifying…', '<p class="fs-5 mb-0"><span class="spinner-border spinner-border-sm me-2"></span>Analysing face, please hold still…</p>');

        const blob = await grabFrame();
        const form = new FormData();
        form.append('exam_id', examId);
        form.append('image', blob, 'frame.jpg');
        const res = await EVS.api('verification/verify.php', { method: 'POST', body: form });

        frame.classList.remove('scanning');
        render(res);
        busy = false;
        verifyBtn.disabled = false;
        loadRecent();
    }
    verifyBtn.addEventListener('click', verify);
    document.addEventListener('keydown', (e) => {
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)
            || document.querySelector('.modal.show');
        if (e.code === 'Space' && !typing) {
            e.preventDefault();
            verify();
        }
    });

    // ------------------------------------------------------------------
    // Recent verifications
    // ------------------------------------------------------------------
    async function loadRecent() {
        if (!Number(examId)) { recentLog.innerHTML = '<li class="list-group-item">No examination selected.</li>'; return; }
        const res = await EVS.api(`api/attendance.php?exam_id=${encodeURIComponent(examId)}&limit=6`);
        if (!res.success) { recentLog.innerHTML = '<li class="list-group-item">Unable to load.</li>'; return; }
        if (presentCount) presentCount.textContent = `${res.summary.present} / ${res.summary.registered}`;
        recentLog.innerHTML = res.records.length ? res.records.map((r) => {
            const icon = r.status === 'present' ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger';
            return `<li class="list-group-item d-flex justify-content-between">
                <span><i class="bi ${icon} me-2"></i>${esc(r.student_id)} · ${esc(r.name)}</span>
                <span>${esc(r.method === 'manual' ? 'manual' : 'face')} · ${esc(r.time)}</span></li>`;
        }).join('') : '<li class="list-group-item">No verifications yet.</li>';
    }

    // ------------------------------------------------------------------
    // Manual verification fallback
    // ------------------------------------------------------------------
    const search = document.getElementById('manualSearch');
    const results = document.getElementById('manualResults');
    const selectedBox = document.getElementById('manualSelected');
    const selectedInfo = document.getElementById('manualSelectedInfo');
    const reasonPreset = document.getElementById('manualReasonPreset');
    const reasonInput = document.getElementById('manualReason');
    const manualBtn = document.getElementById('manualVerifyBtn');
    const manualAlert = document.getElementById('manualAlert');
    let selected = null;
    let searchTimer = null;

    reasonPreset.addEventListener('change', () => { reasonInput.value = reasonPreset.value; if (!reasonPreset.value) reasonInput.focus(); });

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = search.value.trim();
        if (q.length < 2) { results.innerHTML = ''; return; }
        searchTimer = setTimeout(async () => {
            const res = await EVS.api(`api/students.php?q=${encodeURIComponent(q)}&exam_id=${encodeURIComponent(examId)}`);
            if (!res.success) { EVS.alert(manualAlert, 'danger', res.message || 'Search failed.'); return; }
            results.innerHTML = res.students.length ? res.students.map((s, i) => `
                <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-index="${i}">
                    <span><strong>${esc(s.student_id)}</strong> · ${esc(s.name)}<br>
                    <span class="small text-muted">${esc(s.department)} · ${esc(s.level)}L · Face ID: ${s.face_registered ? 'yes' : 'no'}</span></span>
                    <span>${s.eligible ? '<span class="badge bg-success">Eligible</span>' : '<span class="badge bg-danger">Not eligible</span>'}
                    ${s.attendance === 'present' ? '<span class="badge bg-warning text-dark">Present</span>' : ''}</span>
                </button>`).join('') : '<div class="list-group-item text-muted">No students found.</div>';
            results.querySelectorAll('[data-index]').forEach((btn) => btn.addEventListener('click', () => {
                selected = res.students[Number(btn.dataset.index)];
                selectedInfo.innerHTML = `<strong>${esc(selected.name)}</strong> · ${esc(selected.student_id)}<br>
                    <span class="small">${esc(selected.department)} · ${esc(selected.level)} Level · ${esc(selected.email)}</span>`;
                selectedBox.classList.remove('d-none');
                manualBtn.disabled = false;
                manualAlert.innerHTML = '';
            }));
        }, 250);
    });

    manualBtn.addEventListener('click', async () => {
        if (!selected) return;
        manualBtn.disabled = true;
        const res = await EVS.api('verification/attendance.php', {
            method: 'POST', body: { student_id: selected.id, exam_id: examId, reason: reasonInput.value.trim() },
        });
        manualBtn.disabled = false;
        if (res.reason === 'REASON_REQUIRED' || res.reason === 'STUDENT_NOT_FOUND' || res.reason === 'CSRF_FAILED') {
            EVS.alert(manualAlert, 'danger', res.message);
            return;
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('manualModal')).hide();
        render(res);
        loadRecent();
        selected = null;
        selectedBox.classList.add('d-none');
        results.innerHTML = '';
        search.value = '';
        manualBtn.disabled = true;
    });

    // ------------------------------------------------------------------
    startCamera();
    loadRecent();
    setInterval(loadRecent, 15000);
    window.addEventListener('beforeunload', () => stream && stream.getTracks().forEach((t) => t.stop()));
})();
