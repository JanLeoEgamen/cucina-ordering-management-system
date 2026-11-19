/**
 * System configuration page.
 *
 * One form for all settings: Save asks for confirmation (the page's shared
 * #save-modal), then sends the form as it is and reports with a toast. A
 * validation error stays on the page, with the values the user typed.
 */
import { showToast } from '../ui/toast';

const start = () => {
    const form = document.querySelector('#settings-form');
    const errors = document.querySelector('#settings-errors');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmSubject = document.querySelector('#save-modal-subject');
    const confirmButton = document.querySelector('#save-modal-button');

    // A viewer without `edit settings` gets the values read only and no Save.
    if (!form || !confirmButton) {
        return;
    }

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const showErrors = (list) => {
        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        errors.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        errors.classList.toggle('hidden', items.length === 0);
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

    /* ---- "what did I change?" ---------------------------------------------
     *
     * Every field remembers the value it was saved with. A field that differs gets an
     * "Edited" badge on its label and a line under it saying what it was, with an Undo
     * that puts the old value back; the footer counts the unsaved changes. After a
     * successful save the saved values become the new starting point.
     */
    const copy = (name, fallback) => form.dataset[name] ?? fallback;
    const summary = document.querySelector('#settings-changes');

    const controls = [...form.querySelectorAll('input:not([type="hidden"]), select')].filter((control) => control.id);

    // A picked user reads "Name · email": the name is enough to recognise it.
    const nameOf = (text) => String(text).split(' · ')[0].trim();

    // The value of a field as a comparable key, and as the words shown for "Was".
    // `fromPage`: what the server rendered (the HTML defaults), not what is typed now - a
    // reload can bring back typed values, and those are exactly the edits to point out.
    const snapshot = (control, fromPage = false) => {
        if (control.type === 'checkbox') {
            const on = fromPage ? control.defaultChecked : control.checked;

            return { key: on ? '1' : '0', text: on ? copy('labelOn', 'On') : copy('labelOff', 'Off') };
        }

        if (control.multiple) {
            const picked = [...control.options].filter((option) => (fromPage ? option.defaultSelected : option.selected));
            let text;

            if (picked.length === 0) {
                text = control.dataset.placeholder || '—';
            } else if (picked.length <= 2) {
                text = picked.map((option) => nameOf(option.textContent)).join(', ');
            } else {
                text = (control.dataset.countLabel || '{n}').replace('{n}', String(picked.length));
            }

            return { key: picked.map((option) => option.value).sort().join(','), text, values: picked.map((option) => option.value) };
        }

        const value = (fromPage ? control.defaultValue : control.value).trim();

        return { key: value, text: value !== '' ? value : (control.placeholder || '—'), value };
    };

    const baseline = new Map(controls.map((control) => [control, snapshot(control, true)]));
    const marks = new Map();

    // Put a field back to the saved value, and let the page (and the dropdown) know.
    const undo = (control) => {
        const saved = baseline.get(control);

        if (control.type === 'checkbox') {
            control.checked = saved.key === '1';
        } else if (control.multiple) {
            [...control.options].forEach((option) => {
                option.selected = saved.values.includes(option.value);
            });
        } else {
            control.value = saved.value;
        }

        control.dispatchEvent(new Event(control.tagName === 'SELECT' || control.type === 'checkbox' ? 'change' : 'input', { bubbles: true }));
    };

    // The badge and the "Was" line of one field, made the first time it changes.
    const markOf = (control) => {
        if (marks.has(control)) {
            return marks.get(control);
        }

        const label = form.querySelector('label[for="' + control.id + '"]');
        // The switch sits beside its text, so its line goes under the text, not beside it.
        const anchor = control.type === 'checkbox' ? label : (control.closest('.mt-1') ?? label);

        const badge = document.createElement('span');
        badge.className = 'ml-2 hidden rounded-full bg-gray-900 px-2 py-0.5 align-middle text-[11px] font-semibold leading-4 text-white dark:bg-mist dark:text-ink';
        badge.textContent = copy('labelEdited', 'Edited');
        label?.append(badge);

        const note = document.createElement('div');
        note.className = 'mt-2 hidden items-center gap-2 text-xs text-gray-600 dark:text-gray-300';

        const was = document.createElement('span');
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'font-semibold underline underline-offset-2 hover:text-gray-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-gray-300 dark:hover:text-white dark:focus-visible:ring-gray-600';
        button.textContent = copy('labelUndo', 'Undo');
        button.addEventListener('click', () => undo(control));

        note.append(was, button);
        anchor?.after(note);

        const mark = { badge, note, was };

        marks.set(control, mark);

        return mark;
    };

    const refreshChanges = () => {
        let count = 0;

        for (const control of controls) {
            const saved = baseline.get(control);
            const changed = snapshot(control).key !== saved.key;
            const plain = control.type !== 'checkbox' && !control.multiple;

            if (changed) {
                count++;
            } else if (!marks.has(control)) {
                continue;
            }

            const mark = markOf(control);

            mark.badge.classList.toggle('hidden', !changed);
            mark.note.classList.toggle('hidden', !changed);
            mark.note.classList.toggle('flex', changed);
            mark.was.textContent = copy('labelWas', 'Was') + ': ' + saved.text;

            // A text, number or time box also gets a ring, so the eye finds it.
            if (plain) {
                for (const name of ['ring-2', 'ring-gray-900/25', 'dark:ring-gray-300/30']) {
                    control.classList.toggle(name, changed);
                }
            }
        }

        if (summary) {
            summary.textContent = count === 1
                ? copy('labelChange', '1 unsaved change')
                : copy('labelChanges', ':n unsaved changes').replace(':n', String(count));
            summary.classList.toggle('hidden', count === 0);
        }
    };

    form.addEventListener('input', refreshChanges);
    form.addEventListener('change', refreshChanges);
    // A reload can bring typed values back: show them as edits straight away.
    refreshChanges();

    let saving = false;

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        showErrors([]);

        // The browser's own message for an empty time, before asking anything.
        if (!form.reportValidity()) {
            return;
        }

        confirmTitle.textContent = form.dataset.confirmTitle ?? confirmTitle.textContent;
        confirmMessage.textContent = form.dataset.confirmMessage ?? confirmMessage.textContent;
        confirmButton.textContent = form.dataset.confirmLabel ?? confirmButton.textContent;
        confirmSubject.textContent = '';
        confirmSubject.classList.add('hidden');

        emit('open-modal', 'edit-confirmation-modal');
    });

    confirmButton.addEventListener('click', async () => {
        // A second click while the request is in flight would save twice.
        if (saving) {
            return;
        }

        saving = true;

        const label = confirmButton.textContent;

        confirmButton.disabled = true;
        confirmButton.textContent = confirmButton.dataset.loadingLabel ?? label;

        try {
            const response = await fetch(form.action, {
                method: 'POST', // the form carries _method=PUT
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: new FormData(form),
            });

            const payload = await response.json().catch(() => ({}));

            emit('close-modal', 'edit-confirmation-modal');

            if (!response.ok) {
                showErrors(messages(payload));

                return;
            }

            // Saved: what is on the page is now the starting point for the next edit.
            controls.forEach((control) => baseline.set(control, snapshot(control)));
            refreshChanges();

            showToast(payload.message);
        } catch (error) {
            emit('close-modal', 'edit-confirmation-modal');
            showErrors(['The configuration could not be saved.']);
        } finally {
            saving = false;
            confirmButton.disabled = false;
            confirmButton.textContent = label;
        }
    });
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
