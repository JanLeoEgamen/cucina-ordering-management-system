/**
 * Bulk sessions list page (staff).
 *
 * One self contained script: table, drawer wiring, confirmations, requests. The
 * same flow as resources/js/pages/order-sessions.js without the card and calendar
 * views, and without stock boxes (a bulk session has no stock). Everything the
 * page needs - copy, URLs - comes from the Blade view through `data-*`.
 *
 * Flow:
 *   #create-bulk-session         -> reset the create form, open its drawer
 *   View                         -> a plain link to the bulk session page
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
    const tableElement = document.querySelector('#bulk-sessions-table');
    const createButton = document.querySelector('#create-bulk-session');
    const createForm = document.querySelector('#create-bulk-session-form');
    const createErrors = document.querySelector('#create-bulk-session-errors');
    const editForm = document.querySelector('#edit-bulk-session-form');
    const editErrors = document.querySelector('#edit-bulk-session-errors');
    const endForm = document.querySelector('#end-session-form');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmButton = document.querySelector('#save-modal-button');
    const confirmSubject = document.querySelector('#save-modal-subject');

    if (!tableElement || !createForm || !editForm) {
        console.warn('The bulk sessions page is incomplete; nothing was wired.');

        return;
    }

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

    // The title arrives escaped by the server (DataTables escapes every column that is
    // not raw), so it is printed as it is; an empty one reads as a dash.
    const titleCell = (value, type) => (type === 'display'
        ? (value ? '<span class="text-gray-900 dark:text-white">' + value + '</span>' : '<span class="text-gray-400">—</span>')
        : value ?? '');

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
            { data: 'title', className: 'hidden md:table-cell', render: titleCell },
            { data: 'min_quantity', className: 'hidden sm:table-cell', render: countCell },
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

    // One named search narrows the feed without a request: the rows are already
    // in the browser, so this only decides which of them the table shows.
    const statusFilter = document.querySelector('#session-status-filter');

    table.search.fixed('filters', (searchData, rowData) => {
        const status = statusFilter?.value ?? '';

        // The server renders the badge, and it carries its state in data-status.
        return status === '' || String(rowData.status ?? '').includes('data-status="' + status + '"');
    });

    statusFilter?.addEventListener('change', () => table.draw());

    /* ---- the dish list --------------------------------------------------- */

    const listOf = (scope) => scope.querySelector('[data-dish-stock-list]');

    // Nothing offered.
    const clearList = (scope) => {
        const list = listOf(scope);

        if (!list) {
            return;
        }

        for (const box of list.querySelectorAll('input[name="dish_ids[]"]')) {
            box.checked = false;
        }

        // One event is enough: the list re-reads its own boxes on change.
        list.dispatchEvent(new Event('change', { bubbles: true }));

        // A fresh list starts unfiltered (the component listens for this).
        list.dispatchEvent(new CustomEvent('dish-list-reset'));
    };

    // Tick the dishes the session offers. A dish of the session that is no longer
    // live in the masterlist is not in the list: saving takes it out of the session.
    const fillList = (scope, dishes) => {
        const list = listOf(scope);

        if (!list) {
            return;
        }

        clearList(scope);

        for (const dish of dishes ?? []) {
            const box = list.querySelector('input[name="dish_ids[]"][value="' + dish.dish_id + '"]');

            if (box) {
                box.checked = true;
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

        if (createForm.elements['min_quantity'] && createForm.dataset.defaultMin) {
            createForm.elements['min_quantity'].value = createForm.dataset.defaultMin;
        }

        showErrors(createErrors, []);
        emit('open-drawer', createForm.dataset.drawer);
    });

    // One click = one action: a button ignores further clicks for this long, so
    // hammering Edit or Delete cannot fire a burst of requests or modal opens.
    const CLICK_COOLDOWN_MS = 600;

    // Confirmation before saving: the form waits in `pendingForm`.
    let pendingForm = null;
    let saving = false;

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
                    showToast(messages(payload).join(' ') || 'The bulk session could not be ended.', 'error');
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
