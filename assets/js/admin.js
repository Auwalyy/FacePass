/**
 * Administrator page interactions:
 *   - instant eligibility / payment toggles (admin/payments.php)
 *   - attendance correction modal pre-fill (admin/attendance.php)
 *   - complaint response modal pre-fill (admin/complaints.php)
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Payments & eligibility
    // ------------------------------------------------------------------
    const table = document.getElementById('eligibilityTable');
    if (table) {
        const examId = table.dataset.examId;
        const alertBox = document.getElementById('paymentAlert');

        const save = async (row, field, value) => {
            row.classList.add('opacity-50');
            const res = await EVS.api('api/payments.php', {
                method: 'POST',
                body: { student_id: row.dataset.studentId, exam_id: examId, field, value },
            });
            row.classList.remove('opacity-50');
            if (!res.success) {
                EVS.alert(alertBox, 'danger', res.message || 'Could not save the change.');
                return;
            }
            // Reflect the server's authoritative state.
            row.querySelector('[data-field="school_fees_paid"]').checked = !!res.school_fees_paid;
            row.querySelector('[data-field="eligible"]').checked = !!res.eligible;
            row.querySelector('.js-paid-label').textContent = res.school_fees_paid ? 'Paid' : 'Not paid';
            row.querySelector('.js-reason').value = res.reason || '';
            row.querySelector('.js-result').innerHTML = res.result === 'ELIGIBLE'
                ? '<span class="badge bg-success">ELIGIBLE</span>'
                : '<span class="badge bg-danger">NOT ELIGIBLE</span>';
            EVS.alert(alertBox, 'success', res.message);
        };

        table.addEventListener('change', (event) => {
            const input = event.target.closest('.js-elig');
            if (!input) return;
            save(input.closest('tr'), input.dataset.field, input.checked ? 1 : 0);
        });
        table.addEventListener('click', (event) => {
            const btn = event.target.closest('.js-save-reason');
            if (!btn) return;
            const row = btn.closest('tr');
            save(row, 'reason', row.querySelector('.js-reason').value);
        });
    }

    // ------------------------------------------------------------------
    // Generic "fill a modal from data-* attributes" helper.
    // <button data-bs-toggle="modal" data-bs-target="#m" data-fill='{"#field": "value"}'>
    // ------------------------------------------------------------------
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-fill]');
        if (!trigger) return;
        let values = {};
        try { values = JSON.parse(trigger.dataset.fill); } catch (e) { return; }
        Object.entries(values).forEach(([selector, value]) => {
            const el = document.querySelector(selector);
            if (!el) return;
            if (el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement) {
                el.value = value == null ? '' : value;
            } else {
                el.textContent = value == null ? '' : value;
            }
        });
    });
})();
