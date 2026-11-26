/**
 * One order session's page: its orders as a table, or as cards.
 *
 * Self contained, like the other page modules. The only import is the toast
 * (resources/js/ui/toast.js); copy and the feed URL come from the Blade view
 * through `data-*` attributes. The payment method's name is the proof link (not for
 * cash); it carries `data-zoom`, so the shared image viewer
 * (resources/js/ui/image-viewer.js) enlarges the proof.
 *
 * Flow:
 *   #session-orders-table   -> DataTable fed by this page's own AJAX response
 *   [data-view-mode]        -> swap the table for the card grid (and back)
 *   #*-status-filter        -> narrow the rows by order / payment status
 *   a row or card           -> the order details modal ([data-order-open]: its number)
 *   Complete / Cancel / Verify -> (in that modal) an inline confirm bar, then POST; Reject -> its reason popup
 *   #end-session            -> confirmation modal, POST .../end, reload the page
 */
import DataTable from 'datatables.net-dt';
import { initTableToolbar } from '../ui/table-toolbar';
import { showToast } from '../ui/toast';
import { initOrderPopup, post } from '../ui/order-popup';

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

    // The method's name. When the order was not paid in cash and has a proof, the name itself is
    // the link to it (underlined, so it reads as one): `data-zoom` opens it in the image viewer.
    const methodHtml = (order) => {
        const name = escapeHtml(order.method_name ?? '—');

        if (order.is_cash || !order.proof_url) {
            return name;
        }

        return '<a href="' + escapeHtml(order.proof_url) + '" target="_blank" rel="noopener" data-zoom data-zoom-caption="' + escapeHtml(copy.proofCaption) + '"'
            + ' title="' + escapeHtml(copy.proofLabel) + '" aria-label="' + escapeHtml((order.method_name ?? '') + ': ' + copy.proofLabel) + '"'
            + ' class="rounded font-medium text-indigo-600 underline underline-offset-4 transition hover:text-indigo-800 hover:decoration-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">'
            + name + '</a>';
    };

    // Method (the proof link) and, under it, the customer's reference.
    const paymentMethodHtml = (order) => '<span class="block text-sm text-gray-700 dark:text-gray-300">' + methodHtml(order) + '</span>'
        + (order.reference_number ? '<span class="mt-0.5 block text-xs tabular-nums text-gray-500 dark:text-gray-400">' + escapeHtml(order.reference_number) + '</span>' : '');

    // The order number is a button: the keyboard's and a screen reader's way into a row or card
    // that the mouse can click anywhere.
    const openButton = (order, classes) => '<button type="button" data-order-open="' + Number(order.order_id) + '"'
        + ' aria-label="' + escapeHtml((copy.openLabel ?? '') + ' ' + order.order_number) + '" aria-haspopup="dialog"'
        + ' class="rounded text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 ' + classes + '">'
        + escapeHtml(order.order_number) + '</button>';

    /* ---- table ----------------------------------------------------------- */

    let rowIndexes = new Map();

    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    const numberCell = (value, type, row) => (type === 'display'
        ? openButton(row, 'whitespace-nowrap font-medium text-gray-900 dark:text-white')
        : value ?? '');

    const itemsCell = (data, type, row) => (type === 'display' ? '<span class="text-sm text-gray-700 dark:text-gray-300">' + itemLines(row) + '</span>' : itemsText(row));

    const totalCell = (value, type) => (type === 'display'
        ? '<span class="block text-right font-medium tabular-nums text-gray-900 dark:text-white">' + money(value) + '</span>'
        : Number(value ?? 0));

    // Sorted by the method's name; the search also finds the reference number.
    const methodCell = (data, type, row) => {
        if (type === 'display') {
            return paymentMethodHtml(row);
        }

        return type === 'filter' ? [row.method_name, row.reference_number].filter(Boolean).join(' ') : row.method_name ?? '';
    };

    // Shown as the badge, sorted by the state behind it.
    const paymentStatusCell = (value, type, row) => (type === 'display' ? value ?? '' : row.payment_status ?? '');

    // The time sits under the date, so the nine columns fit a laptop screen.
    const placedHtml = (value) => {
        const [day, ...time] = prettyDateTime(value).split(', ');

        return '<span class="block whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">' + escapeHtml(day) + '</span>'
            + (time.length ? '<span class="mt-0.5 block whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">' + escapeHtml(time.join(', ')) + '</span>' : '');
    };

    const placedCell = (value, type) => (type === 'display' ? placedHtml(value) : value ?? '');

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
            { data: null, className: 'hidden sm:table-cell', render: methodCell },
            { data: 'payment_badge', className: 'hidden sm:table-cell', render: paymentStatusCell },
            { data: 'status_badge' },
            { data: 'order_date', className: 'hidden lg:table-cell', render: placedCell },
        ],
        // The whole row opens its order (see the click listener below).
        createdRow: (row) => row.classList.add('row-clickable'),
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
        <article data-order-card="${Number(order.order_id)}" class="flex cursor-pointer flex-col justify-between overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition hover:border-gray-300 hover:shadow-card-lift dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-500">
            <div class="flex items-start justify-between gap-2 border-b border-gray-100 p-4 dark:border-gray-700/60">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">${openButton(order, 'max-w-full truncate')}</p>
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
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy.methodLabel ?? '')}</dt>
                    <dd>${paymentMethodHtml(order)}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy.paymentStatusLabel ?? '')}</dt>
                    <dd class="mt-1">${order.payment_badge ?? ''}</dd>
                </div>
            </dl>
            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
                <span class="text-xs text-gray-500 dark:text-gray-400">${Number(order.items_count ?? 0)} ${escapeHtml(copy.itemsLabel ?? '')}</span>
                <span class="text-base font-semibold tabular-nums text-indigo-600 dark:text-indigo-400">${money(order.total_amount)}</span>
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
            // The Announce button's count of waiting orders is server rendered, so it reloads too.
            if (kind === 'cancel' || kind === 'ready') {
                setTimeout(() => window.location.reload(), 700);

                return;
            }

            table.ajax.reload(null, false);
        },
    });

    const openOrder = (orderId) => {
        const order = table.rows().data().toArray().find((row) => Number(row.order_id) === Number(orderId));

        if (order) {
            popup.open(order);
        }
    };

    // Rows and cards are drawn by script, so the clicks are handled by delegation. A row or card
    // opens its order wherever it is clicked, except on something with a job of its own (the
    // proof link) and while text is being selected (copying an order or reference number).
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-order-open]');

        if (button) {
            openOrder(button.dataset.orderOpen);

            return;
        }

        if (event.target.closest('a, button, input, select, label, [data-zoom]') || window.getSelection()?.toString()) {
            return;
        }

        const card = event.target.closest('[data-order-card]');

        if (card) {
            openOrder(card.dataset.orderCard);

            return;
        }

        const row = event.target.closest('#session-orders-table tbody tr.row-clickable');

        if (row) {
            openOrder(table.row(row).data()?.order_id);
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

    // "Ready for Pick up": the same confirmation modal, with the button's own words.
    const announceButton = document.querySelector('#announce-ready');

    announceButton?.addEventListener('click', () => {
        askConfirmation({ url: announceButton.dataset.announceUrl, kind: 'announce' }, {
            title: announceButton.dataset.title,
            message: announceButton.dataset.message,
            label: announceButton.dataset.label,
        }, announceButton.dataset.subject);
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

// Started by resources/js/ui/soft-nav.js on every visit (see <x-page-module>); what start() adds to
// document / window is removed when the page is left.
export default start;
