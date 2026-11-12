/**
 * The payment popup: one payment's details, with Verify, Reject and Undo.
 *
 * The markup is the <x-ui.payment-popup> component, included once per page.
 * A page script calls initPaymentPopup() once and then open(payment) with a
 * payment in the shape the payments feed (and GET /payments/{id}) returns:
 *
 *   order_number, customer_name, amount, method_name, reference_number,
 *   created_at, status (badge html), order_status, rejection_reason,
 *   verified_by_name, verified_at, proof_url, items[],
 *   verify_url / reject_url / undo_url (null unless the user may take that action)
 *
 * Verify and Undo ask for a plain confirmation; Reject asks for the reason first.
 * After a decision the popup closes and `onChange` runs, so the page can
 * refresh whatever it shows about the payment.
 */
import { showToast } from './toast';

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[character]));

const money = (value) => '$' + Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// 2026-11-10 14:05:00 -> Nov 10, 2026, 2:05 PM
const prettyDateTime = (value) => {
    const parsed = new Date(String(value ?? '').replace(' ', 'T'));

    return Number.isNaN(parsed.getTime())
        ? '—'
        : parsed.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

const byId = (id) => document.getElementById(id);

const fillText = (id, value) => {
    const element = byId(id);

    if (element) {
        element.textContent = value === null || value === undefined || value === '' ? '—' : value;
    }
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

// Block a button and show its loading label for as long as a request runs.
const busy = (button) => {
    const label = button.textContent;

    button.disabled = true;
    button.textContent = button.dataset.loadingLabel ?? label;

    return () => {
        button.disabled = false;
        button.textContent = label;
    };
};

const post = async (url, fields = {}) => {
    const body = new FormData();

    body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');

    for (const [name, value] of Object.entries(fields)) {
        body.append(name, value);
    }

    const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body,
    });

    return { ok: response.ok, payload: await response.json().catch(() => ({})) };
};

export function initPaymentPopup({ onChange = () => {} } = {}) {
    // The modal component has no id of its own (its name is only an event key),
    // so the popup is recognised by an element inside it.
    if (!byId('view-payment-items')) {
        console.warn('The payment popup is not on this page; nothing was wired.');

        return null;
    }

    // The three decisions; the buttons are only rendered for whoever holds the
    // permission, and each is shown only when the payment carries its URL.
    const decisionButtons = Array.from(document.querySelectorAll('[data-decision]'));

    const confirmTitle = byId('pay-confirm-title');
    const confirmMessage = byId('pay-confirm-message');
    const confirmSubject = byId('pay-confirm-subject');
    const confirmButton = byId('pay-confirm-button');

    const rejectReason = byId('reject-reason');
    const rejectErrors = byId('reject-errors');
    const rejectSubject = byId('reject-subject');
    const rejectConfirm = byId('reject-confirm');

    let pendingDecision = null;
    let pendingReject = null;
    let sending = false;

    const showRejectErrors = (list) => {
        if (!rejectErrors) {
            return;
        }

        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        rejectErrors.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        rejectErrors.classList.toggle('hidden', items.length === 0);
    };

    const open = (payment) => {
        if (!payment) {
            return;
        }

        fillText('view-payment-order', payment.order_number);
        fillText('view-payment-customer', payment.customer_name);
        fillText('view-payment-amount', money(payment.amount));
        fillText('view-payment-method', payment.method_name);
        fillText('view-payment-reference', payment.reference_number);
        fillText('view-payment-date', prettyDateTime(payment.created_at));
        fillText('view-payment-verified', payment.verified_by_name
            ? payment.verified_by_name + (payment.verified_at ? ' · ' + prettyDateTime(payment.verified_at) : '')
            : null);

        byId('view-payment-status').innerHTML = payment.status ?? '';

        // Why it was rejected, for a rejected payment only.
        fillText('view-payment-reason', payment.rejection_reason);
        byId('view-payment-reason-wrapper')?.classList.toggle('hidden', !payment.rejection_reason);

        // The order's own badge is not in the data (only its raw state), so the
        // state is printed as text.
        fillText('view-payment-order-status', payment.order_status ? payment.order_status.charAt(0).toUpperCase() + payment.order_status.slice(1) : null);

        // The proof, large, and clickable to enlarge.
        const proof = byId('view-payment-proof');

        if (proof) {
            proof.src = payment.proof_url ?? '';
            proof.dataset.zoomCaption = payment.order_number ?? '';
        }

        byId('view-payment-proof-wrapper')?.classList.toggle('hidden', !payment.proof_url);

        const lines = payment.items ?? [];

        byId('view-payment-items').innerHTML = lines.length === 0
            ? '<tr><td colspan="3" class="px-3 py-3 text-sm text-gray-500 dark:text-gray-400">—</td></tr>'
            : lines.map((item) => '<tr>'
                + '<td class="px-3 py-2 text-sm text-gray-800 dark:text-gray-200">' + escapeHtml(item.dish_name) + '</td>'
                + '<td class="px-3 py-2 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">' + Number(item.quantity) + '</td>'
                + '<td class="px-3 py-2 text-right text-sm tabular-nums text-gray-900 dark:text-white">' + money(item.sub_total) + '</td>'
                + '</tr>').join('');

        // Verify / Reject / Undo: shown only when this payment carries the URL.
        for (const button of decisionButtons) {
            const url = payment[button.dataset.decision + '_url'] ?? '';

            button.classList.toggle('hidden', url === '');
            button.dataset.url = url;
            button.dataset.subject = payment.order_number ?? '';
        }

        emit('open-modal', 'view-payment-modal');
    };

    // Reject has its own popup (it needs the reason); the others share the plain
    // confirmation: the button sets its copy, the confirmation's button sends.
    decisionButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (!button.dataset.url) {
                return;
            }

            if (button.dataset.decision === 'reject') {
                pendingReject = button.dataset.url;

                rejectReason.value = '';
                rejectSubject.textContent = button.dataset.subject ?? '';
                showRejectErrors([]);

                emit('open-modal', 'reject-payment-modal');
                setTimeout(() => rejectReason.focus(), 150);

                return;
            }

            pendingDecision = button.dataset.url;

            confirmTitle.textContent = button.dataset.title ?? '';
            confirmMessage.textContent = button.dataset.message ?? '';
            confirmButton.textContent = button.dataset.label ?? '';
            confirmSubject.textContent = button.dataset.subject ?? '';
            confirmSubject.classList.toggle('hidden', !button.dataset.subject);

            emit('open-modal', 'payment-decision-modal');
        });
    });

    confirmButton?.addEventListener('click', async () => {
        // A second click while the request is in flight would decide twice.
        if (!pendingDecision || sending) {
            return;
        }

        const url = pendingDecision;

        pendingDecision = null;
        sending = true;

        const done = busy(confirmButton);

        try {
            const { ok, payload } = await post(url);

            emit('close-modal', 'payment-decision-modal');

            if (!ok) {
                showToast(messages(payload).join(' ') || 'The payment could not be updated.', 'error');

                return;
            }

            emit('close-modal', 'view-payment-modal');
            showToast(payload.message);
            onChange();
        } finally {
            sending = false;
            done();
        }
    });

    // Reject: the reason is required, and the server's answer is shown in the popup.
    rejectConfirm?.addEventListener('click', async () => {
        if (!pendingReject || sending) {
            return;
        }

        const reason = rejectReason.value.trim();

        if (reason === '') {
            showRejectErrors([rejectReason.dataset.required ?? 'Give a reason for rejecting this payment.']);
            rejectReason.focus();

            return;
        }

        sending = true;

        const done = busy(rejectConfirm);

        try {
            const { ok, payload } = await post(pendingReject, { reason });

            if (!ok) {
                // A validation error stays in the popup with what was typed.
                showRejectErrors(messages(payload).length ? messages(payload) : ['The payment could not be rejected.']);

                return;
            }

            pendingReject = null;

            emit('close-modal', 'reject-payment-modal');
            emit('close-modal', 'view-payment-modal');
            showToast(payload.message);
            onChange();
        } finally {
            sending = false;
            done();
        }
    });

    return { open };
}
