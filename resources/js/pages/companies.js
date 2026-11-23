/**
 * Companies list page.
 *
 * Same shape as resources/js/pages/payment-methods.js (table, drawers, confirmations,
 * requests) without the QR upload and the card view: a company is a name and a switch.
 * Cell markup, copy and URLs come from the Blade view through `data-*` attributes.
 *
 * Flow:
 *   #create-company               -> reset the create form, open its drawer
 *   [data-row-action=view]        -> GET the record, fill the view drawer
 *   [data-row-action=edit]        -> GET the record, fill the edit form, open it
 *   [data-row-action=delete]      -> delete form points at the record, open modal
 *   form submit                   -> confirmation modal (copy from the form's data-*)
 *   confirm                       -> send the form, toast, reload; failures keep the drawer open
 */
import DataTable from 'datatables.net-dt';
import { initTableToolbar } from '../ui/table-toolbar';
import { showToast } from '../ui/toast';

const start = () => {
    const tableElement = document.querySelector('#companies-table');
    const createButton = document.querySelector('#create-company');
    const createForm = document.querySelector('#create-company-form');
    const createErrors = document.querySelector('#create-company-errors');
    const editForm = document.querySelector('#edit-company-form');
    const editErrors = document.querySelector('#edit-company-errors');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');
    const viewErrors = document.querySelector('#view-company-errors');
    const viewName = document.querySelector('#view-company-name');
    const viewProtected = document.querySelector('#view-company-protected');
    const viewUsers = document.querySelector('#view-company-users');
    const viewStatus = document.querySelector('#view-company-status');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmButton = document.querySelector('#save-modal-button');
    const confirmSubject = document.querySelector('#save-modal-subject');

    if (!tableElement || !createForm || !editForm) {
        console.warn('The companies page is incomplete; nothing was wired.');

        return;
    }

    /* ---- helpers --------------------------------------------------------- */

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

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

    // An empty value reads better as a dash than as nothing at all.
    const fillText = (element, value) => {
        if (!element) {
            return;
        }

        element.textContent = value === null || value === undefined || value === '' ? '—' : value;
    };

    const eachField = (form, callback) => {
        for (const field of form.elements) {
            if (!field.name || field.name.startsWith('_')) continue;          // _token, _method
            if (['hidden', 'submit', 'button', 'reset', 'file'].includes(field.type)) continue;

            callback(field);
        }
    };

    const resetForm = (form) => eachField(form, (field) => {
        if (field.type === 'checkbox') {
            field.checked = field.defaultChecked;

            return;
        }

        field.value = '';
    });

    const fillForm = (form, record) => eachField(form, (field) => {
        if (field.type === 'checkbox') {
            field.checked = Boolean(record[field.name]);

            return;
        }

        field.value = record[field.name] ?? '';
    });

    // Read only counterpart of fillForm: the same record, laid out as text.
    const fillView = (record) => {
        fillText(viewName, record.company_name);
        fillText(viewUsers, record.users_count ?? 0);

        // The chip only shows for a protected company.
        viewProtected?.classList.toggle('hidden', !record.is_protected);

        // The badge is rendered server side and travels with the record.
        if (viewStatus) {
            viewStatus.innerHTML = record.status ?? '';
        }
    };

    /* ---- table ----------------------------------------------------------- */

    let rowIndexes = new Map();

    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    // The label for the protected chip comes from the view, like the rest of the copy.
    const protectedLabel = tableElement.dataset.protectedLabel ?? 'Protected';

    // A protected company says so next to its name: it cannot be edited or deleted, so its row
    // only ever offers View.
    const nameCell = (value, type, row) => (type === 'display'
        ? '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(value) + '</span>'
            + (row.is_protected
                ? '<span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">' + escapeHtml(protectedLabel) + '</span>'
                : '')
        : value ?? '');

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Column 0 (#) is not orderable; the name sorts.
        order: [[1, 'asc']],
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
            // `hidden sm:table-cell` hides the column on phones: className is applied to the
            // header cell as well, so both disappear together.
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'company_name', render: nameCell },
            { data: 'users_count', searchable: false, className: 'hidden tabular-nums sm:table-cell' },
            { data: 'is_active', className: 'hidden sm:table-cell' },
            { data: 'action', orderable: false, searchable: false, className: 'whitespace-nowrap text-right' },
        ],
    });

    // Search, sort and the filter dropdown above the table.
    initTableToolbar(table);

    const reload = () => table.ajax.reload(null, false);

    // Rebuild the display numbers (indexCell above) for the row order the coming draw will show.
    table.on('preDraw', () => {
        rowIndexes = new Map(
            table.rows({ search: 'applied', order: 'applied' }).indexes().toArray()
                .map((index, position) => [index, position + 1]),
        );
    });

    /* ---- filters --------------------------------------------------------- */

    // One named search narrows the feed without a request: the rows are already in the browser.
    // It is a *named* search, so the table's own search box keeps working next to it.
    const statusFilter = document.querySelector('#company-status-filter');

    table.search.fixed('filters', (searchData, rowData) => {
        const status = statusFilter?.value ?? '';

        // The server renders the badge, and it carries its state in data-status.
        return status === '' || String(rowData.is_active ?? '').includes('data-status="' + status + '"');
    });

    statusFilter?.addEventListener('change', () => table.draw());

    /* ---- wiring ---------------------------------------------------------- */

    createButton?.addEventListener('click', () => {
        resetForm(createForm);
        showErrors(createErrors, []);
        emit('open-drawer', createForm.dataset.drawer);
    });

    // One click = one action: a button ignores further clicks for this long, so hammering Edit or
    // Delete cannot fire a burst of requests or modal opens.
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

        if (button.dataset.rowAction === 'view' || button.dataset.rowAction === 'edit') {
            const editing = button.dataset.rowAction === 'edit';
            const done = busy(button);

            try {
                const response = await fetch(editing ? button.dataset.editUrl : button.dataset.viewUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    const errors = editing ? editErrors : viewErrors;

                    showToast(payload.message ?? (editing ? editForm.dataset.loadError : button.dataset.loadError), 'error');
                    showErrors(errors, messages(payload));

                    return;
                }

                const record = await response.json();

                if (editing) {
                    editForm.action = button.dataset.updateUrl;
                    fillForm(editForm, record);
                    showErrors(editErrors, []);
                    emit('open-drawer', editForm.dataset.drawer);
                } else {
                    fillView(record);
                    showErrors(viewErrors, []);
                    emit('open-drawer', 'view-company-drawer');
                }
            } finally {
                done();
            }

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
            confirmSubject.textContent = subject;
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
                // The drawer stays open with the user's input and the errors.
                showErrors(errors, messages(payload));

                return;
            }

            emit('close-drawer', form.dataset.drawer);
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

// Started by resources/js/ui/soft-nav.js on every visit (see <x-page-module>); what start() adds to
// document / window is removed when the page is left.
export default start;
