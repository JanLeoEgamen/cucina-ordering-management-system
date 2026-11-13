/**
 * System configuration page.
 *
 * One form for all settings: Save asks for confirmation (the page's shared
 * #save-modal), then sends the form as it is and reports with a toast. A
 * validation error stays on the page, with the values the user typed.
 */
import { showToast } from '../ui/toast';

const start = () => {
    const form = document.querySelector('#settings-form');
    const errors = document.querySelector('#settings-errors');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmSubject = document.querySelector('#save-modal-subject');
    const confirmButton = document.querySelector('#save-modal-button');

    // A viewer without `edit settings` gets the values read only and no Save.
    if (!form || !confirmButton) {
        return;
    }

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const showErrors = (list) => {
        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        errors.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        errors.classList.toggle('hidden', items.length === 0);
    };

    const messages = (payload) => {
        if (payload && payload.errors) {
            const list = Object.values(payload.errors).flat().filter(Boolean);

            if (list.length) {
                return list;
            }
        }

        return payload && typeof payload.message === 'string' ? [payload.message] : [];
    };

    let saving = false;

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        showErrors([]);

        // The browser's own message for an empty time, before asking anything.
        if (!form.reportValidity()) {
            return;
        }

        confirmTitle.textContent = form.dataset.confirmTitle ?? confirmTitle.textContent;
        confirmMessage.textContent = form.dataset.confirmMessage ?? confirmMessage.textContent;
        confirmButton.textContent = form.dataset.confirmLabel ?? confirmButton.textContent;
        confirmSubject.textContent = '';
        confirmSubject.classList.add('hidden');

        emit('open-modal', 'edit-confirmation-modal');
    });

    confirmButton.addEventListener('click', async () => {
        // A second click while the request is in flight would save twice.
        if (saving) {
            return;
        }

        saving = true;

        const label = confirmButton.textContent;

        confirmButton.disabled = true;
        confirmButton.textContent = confirmButton.dataset.loadingLabel ?? label;

        try {
            const response = await fetch(form.action, {
                method: 'POST', // the form carries _method=PUT
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: new FormData(form),
            });

            const payload = await response.json().catch(() => ({}));

            emit('close-modal', 'edit-confirmation-modal');

            if (!response.ok) {
                showErrors(messages(payload));

                return;
            }

            showToast(payload.message);
        } catch (error) {
            emit('close-modal', 'edit-confirmation-modal');
            showErrors(['The configuration could not be saved.']);
        } finally {
            saving = false;
            confirmButton.disabled = false;
            confirmButton.textContent = label;
        }
    });
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
