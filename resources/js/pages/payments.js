/**
 * Payments page.
 *
 * Table or cards, the view popup, and delete. The popup (Verify / Reject / Undo
 * inside) is the shared component resources/js/ui/payment-popup.js.
 * The only import is the toast (resources/js/ui/toast.js); URLs and copy come
 * from the Blade view and from the server rendered action buttons through
 * `data-*` attributes. Proof pictures carry `data-zoom`, so the shared image
 * viewer (resources/js/ui/image-viewer.js) enlarges them.
 *
 * Flow:
 *   [data-view-mode]            -> swap the table for the card grid (and back)
 *   [data-row-action=view]      -> open the shared payment popup with the row the table holds
 *   [data-row-action=delete]    -> delete form points at the record, open modal
 */
import DataTable from 'datatables.net-dt';
import { initTableToolbar } from '../ui/table-toolbar';
import { showToast } from '../ui/toast';
import { initPaymentPopup } from '../ui/payment-popup';

const start = () => {
    const tableElement = document.querySelector('#payments-table');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');

    if (!tableElement || !deleteForm) {
        console.warn('The payments page is incomplete; nothing was wired.');

        return;
    }

    /* ---- helpers --------------------------------------------------------- */

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const money = (value) => '₱' + Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // 2026-11-10 14:05:00 -> Nov 10, 2026, 2:05 PM
    const prettyDateTime = (value) => {
        const parsed = new Date(String(value ?? '').replace(' ', 'T'));

        return Number.isNaN(parsed.getTime())
            ? '—'
            : parsed.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
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

    const showErrors = (element, list) => {
        if (!element) {
            return;
        }

        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        element.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        element.classList.toggle('hidden', items.length === 0);
    };

    const send = async (form) => {
        try {
            const response = await fetch(form.action, {
                method: 'POST', // the forms carry _method for DELETE
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: new FormData(form),
            });

            const payload = await response.json().catch(() => ({}));

            return { ok: response.ok, payload };
        } catch (error) {
            console.warn('The request could not be sent.', error);

            return { ok: false, payload: {} };
        }
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

    const copy = tableElement.dataset;

    // The proof as a small picture that opens in the image viewer.
    const proofThumb = (payment) => (payment.proof_url
        ? '<img src="' + escapeHtml(payment.proof_url) + '" alt="" data-zoom data-zoom-caption="' + escapeHtml(copy.proofCaption ?? '') + '"'
          + ' loading="lazy" class="h-10 w-10 cursor-zoom-in rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-600" onerror="this.remove()">'
        : '<span class="text-gray-400 dark:text-gray-500">—</span>');

    /* ---- table ----------------------------------------------------------- */

    let rowIndexes = new Map();

    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    const orderCell = (value, type) => (type === 'display'
        ? '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(value) + '</span>'
        : value ?? '');

    const amountCell = (value, type) => (type === 'display'
        ? '<span class="block text-right font-medium tabular-nums text-gray-900 dark:text-white">' + money(value) + '</span>'
        : Number(value ?? 0));

    const proofCell = (data, type, row) => (type === 'display' ? proofThumb(row) : (row.proof_url ? 'proof' : ''));

    const dateCell = (value, type) => (type === 'display'
        ? '<span class="whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">' + escapeHtml(prettyDateTime(value)) + '</span>'
        : value ?? '');

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Newest payment first (column 0, the running number, is not orderable).
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
            emptyTable: 'No data available yet',
            zeroRecords: 'No rows match the search and filters',
        },
        ajax: tableElement.dataset.endpoint,
        columns: [
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'order_number', render: orderCell },
            { data: 'customer_name', className: 'hidden sm:table-cell' },
            { data: 'method_name', className: 'hidden md:table-cell' },
            { data: 'reference_number', className: 'hidden lg:table-cell', defaultContent: '—' },
            { data: 'amount', render: amountCell },
            { data: null, orderable: false, searchable: false, className: 'hidden sm:table-cell', render: proofCell },
            { data: 'status' },
            { data: 'created_at', className: 'hidden lg:table-cell', render: dateCell },
            { data: 'action', orderable: false, searchable: false, className: 'whitespace-nowrap text-right' },
        ],
    });

    // Search, sort and the filter dropdown above the table.
    initTableToolbar(table);

    const reload = () => table.ajax.reload(null, false);

    table.on('preDraw', () => {
        rowIndexes = new Map(
            table.rows({ search: 'applied', order: 'applied' }).indexes().toArray()
                .map((index, position) => [index, position + 1]),
        );
    });

    /* ---- filters --------------------------------------------------------- */

    // One named search holds every filter, so the table's own search box keeps
    // working next to them, and the card grid (built from the filtered rows)
    // follows. The server renders the status badge, which carries its state in
    // data-status; the rest are read from the row's own fields.
    const statusFilter = document.querySelector('#payment-status-filter');
    const methodFilter = document.querySelector('#payment-method-filter');
    const sessionFilter = document.querySelector('#payment-session-filter');
    const dateFrom = document.querySelector('#payment-date-from');
    const dateTo = document.querySelector('#payment-date-to');

    table.search.fixed('filters', (searchData, rowData) => {
        const status = statusFilter?.value ?? '';
        const method = methodFilter?.value ?? '';
        const session = sessionFilter?.value ?? '';
        const from = dateFrom?.value ?? '';
        const to = dateTo?.value ?? '';

        // The day the payment was made (YYYY-MM-DD, compared as text).
        const day = String(rowData.created_at ?? '').slice(0, 10);

        return (status === '' || String(rowData.status ?? '').includes('data-status="' + status + '"'))
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

    // The method and session lists offer what the payments hold. They are
    // rebuilt when the feed changes (a decision, a delete), keeping the choice
    // while it still exists.
    let optionsKey = '';

    const syncFilterOptions = () => {
        const rows = table.rows().data().toArray();

        const methods = new Map();
        const sessions = new Map();

        for (const row of rows) {
            if (row.payment_method_id && !methods.has(String(row.payment_method_id))) {
                methods.set(String(row.payment_method_id), row.method_name ?? '—');
            }

            if (row.session_id && !sessions.has(String(row.session_id))) {
                sessions.set(String(row.session_id), {
                    date: String(row.session_date ?? '').slice(0, 10),
                    start: String(row.start_time ?? '').slice(0, 5),
                    end: String(row.end_time ?? '').slice(0, 5),
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

    const onFilterChange = () => {
        table.draw();
    };

    // The date range can never run backwards. The pickers already refuse it
    // (each box limits the other), and a date typed in by hand that breaks the
    // rule is pulled back to the other end of the range, with a message.
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
    [statusFilter, methodFilter, sessionFilter].forEach((control) => control?.addEventListener('change', onFilterChange));

    [dateFrom, dateTo].forEach((control) => control?.addEventListener('change', () => {
        keepRangeValid(control);
        onFilterChange();
    }));

    table.on('draw', syncFilterOptions);

    /* ---- view mode ------------------------------------------------------- */

    const cardsElement = document.querySelector('#payments-cards');
    const viewButtons = Array.from(document.querySelectorAll('[data-view-mode]'));
    const VIEW_STORAGE_KEY = 'cucina.viewMode.payments';

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

    const cardTemplate = (payment) => `
        <article class="flex flex-col justify-between overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-start justify-between gap-2 border-b border-gray-100 p-4 dark:border-gray-700/60">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">${escapeHtml(payment.order_number)}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">${escapeHtml(prettyDateTime(payment.created_at))}</p>
                </div>
                ${payment.status ?? ''}
            </div>
            <dl class="flex flex-1 flex-col gap-2 p-4 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(copy.customerLabel ?? '')}</dt>
                    <dd class="truncate text-gray-900 dark:text-white">${escapeHtml(payment.customer_name ?? '—')}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(copy.methodLabel ?? '')}</dt>
                    <dd class="truncate text-gray-900 dark:text-white">${escapeHtml(payment.method_name ?? '—')}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(copy.referenceLabel ?? '')}</dt>
                    <dd class="truncate tabular-nums text-gray-900 dark:text-white">${escapeHtml(payment.reference_number ?? '—')}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(copy.amountLabel ?? '')}</dt>
                    <dd class="text-base font-semibold tabular-nums text-indigo-600 dark:text-indigo-400">${money(payment.amount)}</dd>
                </div>
                ${payment.proof_url ? `<div class="pt-1">${proofThumb(payment)}</div>` : ''}
            </dl>
            <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
                ${payment.action ?? ''}
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

    // Search and pagination frame both views.
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

    /* ---- the view popup -------------------------------------------------- */

    // After a decision the table is fetched again, so the status badge, the
    // buttons and the filters all follow the payment.
    const popup = initPaymentPopup({ onChange: reload });

    const openPayment = (orderId) => {
        const payment = table.rows().data().toArray().find((row) => Number(row.order_id) === Number(orderId));

        popup?.open(payment);
    };

    /* ---- row actions ----------------------------------------------------- */

    // One click = one action: a button ignores further clicks for this long.
    const CLICK_COOLDOWN_MS = 600;

    // Rows are injected by DataTables, so the actions are handled by delegation.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-row-action]');

        if (!button || button.disabled) {
            return;
        }

        const now = Date.now();

        if (now < Number(button.dataset.readyAt ?? 0)) {
            return;
        }

        button.dataset.readyAt = String(now + CLICK_COOLDOWN_MS);

        const action = button.dataset.rowAction;

        if (action === 'view') {
            openPayment(button.dataset.id);

            return;
        }

        if (action === 'delete') {
            // The confirmation is already up: ignore repeat clicks on the row.
            if (deleteForm.closest('[role="dialog"]')?.getAttribute('aria-hidden') === 'false') {
                return;
            }

            deleteForm.action = button.dataset.deleteUrl;
            showErrors(deleteErrors, []);
            emit('open-modal', 'delete-confirmation-modal');
        }
    });
    // Delete (soft delete on the server).
    let deleting = false;

    deleteForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (deleting) {
            return;
        }

        deleting = true;

        const done = busy(deleteButton);

        try {
            const { ok, payload } = await send(deleteForm);

            if (!ok) {
                showErrors(deleteErrors, messages(payload));

                return;
            }

            emit('close-modal', 'delete-confirmation-modal');
            showToast(payload.message);
            reload();
        } finally {
            deleting = false;
            done();
        }
    });
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
