/**
 * "Manage Types" drawer of the Dish Masterlist (resources/views/dishes/index.blade.php).
 *
 * Dish types have no page of their own: the drawer lists them and swaps to one
 * form that creates or edits. It runs next to resources/js/pages/dishes.js, which
 * owns the page's single #delete-modal / #save-modal, so this module binds
 * neither: it saves straight from its small form and confirms a delete inside
 * the list row. Its buttons carry `data-type-action`, not `data-row-action`, so
 * the dishes table's click handler never sees them.
 *
 * The dish form's type picker, the type filter and the Types column are drawn
 * from the page load, so after any change the page reloads once the drawer closes.
 */
import { showToast } from '../ui/toast';

const start = () => {
    const root = document.querySelector('#manage-dish-types-panel');
    const trigger = document.querySelector('#manage-dish-types');

    if (!root || !trigger) {
        return;
    }

    const list = root.querySelector('#types-list');
    const listPanel = root.querySelector('#types-list-panel');
    const form = root.querySelector('#types-form');
    const errors = root.querySelector('#types-errors');
    const title = root.querySelector('#types-title');
    const addButton = root.querySelector('#types-add');
    const backButton = root.querySelector('#types-back');
    const submit = root.querySelector('#types-submit');
    const methodField = form.querySelector('input[name="_method"]');
    const text = root.dataset;
    const listTitle = title.textContent;

    /* ---- helpers --------------------------------------------------------- */

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const messages = (payload) => {
        if (payload && payload.errors) {
            const items = Object.values(payload.errors).flat().filter(Boolean);

            if (items.length) {
                return items;
            }
        }

        return payload && typeof payload.message === 'string' ? [payload.message] : [];
    };

    const showErrors = (items) => {
        const shown = (items ?? []).filter((message) => typeof message === 'string' && message !== '');

        errors.innerHTML = shown.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        errors.classList.toggle('hidden', shown.length === 0);
    };

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const request = async (url, options = {}) => {
        try {
            const response = await fetch(url, {
                ...options,
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            const payload = await response.json().catch(() => ({}));

            return { ok: response.ok, payload };
        } catch (error) {
            console.warn('The request could not be sent.', error);

            return { ok: false, payload: {} };
        }
    };

    // Block a button and show its loading label while a request runs.
    const busy = (button) => {
        const label = button.textContent;

        button.disabled = true;
        button.textContent = button.dataset.loadingLabel ?? label;

        return () => {
            button.disabled = false;
            button.textContent = label;
        };
    };

    /* ---- sync with the page ---------------------------------------------- */

    // Set by any successful create / update / delete.
    let dirty = false;

    // The drawer closes from its X, Done or the backdrop; all of them flip aria-hidden on the panel, so watch that instead of each button.
    const panel = root.parentElement.closest('[aria-hidden]');

    if (panel) {
        new MutationObserver(() => {
            if (panel.getAttribute('aria-hidden') === 'true' && dirty) {
                window.location.reload();
            }
        }).observe(panel, { attributes: true, attributeFilter: ['aria-hidden'] });
    }

    /* ---- list ------------------------------------------------------------ */

    const rowTemplate = (type) => `
        <li data-name="${escapeHtml(type.type_name)}" class="flex items-start justify-between gap-3 px-6 py-3">
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900 dark:text-white">
                    <span class="truncate">${escapeHtml(type.type_name)}</span>
                    ${type.is_active ?? ''}
                </p>
                ${type.description ? `<p class="mt-0.5 line-clamp-2 text-xs text-gray-500 dark:text-gray-400">${escapeHtml(type.description)}</p>` : ''}
            </div>
            <div data-type-actions class="flex-shrink-0 whitespace-nowrap text-xs">${type.action ?? ''}</div>
        </li>`;

    const loadList = async () => {
        const { ok, payload } = await request(root.dataset.feedUrl);

        if (!ok) {
            showErrors(messages(payload).length ? messages(payload) : [text.loadError]);

            return;
        }

        const rows = payload.data ?? [];

        list.innerHTML = rows.length
            ? rows.map(rowTemplate).join('')
            : `<li class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">${escapeHtml(text.empty)}</li>`;
    };

    const showPanel = (name) => {
        const formVisible = name === 'form';

        listPanel.classList.toggle('hidden', formVisible);
        listPanel.classList.toggle('flex', !formVisible);
        form.classList.toggle('hidden', !formVisible);
        form.classList.toggle('flex', formVisible);
    };

    /* ---- form ------------------------------------------------------------ */

    const fill = (record) => {
        form.elements.type_name.value = record?.type_name ?? '';
        form.elements.description.value = record?.description ?? '';

        // The toggle posts a hidden 0 and a checkbox 1; only the checkbox is filled.
        form.querySelector('input[type="checkbox"][name="is_active"]').checked = record ? Boolean(record.is_active) : true;
    };

    const openForm = ({ action, edit, record }) => {
        form.action = action;
        methodField.disabled = !edit;
        title.textContent = edit ? text.titleEdit : text.titleCreate;
        submit.textContent = edit ? text.labelEdit : text.labelCreate;
        fill(record);
        showErrors([]);
        leaveConfirm();
        showPanel('form');
        form.elements.type_name.focus();
    };

    const backToList = () => {
        showErrors([]);
        title.textContent = listTitle;
        showPanel('list');
    };

    /* ---- wiring ---------------------------------------------------------- */

    trigger.addEventListener('click', async () => {
        backToList();
        emit('open-drawer', root.dataset.drawer);
        await loadList();
    });

    addButton?.addEventListener('click', () => openForm({ action: root.dataset.storeUrl, edit: false }));
    backButton.addEventListener('click', backToList);

    // Saving is confirmed first: Save swaps the buttons for "Create / Save changes to X?".
    const actions = root.querySelector('#types-form-actions');
    const confirmBar = root.querySelector('#types-confirm');
    const confirmText = root.querySelector('#types-confirm-text');
    const confirmBack = root.querySelector('#types-confirm-back');
    const confirmGo = root.querySelector('#types-confirm-go');

    const leaveConfirm = () => {
        actions.classList.remove('hidden');
        confirmBar.classList.add('hidden');
        confirmBar.classList.remove('flex');
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        // The browser's own required-field message, before asking anything.
        if (!form.reportValidity()) {
            return;
        }

        const edit = !methodField.disabled;
        const name = form.elements.type_name.value.trim();

        showErrors([]);
        confirmText.textContent = (edit ? text.confirmEdit : text.confirmCreate).replace('{name}', name);
        confirmGo.textContent = edit ? text.labelEdit : text.labelCreate;
        actions.classList.add('hidden');
        confirmBar.classList.remove('hidden');
        confirmBar.classList.add('flex');
        confirmGo.focus();
    });

    confirmBack.addEventListener('click', leaveConfirm);

    confirmGo.addEventListener('click', async () => {
        if (confirmGo.disabled) {
            return;
        }

        const done = busy(confirmGo);

        try {
            const { ok, payload } = await request(form.action, { method: 'POST', body: new FormData(form) });

            leaveConfirm();

            if (!ok) {
                // The form stays open with the input and the errors.
                showErrors(messages(payload));

                return;
            }

            dirty = true;
            showToast(payload.message);
            backToList();
            await loadList();
        } finally {
            done();
        }
    });

    // One click = one action, for the same reason dishes.js has a cooldown.
    const CLICK_COOLDOWN_MS = 600;

    list.addEventListener('click', async (event) => {
        const button = event.target.closest('button');

        if (!button || button.disabled) {
            return;
        }

        const row = button.closest('li');

        // Put the row back as it was (after Cancel, or when the server refused).
        const restoreRow = () => {
            row.innerHTML = row.dataset.original;
            row.className = row.dataset.originalClass;
        };

        // Inline delete confirmation: the row's buttons are swapped for Yes / No.
        if (button.dataset.confirmDelete !== undefined) {
            const done = busy(button);

            try {
                const body = new FormData();

                body.append('_method', 'DELETE');
                body.append('_token', csrf());

                const { ok, payload } = await request(button.dataset.confirmDelete, { method: 'POST', body });

                if (!ok) {
                    // e.g. "has associated dishes": show it and put the buttons back.
                    showErrors(messages(payload));
                    restoreRow();

                    return;
                }

                dirty = true;
                showErrors([]);
                showToast(payload.message);
                await loadList();
            } finally {
                done();
            }

            return;
        }

        if (button.dataset.cancelDelete !== undefined) {
            restoreRow();

            return;
        }

        const now = Date.now();

        if (!button.dataset.typeAction || now < Number(button.dataset.readyAt ?? 0)) {
            return;
        }

        button.dataset.readyAt = String(now + CLICK_COOLDOWN_MS);

        if (button.dataset.typeAction === 'delete') {
            showErrors([]);
            row.dataset.original = row.innerHTML;
            row.dataset.originalClass = row.className;
            row.className = 'flex flex-wrap items-center justify-between gap-3 bg-red-50 px-6 py-3 dark:bg-red-900/20';
            row.innerHTML = `
                <p class="min-w-0 flex-1 basis-40 text-sm font-medium text-red-800 dark:text-red-200">${escapeHtml(text.deletePrompt.replace('{name}', row.dataset.name ?? ''))}</p>
                <div class="flex flex-shrink-0 gap-2 text-xs">
                    <button type="button" data-cancel-delete class="rounded-full border border-gray-200 bg-white px-3 py-1.5 font-medium text-gray-700 transition hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">${escapeHtml(text.no)}</button>
                    <button type="button" data-confirm-delete="${escapeHtml(button.dataset.deleteUrl)}" class="rounded-full bg-red-600 px-3 py-1.5 font-medium text-white transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-300">${escapeHtml(text.yes)}</button>
                </div>`;
            row.querySelector('[data-confirm-delete]').focus();

            return;
        }

        if (button.dataset.typeAction === 'edit') {
            const done = busy(button);

            try {
                const { ok, payload } = await request(button.dataset.editUrl);

                if (!ok) {
                    showErrors(messages(payload).length ? messages(payload) : [text.loadError]);

                    return;
                }

                openForm({ action: button.dataset.updateUrl, edit: true, record: payload });
            } finally {
                done();
            }
        }
    });
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
