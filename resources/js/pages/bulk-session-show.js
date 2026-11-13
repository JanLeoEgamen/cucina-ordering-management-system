/**
 * Bulk session page (staff): the End Session button.
 *
 * Flow:
 *   #end-session  -> the shared confirmation modal, then POST .../end, reload the page
 * (The orders table and its actions join this page with the bulk order lifecycle.)
 */
import { post } from '../ui/order-popup';
import { showToast } from '../ui/toast';

const start = () => {
    const endButton = document.querySelector('#end-session');
    const confirmSubject = document.querySelector('#save-modal-subject');
    const confirmButton = document.querySelector('#save-modal-button');

    if (!endButton || !confirmButton) {
        return;
    }

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    let pending = null;
    let sending = false;

    endButton.addEventListener('click', () => {
        pending = { url: endButton.dataset.endUrl };

        const subject = endButton.dataset.subject ?? '';

        confirmSubject.textContent = subject;
        confirmSubject.classList.toggle('hidden', subject === '');

        emit('open-modal', 'edit-confirmation-modal');
    });

    confirmButton.addEventListener('click', async () => {
        // A second click while the request is in flight would send twice.
        if (!pending || sending) {
            return;
        }

        const { url } = pending;

        pending = null;
        sending = true;

        const label = confirmButton.textContent;

        confirmButton.disabled = true;
        confirmButton.textContent = confirmButton.dataset.loadingLabel ?? label;

        try {
            const { ok, payload } = await post(url);

            emit('close-modal', 'edit-confirmation-modal');

            if (!ok) {
                showToast(payload.message ?? 'The request could not be completed.', 'error');

                return;
            }

            showToast(payload.message);
            // The page (status, End button) is server rendered: load it again.
            setTimeout(() => window.location.reload(), 700);
        } finally {
            sending = false;
            confirmButton.disabled = false;
            confirmButton.textContent = label;
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
