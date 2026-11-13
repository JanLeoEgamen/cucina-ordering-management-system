/**
 * Orders page: every order of every session, as a table or as cards.
 *
 * Self contained, like the other page modules. The feed is this page's own AJAX
 * response (OrderController::all); copy comes from the Blade view through `data-*`
 * attributes. A row's View button opens the shared order popup
 * (resources/js/ui/order-popup.js), where an order is completed, cancelled, or its
 * payment verified / rejected - the same popup the order session page uses.
 *
 * Flow:
 *   #orders-table      -> DataTable fed by the AJAX response
 *   [data-view-mode]   -> swap the table for the card grid (and back)
 *   #orders-*-filter   -> narrow the rows: order status, payment status, method,
 *                         session, and a date range (all in the browser)
 *   [data-order-view]  -> the order popup
 */
import DataTable from 'datatables.net-dt';
import { initTableToolbar } from '../ui/table-toolbar';
import { showToast } from '../ui/toast';
import { escapeHtml, initOrderPopup, money, prettyDateTime } from '../ui/order-popup';
import { proofLink } from '../ui/proof-link';

const start = () => {
    const tableElement = document.querySelector('#orders-table');

    if (!tableElement) {
        console.warn('The orders page is incomplete; nothing was wired.');

        return;
    }

    const copy = tableElement.dataset;

    /* ---- cells ----------------------------------------------------------- */

    // "2× Adobo" lines of one order.
    const itemsText = (order) => (order.items ?? [])
        .map((item) => item.quantity + '× ' + item.dish_name)
        .join(', ');

    const itemLines = (order) => (order.items ?? []).length === 0
        ? '<span class="text-gray-400 dark:text-gray-500">—</span>'
        : (order.items ?? []).map((item) => '<span class="block">'
            + '<span class="tabular-nums text-gray-500 dark:text-gray-400">' + Number(item.quantity) + '×</span> '
            + escapeHtml(item.dish_name) + '</span>').join('');

    // Method, the customer's reference, the proof link and the payment badge.
    const paymentHtml = (order) => '<span class="block text-sm text-gray-700 dark:text-gray-300">' + escapeHtml(order.method_name ?? '—') + '</span>'
        + (order.reference_number ? '<span class="mt-0.5 block text-xs tabular-nums text-gray-500 dark:text-gray-400">' + escapeHtml(order.reference_number) + '</span>' : '')
        + (order.proof_url
            ? '<span class="mt-1 block">' + proofLink(order.proof_url, copy.proofLabel, copy.proofCaption) + '</span>'
            : '')
        + '<span class="mt-1 block">' + (order.payment_badge ?? '') + '</span>';

    // The session the order was placed in, as a link to its page when allowed.
    const sessionHtml = (order) => order.session_url
        ? '<a href="' + escapeHtml(order.session_url) + '" class="text-sm text-indigo-600 underline-offset-2 hover:underline dark:text-indigo-400">' + escapeHtml(order.session_label ?? '—') + '</a>'
        : '<span class="text-sm text-gray-700 dark:text-gray-300">' + escapeHtml(order.session_label ?? '—') + '</span>';

    let rowIndexes = new Map();

    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    const numberCell = (value, type) => (type === 'display'
        ? '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(value) + '</span>'
        : value ?? '');

    const sessionCell = (data, type, row) => (type === 'display' ? sessionHtml(row) : row.session_label ?? '');

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

    // The row's one action: the popup, which holds the order's own actions. The
    // button carries the order id; the click below finds the row in the table.
    const viewButton = (order) => '<button type="button" data-order-view="' + Number(order.order_id) + '"'
        + ' class="mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200">'
        + escapeHtml(copy.viewLabel ?? 'View') + '</button>';

    const actionCell = (data, type, row) => (type === 'display' ? viewButton(row) : '');

    /* ---- table ----------------------------------------------------------- */

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Newest order first (column 0, the running number, is not orderable).
        order: [[8, 'desc']],
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
            emptyTable: 'No orders yet.',
            zeroRecords: 'No rows match the search and filters',
        },
        ajax: tableElement.dataset.endpoint,
        columns: [
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'order_number', render: numberCell },
            { data: 'customer_name' },
            { data: null, className: 'hidden lg:table-cell', render: sessionCell },
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

    // One named search holds every filter, so the table's own search box keeps working
    // next to them and the card grid (built from the filtered rows) follows. The server
    // renders both badges, and each carries its state in data-status.
    const statusFilter = document.querySelector('#orders-status-filter');
    const paymentFilter = document.querySelector('#orders-payment-filter');
    const methodFilter = document.querySelector('#orders-method-filter');
    const sessionFilter = document.querySelector('#orders-session-filter');
    const dateFrom = document.querySelector('#orders-date-from');
    const dateTo = document.querySelector('#orders-date-to');

    table.search.fixed('filters', (searchData, rowData) => {
        const status = statusFilter?.value ?? '';
        const payment = paymentFilter?.value ?? '';
        const method = methodFilter?.value ?? '';
        const session = sessionFilter?.value ?? '';
        const from = dateFrom?.value ?? '';
        const to = dateTo?.value ?? '';

        // The day the order was placed (YYYY-MM-DD, compared as text).
        const day = String(rowData.order_date ?? '').slice(0, 10);

        return (status === '' || String(rowData.status_badge ?? '').includes('data-status="' + status + '"'))
            && (payment === '' || String(rowData.payment_badge ?? '').includes('data-status="' + payment + '"'))
            && (method === '' || String(rowData.payment_method_id) === method)
            && (session === '' || String(rowData.session_id) === session)
            && (from === '' || (day !== '' && day >= from))
            && (to === '' || (day !== '' && day <= to));
    });

    // 2026-11-10 -> Nov 10, 2026
    const prettyDay = (value) => {
        const parsed = new Date(String(value ?? '').slice(0, 10) + 'T00:00:00');

        return Number.isNaN(parsed.getTime())
            ? String(value ?? '')
            : parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    // The method and session lists offer what the orders hold. They are rebuilt when the
    // feed changes (an order completed, cancelled), keeping the choice while it still exists.
    let optionsKey = '';

    const syncFilterOptions = () => {
        const methods = new Map();
        const sessions = new Map();

        for (const row of table.rows().data().toArray()) {
            if (row.payment_method_id && !methods.has(String(row.payment_method_id))) {
                methods.set(String(row.payment_method_id), row.method_name ?? '—');
            }

            if (row.session_id && !sessions.has(String(row.session_id))) {
                sessions.set(String(row.session_id), {
                    date: String(row.session_date ?? '').slice(0, 10),
                    start: String(row.session_start ?? '').slice(0, 5),
                    end: String(row.session_end ?? '').slice(0, 5),
                });
            }
        }

        const methodList = [...methods.entries()].sort((a, b) => a[1].localeCompare(b[1]));
        // Newest session first.
        const sessionList = [...sessions.entries()].sort((a, b) => (b[1].date + b[1].start).localeCompare(a[1].date + a[1].start));

        const key = JSON.stringify([methodList, sessionList]);

        if (key === optionsKey) {
            return;
        }

        optionsKey = key;

        const fill = (select, entries, label) => {
            if (!select) {
                return;
            }

            const chosen = select.value;
            const first = select.querySelector('option[value=""]')?.outerHTML ?? '<option value=""></option>';

            select.innerHTML = first + entries.map(([id, text]) => '<option value="' + escapeHtml(id) + '">' + escapeHtml(label(text)) + '</option>').join('');
            select.value = entries.some(([id]) => id === chosen) ? chosen : '';
        };

        fill(methodFilter, methodList, (name) => name);
        fill(sessionFilter, sessionList, (session) => prettyDay(session.date) + ' · ' + session.start + '–' + session.end);
    };

    // The date range can never run backwards. The pickers already refuse it (each box
    // limits the other), and a date typed in by hand that breaks the rule is pulled back
    // to the other end of the range, with a message.
    const keepRangeValid = (changed) => {
        const from = dateFrom?.value ?? '';
        const to = dateTo?.value ?? '';

        if (changed && from !== '' && to !== '' && from > to) {
            changed.value = changed === dateFrom ? to : from;

            showToast(copy.dateRangeError ?? 'Date from cannot be later than date to.', 'warning');
        }

        if (dateFrom && dateTo) {
            dateTo.min = dateFrom.value;
            dateFrom.max = dateTo.value;
        }
    };

    // "Clear filters" lives in the toolbar (resources/js/ui/table-toolbar.js): it empties these
    // controls and fires `change` on each, so the listeners below also serve it.
    [statusFilter, paymentFilter, methodFilter, sessionFilter].forEach((control) => control?.addEventListener('change', () => table.draw()));

    [dateFrom, dateTo].forEach((control) => control?.addEventListener('change', () => {
        keepRangeValid(control);
        table.draw();
    }));

    table.on('draw', syncFilterOptions);

    /* ---- view mode ------------------------------------------------------- */

    const cardsElement = document.querySelector('#orders-cards');
    const viewButtons = Array.from(document.querySelectorAll('[data-view-mode]'));
    const VIEW_STORAGE_KEY = 'cucina.viewMode.orders';

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

    const label = (text) => '<dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">' + escapeHtml(text ?? '') + '</dt>';

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
                <div>${label(copy.customerLabel)}<dd class="text-gray-900 dark:text-white">${escapeHtml(order.customer_name ?? '—')}</dd></div>
                <div>${label(copy.sessionLabel)}<dd>${sessionHtml(order)}</dd></div>
                <div>${label(copy.itemsLabel)}<dd class="text-gray-700 dark:text-gray-300">${itemLines(order)}</dd></div>
                <div>${label(copy.paymentLabel)}<dd>${paymentHtml(order)}</dd></div>
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

    // Search and pagination frame both views: the card grid goes into the table's row
    // of the DataTables container.
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

    /* ---- the order popup ------------------------------------------------- */

    // Whatever the action was, the order (and so the row, its badges and its buttons)
    // changed: fetch the orders again. Cancelling gives stock back, which this page does
    // not show, so no page reload is needed here.
    const popup = initOrderPopup({ onChange: () => table.ajax.reload(null, false) });

    // Rows and cards are drawn by script, so the button is handled by delegation.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-order-view]');

        if (button) {
            popup.open(table.rows().data().toArray().find((row) => Number(row.order_id) === Number(button.dataset.orderView)));
        }
    });
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
