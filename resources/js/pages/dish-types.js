/**
 * Dish types list page.
 *
 * Same shape as resources/js/pages/dishes.js - table, view switch, drawer
 * wiring, confirmations, requests - with this page's columns and elements.
 */
import DataTable from 'datatables.net-dt';
import { showToast } from '../ui/toast';

const start = () => {
    const tableElement = document.querySelector('#dish-types-table');
    const createButton = document.querySelector('#create-dish-type');
    const createForm = document.querySelector('#create-dish-type-form');
    const createErrors = document.querySelector('#create-dish-type-errors');
    const editForm = document.querySelector('#edit-dish-type-form');
    const editErrors = document.querySelector('#edit-dish-type-errors');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');
    const viewErrors = document.querySelector('#view-type-errors');
    const viewName = document.querySelector('#view-type-name');
    const viewStatus = document.querySelector('#view-type-status');
    const viewDescription = document.querySelector('#view-type-description');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmButton = document.querySelector('#save-modal-button');
    const confirmSubject = document.querySelector('#save-modal-subject');

    if (!tableElement || !createForm || !editForm) {
        console.warn('The dish types page is incomplete; nothing was wired.');

        return;
    }

    /* ---- table ----------------------------------------------------------- */

    /*
     * Numbering cell (the dishes page has the same one): `rowIndexes` numbers
     * the rows in the current sort and filter order, continuous across pages;
     * the preDraw hook after the table rebuilds it for every draw.
     */
    let rowIndexes = new Map();

    // Cell styling lives in the renderer: columns[].className also lands on the
    // header cell, which would tint the labels.
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
        // Column 0 (#) is not orderable; the first content column sorts.
        order: [[1, 'asc']],
        // Toolbar: the search keeps the top row, the page length moves down next
        // to the "showing ..." summary, and its label drops the word "entries"
        // so the footer does not say it twice.
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
            // Same weights as the dishes table: counter and description light,
            // the name carries the row, actions right aligned.
            // On phones the counter and the long description are dropped; the
            // rest fits without wrapping every cell.
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'type_name', render: nameCell },
            { data: 'description', className: 'hidden sm:table-cell' },
            { data: 'is_active' },
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

    // An empty column reads better as a dash than as nothing at all.
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

    // Read only counterpart of fillForm: the same record, laid out as text.
    const fillView = (record) => {
        fillText(viewName, record.type_name);
        fillText(viewDescription, record.description);

        // The badge is rendered server side and travels with the record.
        if (viewStatus) {
            viewStatus.innerHTML = record.status ?? '';
        }
    };

    const fillForm = (form, record) => eachField(form, (field) => {
        if (field.type === 'checkbox') {
            field.checked = Boolean(record[field.name]);

            return;
        }

        field.value = record[field.name] ?? '';
    });

    /* ---- view mode ------------------------------------------------------- */

    /*
     * The switch itself lives in the Blade view; this wires it up. Cards are
     * rendered from the current page of the table's filtered rows, so search,
     * page length and pagination keep working unchanged in both views. The
     * Edit / Delete buttons inside `row.action` already carry `data-row-action`
     * and their URLs, so the delegated listener below serves them from cards
     * exactly as it does from table rows.
     */
    const cardsElement = document.querySelector('#dish-types-cards');
    const viewButtons = Array.from(document.querySelectorAll('[data-view-mode]'));
    const VIEW_STORAGE_KEY = 'cucina.viewMode.dish-types';

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

    let viewMode = storedView === 'card' || storedView === 'table'
        ? storedView
        : (phoneLayout ? 'card' : 'table');

    // No empty state until the first payload has actually been drawn.
    let feedLoaded = false;

    const cardTemplate = (dishType) => `
        <article class="flex flex-col justify-between rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex flex-1 flex-col gap-1 p-4">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white" title="${escapeHtml(dishType.type_name)}">${escapeHtml(dishType.type_name)}</h3>
                    ${dishType.is_active ?? ''}
                </div>
                <p class="mt-1 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">${escapeHtml(dishType.description)}</p>
            </div>
            <div class="flex justify-end gap-1 border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
                ${dishType.action ?? ''}
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

    // `remember` is false for the automatic first paint: only a real click is
    // persisted, so a phone still starts on cards after a desktop preference.
    const applyViewMode = (mode, remember = true) => {
        viewMode = mode === 'card' ? 'card' : 'table';

        const cardMode = viewMode === 'card';

        tableElement.classList.toggle('hidden', cardMode);
        cardsElement?.classList.toggle('hidden', !cardMode);

        viewButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.viewMode === viewMode)));

        if (cardMode) {
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

    table.on('draw', () => {
        feedLoaded = true;
        renderCards();
    });

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => applyViewMode(button.dataset.viewMode));
    });

    applyViewMode(viewMode, false);

    /* ---- wiring ---------------------------------------------------------- */

    createButton?.addEventListener('click', () => {
        resetForm(createForm);
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
            // loads, and fillView lays it out instead of filling a form.
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

                fillView(await response.json());
                showErrors(viewErrors, []);
                emit('open-drawer', 'view-type-drawer');
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

                editForm.action = button.dataset.updateUrl;
                fillForm(editForm, await response.json());
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
