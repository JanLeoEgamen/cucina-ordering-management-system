/**
 * Order sessions list page.
 *
 * One self contained script: table, drawer wiring, confirmations, requests.
 * The only import is the toast (resources/js/ui/toast.js); everything else the
 * page needs - cell markup, copy, URLs - comes from the Blade view through
 * `data-*` attributes, so nothing is hard coded here.
 *
 * Flow:
 *   #create-order-session        -> reset the create form, open its drawer
 *   [data-view-mode]             -> table, card grid or month calendar
 *   View                         -> a plain link to the session page (order-session-show.js)
 *   [data-row-action=edit]       -> GET the session, fill the edit form, open it
 *   [data-row-action=end]        -> confirmation, then POST .../end
 *   [data-row-action=delete]     -> delete form points at the record, open modal
 *   form submit                  -> confirmation modal (copy from the form's data-*)
 *   confirm                      -> send the form, toast, reload; failures keep the drawer open
 */
import DataTable from 'datatables.net-dt';
import { initTableToolbar } from '../ui/table-toolbar';
import { showToast } from '../ui/toast';

const start = () => {
    const tableElement = document.querySelector('#order-sessions-table');
    const createButton = document.querySelector('#create-order-session');
    const createForm = document.querySelector('#create-order-session-form');
    const createErrors = document.querySelector('#create-order-session-errors');
    const editForm = document.querySelector('#edit-order-session-form');
    const editErrors = document.querySelector('#edit-order-session-errors');
    const endForm = document.querySelector('#end-session-form');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmButton = document.querySelector('#save-modal-button');
    const confirmSubject = document.querySelector('#save-modal-subject');

    if (!tableElement || !createForm || !editForm) {
        console.warn('The order sessions page is incomplete; nothing was wired.');

        return;
    }

    /* ---- table ----------------------------------------------------------- */

    let rowIndexes = new Map();

    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    // The session date carries the row, so it is the one emphasised cell.
    const dateCell = (value, type) => (type === 'display'
        ? '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(prettyDate(value)) + '</span>'
        : value ?? '');

    // The window reads as one cell: the two times are metadata, not columns.
    const windowCell = (data, type, row) => {
        const window = shortTime(row.start_time) + ' – ' + shortTime(row.end_time);

        return type === 'display'
            ? '<span class="tabular-nums text-gray-600 dark:text-gray-300">' + escapeHtml(window) + '</span>'
            : window;
    };

    const countCell = (value, type) => (type === 'display'
        ? '<span class="tabular-nums text-gray-600 dark:text-gray-300">' + Number(value ?? 0) + '</span>'
        : Number(value ?? 0));

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Column 0 (#) is not orderable; the date sorts, newest first.
        order: [[1, 'desc']],
        layout: {
            // `null`, not omitted: the layout object is merged over DataTables'
            // defaults, which place the page length in the top row as well.
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
            // `hidden sm:table-cell` hides the column on phones: className is
            // applied to the header cell as well, so both disappear together.
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'order_date', render: dateCell },
            { data: null, orderable: false, searchable: false, render: windowCell },
            { data: 'dishes_count', className: 'hidden sm:table-cell', render: countCell },
            { data: 'orders_count', className: 'hidden sm:table-cell', render: countCell },
            { data: 'created_by_name', className: 'hidden md:table-cell' },
            { data: 'status', className: 'hidden sm:table-cell' },
            { data: 'action', orderable: false, searchable: false, className: 'whitespace-nowrap text-right' },
        ],
    });

    // Search, sort and the filter dropdown above the table.
    initTableToolbar(table);

    const reload = () => table.ajax.reload(null, false);

    // Rebuild the display numbers (indexCell above) for the row order the
    // coming draw will show.
    table.on('preDraw', () => {
        rowIndexes = new Map(
            table.rows({ search: 'applied', order: 'applied' }).indexes().toArray()
                .map((index, position) => [index, position + 1]),
        );
    });

    /* ---- filters --------------------------------------------------------- */

    // One named search narrows the feed without a request: the rows are already
    // in the browser, so this only decides which of them the table shows. It is
    // a *named* search, so the table's own search box keeps working next to it.
    const statusFilter = document.querySelector('#session-status-filter');

    table.search.fixed('filters', (searchData, rowData) => {
        const status = statusFilter?.value ?? '';

        // The server renders the badge, and it carries its state in data-status.
        return status === '' || String(rowData.status ?? '').includes('data-status="' + status + '"');
    });

    statusFilter?.addEventListener('change', () => table.draw());

    /* ---- helpers --------------------------------------------------------- */

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    // 2026-11-10 -> Nov 10, 2026 (the raw value is kept for sorting).
    const prettyDate = (value) => {
        if (!value) {
            return '—';
        }

        const parsed = new Date(String(value).slice(0, 10) + 'T00:00:00');

        return Number.isNaN(parsed.getTime())
            ? String(value)
            : parsed.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    // 08:00:00 -> 08:00
    const shortTime = (value) => String(value ?? '').slice(0, 5);

    const windowOf = (record) => shortTime(record.start_time) + ' – ' + shortTime(record.end_time);

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
                method: 'POST', // the forms carry _method for PUT / DELETE
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: new FormData(form),
            });

            let payload = {};

            try {
                payload = await response.json();
            } catch (error) {
                payload = {};
            }

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

    const eachField = (form, callback) => {
        for (const field of form.elements) {
            if (!field.name || field.name.startsWith('_')) continue;          // _token, _method
            if (['hidden', 'submit', 'button', 'reset', 'file', 'checkbox'].includes(field.type)) continue;

            callback(field);
        }
    };

    /* ---- the dish + stock list ------------------------------------------- */

    // Every list on the page, so both drawers can be cleared and filled.
    const listOf = (scope) => scope.querySelector('[data-dish-stock-list]');

    // Nothing offered and every stock back to zero.
    const clearList = (scope) => {
        const list = listOf(scope);

        if (!list) {
            return;
        }

        for (const box of list.querySelectorAll('input[name="dish_ids[]"]')) {
            box.checked = false;
        }

        for (const stock of list.querySelectorAll('input[name^="stocks["]')) {
            stock.value = '0';
        }

        // No stock was loaded from the server: a typed number is the stock itself.
        for (const loaded of list.querySelectorAll('input[name^="loaded_stocks["]')) {
            loaded.value = '';
        }

        // One event is enough: the list re-reads its own boxes on change.
        list.dispatchEvent(new Event('change', { bubbles: true }));

        // A fresh list starts unfiltered (the component listens for this).
        list.dispatchEvent(new CustomEvent('dish-list-reset'));
    };

    // Tick the dishes the session offers and put its stock in the row of each.
    //
    // A dish of the session that is no longer live is not in the list, so it
    // cannot be ticked back on: saving this form takes it out of the session.
    const fillList = (scope, dishes) => {
        const list = listOf(scope);

        if (!list) {
            return;
        }

        clearList(scope);

        for (const dish of dishes ?? []) {
            const box = list.querySelector('input[name="dish_ids[]"][value="' + dish.dish_id + '"]');
            const stock = list.querySelector('input[name="stocks[' + dish.dish_id + ']"]');
            const loaded = list.querySelector('input[name="loaded_stocks[' + dish.dish_id + ']"]');

            if (box) {
                box.checked = true;
            }

            if (stock) {
                stock.value = String(dish.available_stock ?? 0);
            }

            // Remember what was loaded: on save the server applies only the
            // difference to the stock as it is by then (customers keep ordering
            // while this drawer is open). Only the edit drawer fills a list with
            // dishes, so this is never sent from the create form.
            if (loaded) {
                loaded.value = String(dish.available_stock ?? 0);
            }
        }

        list.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const resetForm = (form) => {
        eachField(form, (field) => {
            field.value = '';
        });

        clearList(form);
    };

    const fillForm = (form, record) => {
        eachField(form, (field) => {
            // The time inputs take HH:MM, the column hands back HH:MM:SS.
            field.value = ['start_time', 'end_time'].includes(field.name)
                ? shortTime(record[field.name])
                : record[field.name] ?? '';
        });

        fillList(form, record.dishes);
    };

    /* ---- view mode ------------------------------------------------------- */

    /*
     * The switch itself lives in the Blade view; this wires it up. Cards are
     * rendered from the current page of the table's filtered rows, so search,
     * page length and pagination keep working unchanged in both views. The
     * calendar shows every filtered row (it ignores the page), so the paging
     * controls are hidden while it is up. The
     * action buttons inside `row.action` already carry `data-row-action` and
     * their URLs, so the delegated listener below serves them from cards
     * exactly as it does from table rows.
     */
    const cardsElement = document.querySelector('#order-sessions-cards');
    const calendarElement = document.querySelector('#order-sessions-calendar');
    const calendarGrid = document.querySelector('#calendar-grid');
    const calendarWeekdays = document.querySelector('#calendar-weekdays');
    const calendarTitle = document.querySelector('#calendar-title');
    const viewButtons = Array.from(document.querySelectorAll('[data-view-mode]'));
    const VIEW_STORAGE_KEY = 'cucina.viewMode.orderSessions';

    // Phones start on the card grid: the table cannot breathe in 375px. A view
    // the user picked themselves is remembered and always wins over this default.
    const storedView = (() => {
        try {
            return localStorage.getItem(VIEW_STORAGE_KEY);
        } catch (error) {
            return null;
        }
    })();

    const phoneLayout = window.matchMedia('(max-width: 639px)').matches;

    let viewMode = ['card', 'table', 'calendar'].includes(storedView)
        ? storedView
        : (phoneLayout ? 'card' : 'table');

    // No empty state until the first payload has actually been drawn.
    let feedLoaded = false;

    const cardTemplate = (session) => `
        <article class="flex flex-col justify-between overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-start justify-between gap-2 border-b border-gray-100 p-4 dark:border-gray-700/60">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">${escapeHtml(prettyDate(session.order_date))}</p>
                    <p class="text-xs tabular-nums text-gray-500 dark:text-gray-400">${escapeHtml(windowOf(session))}</p>
                </div>
                ${session.status ?? ''}
            </div>
            <dl class="flex flex-1 flex-col gap-2 p-4 text-sm">
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">Dishes</dt>
                    <dd class="tabular-nums text-gray-900 dark:text-white">${Number(session.dishes_count ?? 0)}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">Orders</dt>
                    <dd class="tabular-nums text-gray-900 dark:text-white">${Number(session.orders_count ?? 0)}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt class="text-gray-500 dark:text-gray-400">Created by</dt>
                    <dd class="truncate text-gray-900 dark:text-white">${escapeHtml(session.created_by_name ?? '—')}</dd>
                </div>
            </dl>
            <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
                ${session.action ?? ''}
            </div>
        </article>`;

    const renderCards = () => {
        if (viewMode !== 'card' || !cardsElement || !feedLoaded) {
            return;
        }

        const rows = table.rows({ page: 'current', search: 'applied' }).data().toArray();

        if (rows.length === 0) {
            cardsElement.innerHTML = '<p class="col-span-full py-8 text-center text-sm text-gray-500 dark:text-gray-400">' + escapeHtml(cardsElement.dataset.empty ?? '') + '</p>';

            return;
        }

        cardsElement.innerHTML = rows.map(cardTemplate).join('');
    };

    /* ---- the calendar ---------------------------------------------------- */

    // First day of the month on show; the month starts as the current one.
    let calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);

    const pad = (number) => String(number).padStart(2, '0');

    // 2026-11-05 from a local date (not toISOString: that is UTC and can be a day off).
    const dayKey = (date) => date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());

    // A chip is coloured by the session's status, which the server rendered
    // as a badge carrying data-status.
    const CHIP_CLASSES = {
        live: 'bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-900/40 dark:text-green-300 dark:hover:bg-green-900/60',
        scheduled: 'bg-blue-100 text-blue-800 hover:bg-blue-200 dark:bg-blue-900/40 dark:text-blue-300 dark:hover:bg-blue-900/60',
        ended: 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600',
    };

    const statusOfRow = (row) => String(row.status ?? '').match(/data-status="([^"]+)"/)?.[1] ?? 'ended';

    // The View link the server rendered in the row's actions (absent without
    // the permission, then the chip is plain text).
    const pageUrlOfRow = (row) => (String(row.action ?? '').match(/href="([^"]+)"/)?.[1] ?? '').replace(/&amp;/g, '&');

    const chipTemplate = (row) => {
        const status = statusOfRow(row);
        const orders = Number(row.orders_count ?? 0);
        const url = pageUrlOfRow(row);
        const classes = 'mt-0.5 block truncate rounded px-1 py-0.5 text-[10px] leading-4 transition sm:text-xs ' + (CHIP_CLASSES[status] ?? CHIP_CLASSES.ended);
        const label = shortTime(row.start_time) + '–' + shortTime(row.end_time);
        const title = label + ' · ' + orders + ' order' + (orders === 1 ? '' : 's') + ' · ' + status;
        const inner = '<span class="font-medium tabular-nums">' + escapeHtml(label) + '</span>'
            + '<span class="hidden tabular-nums sm:inline"> · ' + orders + '</span>';

        return url !== ''
            ? '<a href="' + escapeHtml(url) + '" title="' + escapeHtml(title) + '" class="' + classes + '">' + inner + '</a>'
            : '<span title="' + escapeHtml(title) + '" class="' + classes + '">' + inner + '</span>';
    };

    const renderCalendar = () => {
        if (viewMode !== 'calendar' || !calendarGrid || !feedLoaded) {
            return;
        }

        // Sunday-first weekday header, built once.
        if (calendarWeekdays && calendarWeekdays.childElementCount === 0) {
            const sunday = new Date(2023, 0, 1);

            calendarWeekdays.innerHTML = Array.from({ length: 7 }, (unused, index) => {
                const day = new Date(sunday.getFullYear(), sunday.getMonth(), sunday.getDate() + index);

                return '<div class="py-2">' + escapeHtml(day.toLocaleDateString('en-US', { weekday: 'short' })) + '</div>';
            }).join('');
        }

        if (calendarTitle) {
            calendarTitle.textContent = calendarMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        }

        // The filtered rows (search and status filter), grouped by their day.
        const byDay = new Map();

        for (const row of table.rows({ search: 'applied' }).data().toArray()) {
            const key = String(row.order_date ?? '').slice(0, 10);

            if (!byDay.has(key)) {
                byDay.set(key, []);
            }

            byDay.get(key).push(row);
        }

        for (const rows of byDay.values()) {
            rows.sort((a, b) => String(a.start_time).localeCompare(String(b.start_time)));
        }

        // Six whole weeks, starting on the Sunday on or before the 1st.
        const first = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), 1);
        const todayKey = dayKey(new Date());
        const cells = [];

        for (let index = 0; index < 42; index += 1) {
            const date = new Date(first.getFullYear(), first.getMonth(), 1 - first.getDay() + index);
            const key = dayKey(date);
            const inMonth = date.getMonth() === first.getMonth();
            const today = key === todayKey;
            const sessions = byDay.get(key) ?? [];

            cells.push(
                '<div class="min-h-[4.25rem] p-1 sm:min-h-[6.5rem] ' + (inMonth ? 'bg-white dark:bg-gray-800' : 'bg-gray-50 dark:bg-gray-800/60') + '">'
                + '<span class="inline-flex h-6 min-w-[1.5rem] items-center justify-center rounded-full px-1 text-xs font-medium tabular-nums '
                + (today ? 'bg-indigo-600 text-white' : (inMonth ? 'text-gray-700 dark:text-gray-200' : 'text-gray-400 dark:text-gray-500')) + '"'
                + (today ? ' aria-current="date"' : '') + '>' + date.getDate() + '</span>'
                + sessions.map(chipTemplate).join('')
                + '</div>'
            );
        }

        calendarGrid.innerHTML = cells.join('');
    };

    calendarElement?.addEventListener('click', (event) => {
        const nav = event.target.closest('[data-cal-nav]')?.dataset.calNav;

        if (!nav) {
            return;
        }

        if (nav === 'today') {
            calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
        } else {
            calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + (nav === 'next' ? 1 : -1), 1);
        }

        renderCalendar();
    });

    // `remember` is false for the automatic first paint: only a real click is
    // persisted, so a phone still starts on cards after a desktop preference.
    const applyViewMode = (mode, remember = true) => {
        viewMode = ['card', 'calendar'].includes(mode) ? mode : 'table';

        const cardMode = viewMode === 'card';
        const calendarMode = viewMode === 'calendar';

        tableElement.classList.toggle('hidden', cardMode || calendarMode);
        cardsElement?.classList.toggle('hidden', !cardMode);
        calendarElement?.classList.toggle('hidden', !calendarMode);

        // The calendar shows every row of the month, so the page length, the
        // "showing x of y" line and the pager would only mislead there.
        tableElement.closest('.dt-container')
            ?.querySelectorAll('.dt-info, .dt-length, .dt-paging')
            .forEach((element) => element.classList.toggle('hidden', calendarMode));
        viewButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.viewMode === viewMode)));

        if (calendarMode) {
            renderCalendar();
        } else if (cardMode) {
            renderCards();
        } else {
            // The columns could not be measured while the table was hidden.
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

    // DataTables wraps the table in `.dt-container`; move the card grid into
    // that container's table row so search and pagination frame both views.
    const tableRow = tableElement.closest('.dt-layout-table');

    if (cardsElement && tableRow) {
        tableRow.append(cardsElement);
    }

    if (calendarElement && tableRow) {
        tableRow.append(calendarElement);
    }

    table.on('draw', () => {
        feedLoaded = true;
        renderCards();
        renderCalendar();
    });

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => applyViewMode(button.dataset.viewMode));
    });

    applyViewMode(viewMode, false);

    /* ---- wiring ---------------------------------------------------------- */

    createButton?.addEventListener('click', () => {
        resetForm(createForm);

        // A session is usually today: start the date off there.
        if (createForm.elements['order_date']) {
            createForm.elements['order_date'].value = new Date().toLocaleDateString('en-CA');
        }

        // The start and end time come from System Configuration (the form carries them).
        if (createForm.elements['start_time'] && createForm.dataset.defaultStart) {
            createForm.elements['start_time'].value = createForm.dataset.defaultStart;
        }

        if (createForm.elements['end_time'] && createForm.dataset.defaultEnd) {
            createForm.elements['end_time'].value = createForm.dataset.defaultEnd;
        }

        showErrors(createErrors, []);
        emit('open-drawer', createForm.dataset.drawer);
    });

    // One click = one action: a button ignores further clicks for this long, so
    // hammering Edit or Delete cannot fire a burst of requests or modal opens.
    const CLICK_COOLDOWN_MS = 600;

    // Rows are injected by DataTables, so the actions are handled by delegation.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-row-action]');

        if (!button || button.disabled) {
            return;
        }

        const now = Date.now();

        if (now < Number(button.dataset.readyAt ?? 0)) {
            return;
        }

        button.dataset.readyAt = String(now + CLICK_COOLDOWN_MS);

        if (button.dataset.rowAction === 'edit') {
            // Labelled and blocked while the session is fetched, so a click
            // cannot queue behind the one already running.
            const done = busy(button);

            try {
                const response = await fetch(button.dataset.editUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));

                    showToast(payload.message ?? editForm.dataset.loadError, 'error');
                    showErrors(editErrors, messages(payload));

                    return;
                }

                editForm.action = button.dataset.updateUrl;
                fillForm(editForm, await response.json());
                showErrors(editErrors, []);
                emit('open-drawer', editForm.dataset.drawer);
            } finally {
                done();
            }

            return;
        }

        if (button.dataset.rowAction === 'end') {
            // Ending is a state change of its own, so it gets its own wording and
            // its own single-slot form; the shared confirmation sends whichever
            // form is waiting in `pendingForm`.
            if (!button.dataset.endUrl) {
                return;
            }

            endForm.action = button.dataset.endUrl;
            pendingForm = { form: endForm, errors: null };

            const subject = button.dataset.subject ?? '';

            confirmTitle.textContent = endForm.dataset.confirmTitle ?? confirmTitle.textContent;
            confirmMessage.textContent = endForm.dataset.confirmMessage ?? confirmMessage.textContent;
            confirmButton.textContent = endForm.dataset.confirmLabel ?? confirmButton.textContent;
            confirmSubject.textContent = prettyDate(subject);
            confirmSubject.classList.toggle('hidden', subject === '');

            emit('open-modal', 'edit-confirmation-modal');

            return;
        }

        if (button.dataset.rowAction === 'delete') {
            // The confirmation is already up: ignore repeat clicks on the row.
            if (deleteForm.closest('[role="dialog"]')?.getAttribute('aria-hidden') === 'false') {
                return;
            }

            deleteForm.action = button.dataset.deleteUrl;
            showErrors(deleteErrors, []);
            emit('open-modal', 'delete-confirmation-modal');
        }
    });

    // Confirmation before saving: the form waits in `pendingForm`.
    let pendingForm = null;
    let saving = false;

    [createForm, editForm].forEach((form) => {
        const errors = form === createForm ? createErrors : editErrors;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            showErrors(errors, []);
            pendingForm = { form, errors };

            const subject = form.dataset.subjectField
                ? String(form.elements[form.dataset.subjectField]?.value ?? '').trim()
                : '';

            confirmTitle.textContent = form.dataset.confirmTitle ?? confirmTitle.textContent;
            confirmMessage.textContent = form.dataset.confirmMessage ?? confirmMessage.textContent;
            confirmButton.textContent = form.dataset.confirmLabel ?? confirmButton.textContent;
            confirmSubject.textContent = subject === '' ? '' : prettyDate(subject);
            confirmSubject.classList.toggle('hidden', subject === '');

            emit('open-modal', 'edit-confirmation-modal');
        });
    });

    confirmButton.addEventListener('click', async () => {
        // A second click while the request is in flight would save twice.
        if (!pendingForm || saving) {
            return;
        }

        const { form, errors } = pendingForm;

        pendingForm = null;
        saving = true;

        const done = busy(confirmButton);

        try {
            const { ok, payload } = await send(form);

            // The confirmation stays up, and busy, until the server answers.
            emit('close-modal', 'edit-confirmation-modal');

            if (!ok) {
                // A form keeps the user's input and shows the errors in its
                // drawer; the end action has no form open to show them in.
                if (errors) {
                    showErrors(errors, messages(payload));
                } else {
                    showToast(messages(payload).join(' ') || 'The order session could not be ended.', 'error');
                }

                return;
            }

            emit('close-drawer', createForm.dataset.drawer);
            emit('close-drawer', editForm.dataset.drawer);
            showToast(payload.message);
            reload();
        } finally {
            saving = false;
            done();
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
