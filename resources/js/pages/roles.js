/**
 * Roles list page.
 *
 * Same shape as resources/js/pages/dish-types.js - table, drawer wiring,
 * confirmations, requests - with this page's columns and elements, and a
 * checkbox picker for the permissions instead of a toggle.
 */
import DataTable from 'datatables.net-dt';
import { showToast } from '../ui/toast';

const start = () => {
    const tableElement = document.querySelector('#roles-table');
    const createButton = document.querySelector('#create-role');
    const createForm = document.querySelector('#create-role-form');
    const createErrors = document.querySelector('#create-role-errors');
    const editForm = document.querySelector('#edit-role-form');
    const editErrors = document.querySelector('#edit-role-errors');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');
    const viewErrors = document.querySelector('#view-role-errors');
    const viewName = document.querySelector('#view-role-name');
    const viewProtected = document.querySelector('#view-role-protected');
    const viewUsers = document.querySelector('#view-role-users');
    const viewPermissions = document.querySelector('#view-role-permissions');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmButton = document.querySelector('#save-modal-button');
    const confirmSubject = document.querySelector('#save-modal-subject');

    if (!tableElement || !createForm || !editForm) {
        console.warn('The roles page is incomplete; nothing was wired.');

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

    // The label for the protected chip comes from the view, like the rest of the
    // copy, so it stays translated.
    const protectedLabel = tableElement.dataset.protectedLabel ?? 'Protected';

    const nameCell = (value, type, row) => {
        if (type !== 'display') {
            return value ?? '';
        }

        // A protected role says so next to its name: it cannot be edited or
        // deleted, so its row only ever offers View.
        const chip = row.is_protected
            ? '<span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">' + escapeHtml(protectedLabel) + '</span>'
            : '';

        return '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(value) + '</span>' + chip;
    };

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Column 0 (#) is not orderable; the role name sorts.
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
        },
        ajax: tableElement.dataset.endpoint,
        columns: [
            // Same weights as the other tables: the counter is light, the name
            // carries the row, the counts sit centred, actions right aligned.
            // On phones the counter and the user count are dropped.
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'name', render: nameCell },
            { data: 'permissions_count', className: 'text-center' },
            { data: 'users_count', className: 'hidden text-center sm:table-cell' },
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

    // The permission names are printed as the same chips the tables use.
    const chips = (names) => {
        if (!names || names.length === 0) {
            return '—';
        }

        return '<span class="flex flex-wrap gap-1">'
            + names.map((name) => '<span class="inline-flex items-center rounded-md border border-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">' + escapeHtml(name) + '</span>').join('')
            + '</span>';
    };

    // The picker's checkboxes are named `permissions[]` and are the truth for
    // the selection, so filling and clearing goes through them.
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
                fillText(viewUsers, record.users_count);

                // The chip only shows for a protected role.
                viewProtected?.classList.toggle('hidden', ! record.is_protected);

                if (viewPermissions) {
                    viewPermissions.innerHTML = chips(record.permission_names ?? []);
                }

                showErrors(viewErrors, []);
                emit('open-drawer', 'view-role-drawer');
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
                fillPicker(editForm, record.permissions);
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

    // Delete (a hard delete on the server: the roles table has no deleted_at).
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
