/**
 * One order session's page: its orders as a table, or as cards.
 *
 * Self contained, like the other page modules. The only import is the toast
 * (resources/js/ui/toast.js); copy and the feed URL come from the Blade view
 * through `data-*` attributes. Proof links carry `data-zoom`, so the shared
 * image viewer (resources/js/ui/image-viewer.js) enlarges them.
 *
 * Flow:
 *   #session-orders-table   -> DataTable fed by this page's own AJAX response
 *   [data-view-mode]        -> swap the table for the card grid (and back)
 *   #*-status-filter        -> narrow the rows by order / payment status
 *   [data-order-view]       -> Actions column: read only order details modal
 *   Complete / Cancel / Verify -> (in that modal) an inline confirm bar, then POST; Reject -> its reason popup
 *   #end-session            -> confirmation modal, POST .../end, reload the page
 */
import DataTable from 'datatables.net-dt';
import { initTableToolbar } from '../ui/table-toolbar';
import { showToast } from '../ui/toast';
import { initOrderPopup, post } from '../ui/order-popup';
import { proofLink } from '../ui/proof-link';

const start = () => {
    const tableElement = document.querySelector('#session-orders-table');
    const endButton = document.querySelector('#end-session');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmSubject = document.querySelector('#save-modal-subject');
    const confirmButton = document.querySelector('#save-modal-button');

    if (!tableElement) {
        console.warn('The order session page is incomplete; nothing was wired.');

        return;
    }

    /* ---- helpers --------------------------------------------------------- */

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const money = (value) => '₱' + Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // 2026-11-10 14:05:00 -> Nov 10, 2:05 PM
    const prettyDateTime = (value) => {
        const parsed = new Date(String(value ?? '').replace(' ', 'T'));

        return Number.isNaN(parsed.getTime())
            ? String(value ?? '—')
            : parsed.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    };

    const copy = tableElement.dataset;

    // "2× Adobo" lines of one order.
    const itemsText = (order) => (order.items ?? [])
        .map((item) => item.quantity + '× ' + item.dish_name)
        .join(', ');

    const itemLines = (order, extra = '') => (order.items ?? []).length === 0
        ? '<span class="text-gray-400 dark:text-gray-500">—</span>'
        : (order.items ?? []).map((item) => '<span class="' + extra + 'block">'
            + '<span class="tabular-nums text-gray-500 dark:text-gray-400">' + Number(item.quantity) + '×</span> '
            + escapeHtml(item.dish_name) + '</span>').join('');

    // Method, the customer's reference, the proof link and the payment badge.
    const paymentHtml = (order) => '<span class="block text-sm text-gray-700 dark:text-gray-300">' + escapeHtml(order.method_name ?? '—') + '</span>'
        + (order.reference_number ? '<span class="mt-0.5 block text-xs tabular-nums text-gray-500 dark:text-gray-400">' + escapeHtml(order.reference_number) + '</span>' : '')
        + (order.proof_url
            ? '<span class="mt-1 block">' + proofLink(order.proof_url, copy.proofLabel, copy.proofCaption) + '</span>'
            : '')
        + '<span class="mt-1 block">' + (order.payment_badge ?? '') + '</span>';

    /* ---- table ----------------------------------------------------------- */

    let rowIndexes = new Map();

    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    const numberCell = (value, type) => (type === 'display'
        ? '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(value) + '</span>'
        : value ?? '');

    const itemsCell = (data, type, row) => (type === 'display' ? '<span class="text-sm text-gray-700 dark:text-gray-300">' + itemLines(row) + '</span>' : itemsText(row));

    const totalCell = (value, type) => (type === 'display'
        ? '<span class="block text-right font-medium tabular-nums text-gray-900 dark:text-white">' + money(value) + '</span>'
        : Number(value ?? 0));

    const paymentCell = (data, type, row) => (type === 'display'
        ? paymentHtml(row)
        : [row.method_name, row.reference_number].filter(Boolean).join(' '));

    const placedCell = (value, type) => (type === 'display'
        ? '<span class="whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">' + escapeHtml(prettyDateTime(value)) + '</span>'
        : value ?? '');

    // The row's one action: a read only look at the order. The button carries
    // the order id; the click below finds the row again in the table.
    const viewButton = (order) => '<button type="button" data-order-view="' + Number(order.order_id) + '"'
        + ' class="mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200">'
        + escapeHtml(copy.viewLabel ?? 'View') + '</button>';

    const actionCell = (data, type, row) => (type === 'display' ? viewButton(row) : '');

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Newest order first (column 0, the running number, is not orderable).
        order: [[7, 'desc']],
        layout: {
            topStart: null,
            // The toolbar above the table (resources/js/ui/table-toolbar.js) searches instead.
            topEnd: null,
            bottomStart: ['info', 'pageLength'],
            bottomEnd: 'paging',
        },
        language: {
            // No label next to the box: the placeholder says it (the label is hidden in app.css).
            search: '',
            searchPlaceholder: 'Search…',
            lengthMenu: '_MENU_ per page',
            infoEmpty: 'No rows to show',
            infoFiltered: '(filtered from _MAX_ rows)',
            emptyTable: 'No orders in this session yet.',
            zeroRecords: 'No rows match the search and filters',
        },
        ajax: tableElement.dataset.endpoint,
        columns: [
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'order_number', render: numberCell },
            { data: 'customer_name' },
            { data: null, orderable: false, className: 'hidden md:table-cell', render: itemsCell },
            { data: 'total_amount', render: totalCell },
            { data: null, orderable: false, className: 'hidden sm:table-cell', render: paymentCell },
            { data: 'status_badge' },
            { data: 'order_date', className: 'hidden lg:table-cell', render: placedCell },
            { data: null, orderable: false, searchable: false, className: 'whitespace-nowrap text-right', render: actionCell },
        ],
    });

    // Search, sort and the filter dropdown above the table.
    initTableToolbar(table);

    table.on('preDraw', () => {
        rowIndexes = new Map(
            table.rows({ search: 'applied', order: 'applied' }).indexes().toArray()
                .map((index, position) => [index, position + 1]),
        );
    });

    /* ---- filters --------------------------------------------------------- */

    // Named searches, so the table's own search box keeps working next to them.
    // The server renders the badges, and each carries its state in data-status.
    const orderFilter = document.querySelector('#order-status-filter');
    const paymentFilter = document.querySelector('#payment-status-filter');

    table.search.fixed('filters', (searchData, rowData) => {
        const order = orderFilter?.value ?? '';
        const payment = paymentFilter?.value ?? '';

        return (order === '' || String(rowData.status_badge ?? '').includes('data-status="' + order + '"'))
            && (payment === '' || String(rowData.payment_badge ?? '').includes('data-status="' + payment + '"'));
    });

    orderFilter?.addEventListener('change', () => table.draw());
    paymentFilter?.addEventListener('change', () => table.draw());

    /* ---- view mode ------------------------------------------------------- */

    const cardsElement = document.querySelector('#session-orders-cards');
    const viewButtons = Array.from(document.querySelectorAll('[data-view-mode]'));
    const VIEW_STORAGE_KEY = 'cucina.viewMode.sessionOrders';

    const storedView = (() => {
        try {
            return localStorage.getItem(VIEW_STORAGE_KEY);
        } catch (error) {
            return null;
        }
    })();

    // Phones start on the card grid; a view the user picked always wins.
    const phoneLayout = window.matchMedia('(max-width: 639px)').matches;

    let viewMode = storedView === 'card' || storedView === 'table'
        ? storedView
        : (phoneLayout ? 'card' : 'table');

    let feedLoaded = false;

    const cardTemplate = (order) => `
        <article class="flex flex-col justify-between overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-start justify-between gap-2 border-b border-gray-100 p-4 dark:border-gray-700/60">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">${escapeHtml(order.order_number)}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">${escapeHtml(prettyDateTime(order.order_date))}</p>
                </div>
                ${order.status_badge ?? ''}
            </div>
            <dl class="flex flex-1 flex-col gap-3 p-4 text-sm">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy.customerLabel ?? '')}</dt>
                    <dd class="text-gray-900 dark:text-white">${escapeHtml(order.customer_name ?? '—')}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy.itemsLabel ?? '')}</dt>
                    <dd class="text-gray-700 dark:text-gray-300">${itemLines(order)}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy.paymentLabel ?? '')}</dt>
                    <dd>${paymentHtml(order)}</dd>
                </div>
            </dl>
            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
                <span class="text-xs text-gray-500 dark:text-gray-400">${Number(order.items_count ?? 0)} ${escapeHtml(copy.itemsLabel ?? '')}</span>
                <span class="text-base font-semibold tabular-nums text-indigo-600 dark:text-indigo-400">${money(order.total_amount)}</span>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 px-4 py-2 dark:border-gray-700/60">
                ${viewButton(order)}
            </div>
        </article>`;

    const renderCards = () => {
        if (viewMode !== 'card' || !cardsElement || !feedLoaded) {
            return;
        }

        const rows = table.rows({ page: 'current', search: 'applied' }).data().toArray();

        cardsElement.innerHTML = rows.length === 0
            ? '<p class="col-span-full py-8 text-center text-sm text-gray-500 dark:text-gray-400">' + escapeHtml(cardsElement.dataset.empty ?? '') + '</p>'
            : rows.map(cardTemplate).join('');
    };

    const applyViewMode = (mode, remember = true) => {
        viewMode = mode === 'card' ? 'card' : 'table';

        const cardMode = viewMode === 'card';

        tableElement.classList.toggle('hidden', cardMode);
        cardsElement?.classList.toggle('hidden', !cardMode);

        viewButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.viewMode === viewMode)));

        if (cardMode) {
            renderCards();
        } else {
            table.columns.adjust();
        }

        if (!remember) {
            return;
        }

        try {
            localStorage.setItem(VIEW_STORAGE_KEY, viewMode);
        } catch (error) {
            // Storage may be disabled; the choice then only lasts this visit.
        }
    };

    // Search and pagination frame both views: the card grid goes into the
    // table's row of the DataTables container.
    const tableRow = tableElement.closest('.dt-layout-table');

    if (cardsElement && tableRow) {
        tableRow.append(cardsElement);
    }

    table.on('draw', () => {
        feedLoaded = true;
        renderCards();
    });

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => applyViewMode(button.dataset.viewMode));
    });

    applyViewMode(viewMode, false);

    /* ---- the order popup (details + actions), shared with the Orders page --- */

    // Cancelling gives the dishes back, so the page's stock is stale: it reloads. Every
    // other action only changes the orders, so the table is fetched again.
    const popup = initOrderPopup({
        onChange: (kind) => {
            if (kind === 'cancel') {
                setTimeout(() => window.location.reload(), 700);

                return;
            }

            table.ajax.reload(null, false);
        },
    });

    // Rows and cards are drawn by script, so the button is handled by delegation.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-order-view]');

        if (button) {
            popup.open(table.rows().data().toArray().find((row) => Number(row.order_id) === Number(button.dataset.orderView)));
        }
    });

    /* ---- confirmation: end the session ------------------------------------- */

    // The shared confirmation modal; the End wording is what it starts with.
    const endCopy = {
        title: confirmTitle?.textContent ?? '',
        message: confirmMessage?.textContent ?? '',
        label: confirmButton?.textContent ?? '',
    };

    let pending = null; // { url, kind: 'end' }
    let sending = false;

    const askConfirmation = (request, copyOf, subject) => {
        pending = request;

        confirmTitle.textContent = copyOf.title;
        confirmMessage.textContent = copyOf.message;
        confirmButton.textContent = copyOf.label;
        confirmSubject.textContent = subject ?? '';
        confirmSubject.classList.toggle('hidden', !subject);

        emit('open-modal', 'edit-confirmation-modal');
    };

    endButton?.addEventListener('click', () => {
        askConfirmation({ url: endButton.dataset.endUrl, kind: 'end' }, endCopy, endButton.dataset.subject);
    });

    confirmButton?.addEventListener('click', async () => {
        // A second click while the request is in flight would send twice.
        if (!pending || sending) {
            return;
        }

        const { url, kind } = pending;

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
            // The page (status, End button, stock) is server rendered: load it again.
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
