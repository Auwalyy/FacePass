/**
 * Shared front-end helpers (vanilla JavaScript, no frameworks).
 *
 *  - EVS.api()        fetch wrapper that sends the CSRF token and parses JSON
 *  - EVS.url()        build an app URL from a relative path
 *  - data-confirm     confirmation modal before submitting a form / following a link
 *  - .needs-validation Bootstrap client-side form validation
 */
(function () {
    'use strict';

    const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content || '';

    const EVS = {
        csrf: meta('csrf-token'),
        base: meta('base-url'),

        url(path) {
            return `${this.base}/${String(path).replace(/^\/+/, '')}`;
        },

        /** POST/GET JSON helper. `body` may be FormData or a plain object. */
        async api(path, { method = 'GET', body = null } = {}) {
            const options = {
                method,
                headers: { 'X-CSRF-Token': this.csrf, 'Accept': 'application/json' },
                credentials: 'same-origin',
            };
            if (body instanceof FormData) {
                options.body = body;
            } else if (body) {
                const fd = new FormData();
                Object.entries(body).forEach(([k, v]) => fd.append(k, v));
                options.body = fd;
            }
            let response;
            try {
                response = await fetch(this.url(path), options);
            } catch (err) {
                return { success: false, reason: 'NETWORK_ERROR', message: 'Cannot reach the server.' };
            }
            try {
                return await response.json();
            } catch (err) {
                return { success: false, reason: 'BAD_RESPONSE', message: `Unexpected server response (HTTP ${response.status}).` };
            }
        },

        /** Escape text before inserting it into HTML. */
        escape(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        },

        /** Show a Bootstrap alert inside a container element. */
        alert(container, type, message) {
            if (!container) return;
            container.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${this.escape(message)}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
        },

        /** Promise-based confirmation using the shared modal in footer.php. */
        confirm(message, okLabel = 'Confirm', okClass = 'btn-danger') {
            const modalEl = document.getElementById('confirmModal');
            if (!modalEl || !window.bootstrap) return Promise.resolve(window.confirm(message));
            return new Promise((resolve) => {
                document.getElementById('confirmModalBody').textContent = message;
                const ok = document.getElementById('confirmModalOk');
                ok.textContent = okLabel;
                ok.className = `btn ${okClass}`;
                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
                let confirmed = false;
                const onOk = () => { confirmed = true; modal.hide(); };
                ok.addEventListener('click', onOk, { once: true });
                modalEl.addEventListener('hidden.bs.modal', () => {
                    ok.removeEventListener('click', onOk);
                    resolve(confirmed);
                }, { once: true });
                modal.show();
            });
        },
    };
    window.EVS = EVS;

    // ------------------------------------------------------------------
    // Confirmation for forms: <form data-confirm="Delete this student?">
    // ------------------------------------------------------------------
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') return;
        event.preventDefault();
        const submitter = event.submitter;
        const ok = await EVS.confirm(form.dataset.confirm, form.dataset.confirmLabel || 'Confirm',
            form.dataset.confirmClass || 'btn-danger');
        if (ok) {
            form.dataset.confirmed = '1';
            // Preserve the clicked button's name/value.
            if (submitter && submitter.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.name;
                hidden.value = submitter.value;
                form.appendChild(hidden);
            }
            form.submit();
        }
    });

    // ------------------------------------------------------------------
    // Bootstrap validation styles + password match check
    // ------------------------------------------------------------------
    document.querySelectorAll('form.needs-validation').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const confirm = form.querySelector('[data-match]');
            if (confirm) {
                const target = form.querySelector(confirm.dataset.match);
                confirm.setCustomValidity(target && target.value !== confirm.value ? 'Passwords do not match' : '');
            }
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopImmediatePropagation();
            }
            form.classList.add('was-validated');
        }, true);
    });

    // ------------------------------------------------------------------
    // Show / hide password toggles: <button data-toggle-password="#id">
    // ------------------------------------------------------------------
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.querySelector(btn.dataset.togglePassword);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    });

    // ------------------------------------------------------------------
    // Auto-submit filter selects: <select data-autosubmit>
    // ------------------------------------------------------------------
    document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
        select.addEventListener('change', () => select.form && select.form.submit());
    });

    // Auto-hide success alerts after a few seconds.
    setTimeout(() => {
        document.querySelectorAll('.alert-success.alert-dismissible').forEach((el) => {
            if (window.bootstrap) bootstrap.Alert.getOrCreateInstance(el).close();
        });
    }, 6000);
})();
