/**
 * The order popup: one order's details with its actions, shared by the order session
 * page (order-session-show.js) and the Orders page (orders.js).
 *
 *   const popup = initOrderPopup({ onChange: (kind) => ... });
 *   popup.open(order);           // `order` is a feed row (see Controller::orderRow)
 *
 * Its markup is <x-ui.order-popup />. It fills the details from the row the page's table
 * already holds, so opening costs no request. Complete Order, Cancel Order, Verify Payment
 * and Reject Payment are all confirmed INSIDE the popup (the footer swaps for a confirm bar;
 * Reject's bar also holds the reason box, which is required). After a successful action the popup closes
 * and `onChange(kind)` runs, kind being 'complete' | 'cancel' | 'verify' | 'reject' | 'ready': the
 * page decides what to refresh (cancelling gives stock back, so the session page reloads).
 */
import { showToast } from './toast';
import { proofLink } from './proof-link';

export const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[character]));

export const money = (value) => '₱' + Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// 2026-11-10 14:05:00 -> Nov 10, 2:05 PM
export const prettyDateTime = (value) => {
    const parsed = new Date(String(value ?? '').replace(' ', 'T'));

    return Number.isNaN(parsed.getTime())
        ? String(value ?? '—')
        : parsed.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

export const post = async (url, fields = {}) => {
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

export function initOrderPopup({ onChange = () => {} } = {}) {
    const byId = (id) => document.querySelector('#' + id);
    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const copyElement = byId('order-popup-copy');

    if (!copyElement) {
        console.warn('The order popup is missing from this page; nothing was wired.');

        return { open: () => {} };
    }

    const copy = copyElement.dataset;

    const fillText = (id, value) => {
        const element = byId(id);

        if (element) {
            element.textContent = value === null || value === undefined || value === '' ? '—' : value;
        }
    };

    // The decision buttons in the footer; the row carries the URL of each, only while
    // it applies and only for someone who may (see Controller::orderRow).
    const verifyButton = byId('order-details-verify');
    const rejectButton = byId('order-details-reject');
    const completeButton = byId('order-details-complete');
    const cancelButton = byId('order-details-cancel');
    const readyButton = byId('order-details-ready');
    const hint = byId('order-details-hint');

    // One request at a time across the inline confirmation.
    let sending = false;

    /* ---- inline confirmation ---------------------------------------------- */

    // Complete / Cancel Order / Verify Payment / Reject Payment are decided inside the popup,
    // so asking in a second modal on top of it would hide the order behind a dialog. The
    // footer is swapped for a confirm bar instead. Reject is the one that also needs a
    // reason, which the customer reads on their orders: the bar then holds the reason box.
    const bar = byId('order-details-bar');
    const confirmBar = byId('order-details-confirm');
    const confirmText = byId('order-details-confirm-text');
    const confirmBack = byId('order-details-confirm-back');
    const confirmGo = byId('order-details-confirm-go');
    const reasonBox = byId('order-details-confirm-reason');
    const rejectReason = byId('order-reject-reason');
    const rejectErrors = byId('order-reject-errors');

    const goStyles = {
        complete: 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300 dark:focus:ring-indigo-800',
        ready: 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300 dark:focus:ring-indigo-800',
        verify: 'bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-300 dark:focus:ring-emerald-800',
        cancel: 'bg-red-600 hover:bg-red-700 focus:ring-red-300 dark:focus:ring-red-900',
        reject: 'bg-red-600 hover:bg-red-700 focus:ring-red-300 dark:focus:ring-red-900',
    };

    let inline = null; // { url, kind: 'verify' | 'complete' | 'cancel' | 'reject' | 'ready' }

    // One line under the reason box (empty text hides it).
    const showRejectError = (message) => {
        if (!rejectErrors) {
            return;
        }

        rejectErrors.textContent = message ?? '';
        rejectErrors.classList.toggle('hidden', !message);
    };

    // Back to the normal footer (and the payment hint the confirmation had replaced).
    const leaveConfirm = () => {
        inline = null;

        bar?.classList.remove('hidden');
        confirmBar?.classList.add('hidden');
        confirmBar?.classList.remove('flex');
        reasonBox?.classList.add('hidden');
        hint?.classList.toggle('hidden', !hint.textContent);
    };

    const askInline = (button, kind) => {
        if (!button.dataset.url || !confirmBar) {
            return;
        }

        inline = { url: button.dataset.url, kind };

        confirmText.textContent = kind === 'complete' && button.dataset.cash
            ? button.dataset.messageCash
            : button.dataset.message;
        confirmGo.textContent = button.dataset.label;
        confirmGo.className = 'rounded-full px-5 py-2.5 text-sm font-medium text-white transition focus:outline-none focus:ring-4 ' + goStyles[kind];

        // Only Reject asks for a reason; it starts empty every time.
        const needsReason = kind === 'reject' && !!rejectReason;

        reasonBox?.classList.toggle('hidden', !needsReason);

        if (needsReason) {
            rejectReason.value = '';
            showRejectError('');
        }

        // The confirmation already says what happens to the payment.
        hint?.classList.add('hidden');
        bar.classList.add('hidden');
        confirmBar.classList.remove('hidden');
        confirmBar.classList.add('flex');

        // The reason box takes the focus (the go button would be pressed with nothing typed).
        (needsReason ? rejectReason : confirmGo).focus();
    };

    verifyButton?.addEventListener('click', () => askInline(verifyButton, 'verify'));
    rejectButton?.addEventListener('click', () => askInline(rejectButton, 'reject'));
    completeButton?.addEventListener('click', () => askInline(completeButton, 'complete'));
    cancelButton?.addEventListener('click', () => askInline(cancelButton, 'cancel'));
    readyButton?.addEventListener('click', () => askInline(readyButton, 'ready'));
    confirmBack?.addEventListener('click', leaveConfirm);

    confirmGo?.addEventListener('click', async () => {
        // A second click while the request is in flight would send twice.
        if (!inline || sending) {
            return;
        }

        const { url, kind } = inline;
        const fields = {};

        if (kind === 'reject') {
            const reason = (rejectReason?.value ?? '').trim();

            if (reason === '') {
                showRejectError(rejectReason?.dataset.required ?? 'Give a reason for rejecting this payment.');
                rejectReason?.focus();

                return;
            }

            fields.reason = reason;
        }

        const label = confirmGo.textContent;

        sending = true;
        confirmGo.disabled = true;
        confirmGo.textContent = confirmGo.dataset.loadingLabel ?? label;

        try {
            const { ok, payload } = await post(url, fields);

            if (!ok && kind === 'reject') {
                // A refusal (a validation error, or "already decided") stays in the bar with what was typed.
                const list = payload && payload.errors ? Object.values(payload.errors).flat() : [payload.message];

                showRejectError(list.filter(Boolean).join(' ') || 'The payment could not be rejected.');

                return;
            }

            if (!ok) {
                // Back to the normal footer, so the order can be looked at again.
                showToast(payload.message ?? 'The request could not be completed.', 'error');
                leaveConfirm();

                return;
            }

            inline = null;
            showToast(payload.message);
            emit('close-modal', 'order-details-modal');
            onChange(kind);
        } finally {
            sending = false;
            confirmGo.disabled = false;
            confirmGo.textContent = label;
        }
    });

    /* ---- open ------------------------------------------------------------- */

    const open = (order) => {
        if (!order) {
            return;
        }

        fillText('order-details-number', order.order_number);
        fillText('order-details-customer', order.customer_name);
        fillText('order-details-placed', prettyDateTime(order.order_date));
        fillText('order-details-method', order.method_name);
        fillText('order-details-reference', order.reference_number);
        fillText('order-details-total', money(order.total_amount));
        fillText('order-details-verified', order.verified_by_name
            ? order.verified_by_name + (order.verified_at ? ' · ' + prettyDateTime(order.verified_at) : '')
            : null);

        // A row from the staff feed always carries these keys (null when empty); a customer's own
        // order (order-cards.js) has neither, so the rows that only staff need are hidden.
        for (const [id, key] of [['order-details-customer', 'customer_name'], ['order-details-verified', 'verified_by_name']]) {
            byId(id)?.closest('[data-popup-field]')?.classList.toggle('hidden', order[key] === undefined);
        }

        byId('order-details-status').innerHTML = order.status_badge ?? '';
        byId('order-details-payment').innerHTML = order.payment_badge ?? '';

        // Which session it was placed in, with a link to it (the Orders page lists orders of every session).
        const sessionLink = byId('order-details-session');

        if (sessionLink) {
            sessionLink.textContent = order.session_label ?? '—';
            sessionLink.href = order.session_url ?? '#';
            sessionLink.classList.toggle('pointer-events-none', !order.session_url);
        }

        byId('order-details-proof').innerHTML = order.proof_url
            ? proofLink(order.proof_url, copy.proofLabel, copy.proofCaption)
            : '<span class="text-gray-500 dark:text-gray-400">—</span>';

        const lines = order.items ?? [];

        byId('order-details-items').innerHTML = lines.length === 0
            ? '<tr><td colspan="4" class="px-3 py-3 text-sm text-gray-500 dark:text-gray-400">—</td></tr>'
            : lines.map((item) => '<tr>'
                + '<td class="px-3 py-2 text-sm text-gray-800 dark:text-gray-200">' + escapeHtml(item.dish_name) + '</td>'
                + '<td class="px-3 py-2 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">' + Number(item.quantity) + '</td>'
                + '<td class="px-3 py-2 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">' + money(item.unit_price) + '</td>'
                + '<td class="px-3 py-2 text-right text-sm tabular-nums text-gray-900 dark:text-white">' + money(item.sub_total) + '</td>'
                + '</tr>').join('');

        for (const [button, url] of [[verifyButton, order.verify_url], [rejectButton, order.reject_url], [completeButton, order.complete_url], [cancelButton, order.cancel_url], [readyButton, order.ready_url]]) {
            if (button) {
                button.classList.toggle('hidden', !url);
                button.dataset.url = url ?? '';
                button.dataset.subject = order.order_number ?? '';
                button.dataset.cash = order.is_cash ? '1' : '';
            }
        }

        // Say what happens to the payment, while the order is still pending.
        if (hint) {
            let text = '';

            if (order.order_status === 'pending') {
                if (order.is_cash) {
                    text = copy.hintCash;
                } else if (order.payment_state === 'pending') {
                    text = copy.hintBank;
                } else if (order.payment_state === 'verified' && cancelButton) {
                    // Cancel is withheld for a verified payment: say how to get it back.
                    text = copy.hintCancel;
                }
            }

            hint.textContent = text ?? '';
            hint.classList.toggle('hidden', !text);
        }

        // Always opens on the normal footer, never on a confirmation left from before.
        leaveConfirm();

        emit('open-modal', 'order-details-modal');
    };

    return { open };
}
