/**
 * Users list page.
 *
 * Same shape as resources/js/pages/dish-types.js - table, drawer wiring,
 * confirmations, requests - with this page's fields, and a checkbox picker for
 * the roles. A blank password on the edit form keeps the stored one.
 */
import DataTable from 'datatables.net-dt';
import { showToast } from '../ui/toast';

const start = () => {
    const tableElement = document.querySelector('#users-table');
    const createButton = document.querySelector('#create-user');
    const createForm = document.querySelector('#create-user-form');
    const createErrors = document.querySelector('#create-user-errors');
    const editForm = document.querySelector('#edit-user-form');
    const editErrors = document.querySelector('#edit-user-errors');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');
    const viewErrors = document.querySelector('#view-user-errors');
    const viewName = document.querySelector('#view-user-name');
    const viewEmail = document.querySelector('#view-user-email');
    const viewRoles = document.querySelector('#view-user-roles');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmButton = document.querySelector('#save-modal-button');
    const confirmSubject = document.querySelector('#save-modal-subject');

    if (!tableElement || !createForm || !editForm) {
        console.warn('The users page is incomplete; nothing was wired.');

        return;
    }

    /* ---- table ----------------------------------------------------------- */

    /*
     * Numbering cell (the dishes page has the same one): `rowIndexes` numbers
     * the rows in the current sort and filter order, continuous across pages;
     * the preDraw hook after the table rebuilds it for every draw.
     */
    let rowIndexes = new Map();

    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    const nameCell = (value, type) => (type === 'display'
        ? '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(value) + '</span>'
        : value ?? '');

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Column 0 (#) is not orderable; the name sorts.
        order: [[1, 'asc']],
        // Toolbar: the search keeps the top row, the page length moves down next
        // to the "showing ..." summary.
        layout: {
            // `null`, not omitted: the layout object is merged over DataTables'
            // defaults, which place the page length in the top row as well.
            topStart: null,
            topEnd: 'search',
            bottomStart: ['info', 'pageLength'],
            bottomEnd: 'paging',
        },
        language: {
            search: 'Search',
            lengthMenu: '_MENU_ per page',
            infoEmpty: 'No rows to show',
            infoFiltered: '(filtered from _MAX_ rows)',
            emptyTable: 'No data available yet',
            zeroRecords: 'No rows match the search and filters',
        },
        ajax: tableElement.dataset.endpoint,
        columns: [
            // Same weights as the other tables. On phones the counter and the
            // email are dropped: the name, the roles and the actions stay.
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'name', render: nameCell },
            { data: 'email', className: 'hidden sm:table-cell' },
            // The roles cell is rendered by the server; sorting its markup would
            // mean nothing, so only the sort arrow is dropped.
            { data: 'roles', orderable: false },
            { data: 'action', orderable: false, searchable: false, className: 'whitespace-nowrap text-right' },
        ],
    });

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

    /*
     * The role select narrows the feed without a request: the rows are already
     * in the browser, so this only decides which of them the table shows. It is
     * a *named* search, so the table's own search box keeps working next to it.
     */
    const roleFilter = document.querySelector('#user-role-filter');

    table.search.fixed('filters', (searchData, rowData) => {
        const role = roleFilter?.value ?? '';

        // The role names travel with every row, so the select matches the same
        // names the table shows as chips.
        return role === '' || (rowData.role_names ?? []).includes(role);
    });

    roleFilter?.addEventListener('change', () => table.draw());

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

    // The role names are printed as the same chips the table renders.
    const chips = (names) => {
        if (!names || names.length === 0) {
            return '—';
        }

        return '<span class="flex flex-wrap gap-1">'
            + names.map((name) => '<span class="inline-flex items-center rounded-md border border-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">' + escapeHtml(name) + '</span>').join('')
            + '</span>';
    };

    // The picker's checkboxes are named `roles[]` and are the truth for the
    // selection, so filling and clearing goes through them.
    const eachBox = (form, callback) => {
        form.querySelectorAll('[data-checkbox-picker] input[type=checkbox][name$="[]"]').forEach(callback);
    };

    // One change event on the picker root is all it needs to redraw its chips.
    const refreshPicker = (form) => {
        form.querySelectorAll('[data-checkbox-picker]').forEach((root) => root.dispatchEvent(new Event('change', { bubbles: true })));
    };

    const clearPicker = (form) => {
        eachBox(form, (box) => { box.checked = false; });
        refreshPicker(form);
    };

    const fillPicker = (form, ids) => {
        const picked = (ids ?? []).map(Number);

        eachBox(form, (box) => { box.checked = picked.includes(Number(box.value)); });
        refreshPicker(form);
    };

    // Field walker: the picker's own checkboxes are left to the functions above.
    const eachField = (form, callback) => {
        for (const field of form.elements) {
            if (!field.name || field.name.startsWith('_')) continue;          // _token, _method
            if (field.name.endsWith('[]')) continue;                          // the picker owns these
            if (['hidden', 'submit', 'button', 'reset', 'file'].includes(field.type)) continue;

            callback(field);
        }
    };

    // The password fields are cleared for the user being edited: a blank pair
    // means "keep the stored password" on the server.
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

        // Never put a stored password into the form.
        field.value = field.type === 'password' ? '' : (record[field.name] ?? '');
    });

    /* ---- wiring ---------------------------------------------------------- */

    createButton?.addEventListener('click', () => {
        resetForm(createForm);
        clearPicker(createForm);
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

        if (button.dataset.rowAction === 'view') {
            // Read only: the record comes from the same endpoint the edit drawer
            // loads, and the fields below are laid out as text instead.
            const done = busy(button);

            try {
                const response = await fetch(button.dataset.viewUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));

                    showToast(payload.message ?? button.dataset.loadError, 'error');
                    showErrors(viewErrors, messages(payload));

                    return;
                }

                const record = await response.json();

                fillText(viewName, record.name);
                fillText(viewEmail, record.email);

                if (viewRoles) {
                    viewRoles.innerHTML = chips(record.role_names ?? []);
                }

                showErrors(viewErrors, []);
                emit('open-drawer', 'view-user-drawer');
            } finally {
                done();
            }

            return;
        }

        if (button.dataset.rowAction === 'edit') {
            // Labelled and blocked while the record is fetched, so a click cannot
            // queue behind the one already running.
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

                const record = await response.json();

                editForm.action = button.dataset.updateUrl;
                fillForm(editForm, record);
                fillPicker(editForm, record.roles);
                showErrors(editErrors, []);
                emit('open-drawer', editForm.dataset.drawer);
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

    // Delete (a soft delete on the server, then the row leaves the table).
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
