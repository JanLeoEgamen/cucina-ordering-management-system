/**
 * My Orders page: fix a payment staff rejected.
 *
 * The page is server rendered; only an order with a rejected payment that is
 * still pending carries the "Fix your payment" panel, and its URLs are in the
 * markup (`form[data-reupload-form]` action, `[data-cash-yes]` data-url), so
 * nothing is hard coded here. Two ways out:
 *
 *   - send a new proof (and reference number): a multipart POST;
 *   - pay with cash on pickup: a button that asks "are you sure?" inline, then POSTs.
 *
 * Either one succeeds with a toast and a reload, so the card shows the payment
 * as pending again. Failures stay in the panel (and as a toast) and the
 * customer can try again.
 */
import { showToast } from '../ui/toast';

const start = () => {
    const root = document.querySelector('#my-orders-root');

    if (!root || !root.querySelector('[data-fix-payment]')) {
        return;
    }

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    // Laravel's 422 carries `errors`; the app's own errors carry `message`.
    const messages = (payload) => {
        if (payload && payload.errors) {
            const list = Object.values(payload.errors).flat().filter(Boolean);

            if (list.length) {
                return list;
            }
        }

        return payload && typeof payload.message === 'string' ? [payload.message] : [];
    };

    const showErrors = (panel, list) => {
        const box = panel.querySelector('[data-fix-errors]');
        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        if (box) {
            box.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
            box.classList.toggle('hidden', items.length === 0);
        }

        items.slice(0, 2).forEach((message) => showToast(message, 'error'));
    };

    // One POST for both actions. Returns { ok, payload }.
    const post = async (url, body) => {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                body,
            });

            return { ok: response.ok, payload: await response.json().catch(() => ({})) };
        } catch (error) {
            console.warn('The request could not be sent.', error);

            return { ok: false, payload: {} };
        }
    };

    // Run an action with the button showing what is happening; on success toast
    // and reload, on failure put the label back and say why.
    const run = async (panel, button, url, body) => {
        const label = button.textContent;

        button.disabled = true;
        button.textContent = button.dataset.loadingLabel ?? label;
        showErrors(panel, []);

        const { ok, payload } = await post(url, body);

        if (ok) {
            showToast(payload.message ?? '', 'success');
            // A moment to read the toast, then the card shows the new state.
            setTimeout(() => window.location.reload(), 900);

            return;
        }

        button.disabled = false;
        button.textContent = label;
        showErrors(panel, messages(payload).length ? messages(payload) : [root.dataset.failed ?? '']);
    };

    // Send a new proof.
    root.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-reupload-form]');

        if (!form) {
            return;
        }

        event.preventDefault();

        const panel = form.closest('[data-fix-payment]');
        const file = form.querySelector('input[type="file"]');

        if (!file?.files?.length) {
            showErrors(panel, [root.dataset.pickProof ?? '']);

            return;
        }

        run(panel, form.querySelector('button[type="submit"]'), form.action, new FormData(form));
    });

    // Pay with cash: ask first, inline, then send.
    root.addEventListener('click', (event) => {
        const holder = event.target.closest('[data-cash-switch]');

        if (!holder) {
            return;
        }

        const ask = holder.querySelector('[data-cash-ask]');
        const confirm = holder.querySelector('[data-cash-confirm]');

        if (event.target.closest('[data-cash-ask]')) {
            ask.classList.add('hidden');
            confirm.classList.remove('hidden');

            return;
        }

        if (event.target.closest('[data-cash-no]')) {
            confirm.classList.add('hidden');
            ask.classList.remove('hidden');

            return;
        }

        const yes = event.target.closest('[data-cash-yes]');

        if (yes) {
            run(holder.closest('[data-fix-payment]'), yes, yes.dataset.url, new FormData());
        }
    });
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
