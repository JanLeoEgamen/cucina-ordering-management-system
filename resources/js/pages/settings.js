/**
 * System configuration page.
 *
 * One form for all settings, shown one tab at a time. Save asks for confirmation (the page's
 * shared #save-modal), then sends the WHOLE form (hidden tabs included) and reports with a
 * toast. A validation error stays on the page, with the values the user typed.
 */
import { showToast } from '../ui/toast';

const start = ({ signal } = {}) => {
    const form = document.querySelector('#settings-form');

    /* ---- tabs ----------------------------------------------------------------
     *
     * Every panel is in the form; a hidden one is only `hidden`, so Save still sends it. The
     * open tab is kept in the URL hash (#notifications) with replaceState: a refresh or a
     * shared link lands on it, and the back button does not step through tabs. This runs
     * before the read only check below: someone without `edit settings` has tabs too.
     */
    const tabs = [...document.querySelectorAll('[data-settings-tab]')];
    const panels = [...document.querySelectorAll('[data-settings-panel]')];

    const showTab = (name, { focus = false, remember = true } = {}) => {
        if (!tabs.some((tab) => tab.dataset.settingsTab === name)) {
            return;
        }

        for (const tab of tabs) {
            const on = tab.dataset.settingsTab === name;

            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;

            if (on && focus) {
                tab.focus();
            }
        }

        panels.forEach((panel) => panel.classList.toggle('hidden', panel.dataset.settingsPanel !== name));

        if (remember) {
            try {
                history.replaceState(history.state, '', '#' + name);
            } catch (error) {
                // The tab still works without a hash.
            }
        }
    };

    // Show the tab and the (folded) block a control is in, so a field that needs attention can be seen.
    const reveal = (control) => {
        const panel = control.closest('[data-settings-panel]');

        if (panel) {
            showTab(panel.dataset.settingsPanel);
        }

        control.closest('details')?.setAttribute('open', '');
    };

    const fromHash = () => showTab(decodeURIComponent(location.hash.slice(1)), { remember: false });

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => showTab(tab.dataset.settingsTab));

        // Arrow keys move along the tabs, as a tab list does.
        tab.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];

            if (step) {
                event.preventDefault();
                showTab(tabs[(index + step + tabs.length) % tabs.length].dataset.settingsTab, { focus: true });
            } else if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                showTab(tabs[event.key === 'Home' ? 0 : tabs.length - 1].dataset.settingsTab, { focus: true });
            }
        });
    });

    window.addEventListener('hashchange', fromHash);
    fromHash();
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

    const controls = [...form.querySelectorAll('input:not([type="hidden"]), select, textarea')].filter((control) => control.id);

    // "Was: ..." for a long text (the privacy policy) is its first words, not a whole page.
    const brief = (text) => (text.length > 80 ? text.slice(0, 80).trimEnd() + '…' : text);

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

        // A dropdown has no `defaultValue`: its saved choice is the option the server marked selected
        // (else the first), and "Was" names that option in words, not its value ("both").
        if (control.tagName === 'SELECT') {
            const option = fromPage
                ? ([...control.options].find((candidate) => candidate.defaultSelected) ?? control.options[0])
                : control.selectedOptions[0];

            return { key: option?.value ?? '', text: option ? option.textContent.trim() : '—', value: option?.value ?? '' };
        }

        const value = (fromPage ? control.defaultValue : control.value).trim();

        return { key: value, text: value !== '' ? brief(value.replace(/\s+/g, ' ')) : (control.placeholder || '—'), value };
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
        } else if (control.type === 'file') {
            // A file box can only be emptied from script, never given a path.
            control.value = '';
        } else {
            control.value = saved.value;
        }

        control.dispatchEvent(new Event(['SELECT'].includes(control.tagName) || ['checkbox', 'file'].includes(control.type) ? 'change' : 'input', { bubbles: true }));
    };

    // The badge and the "Was" line of one field, made the first time it changes.
    const markOf = (control) => {
        if (marks.has(control)) {
            return marks.get(control);
        }

        const label = form.querySelector('label[for="' + control.id + '"]');
        // A setting is its words on one side and its control on the other (<x-settings.field>): the line
        // goes under the control, or under the words for a switch, which sits beside them.
        const field = control.closest('[data-field]');
        const textBox = field?.querySelector('[data-field-text]');
        const controlBox = field?.querySelector('[data-control]');

        const badge = document.createElement('span');
        badge.className = 'ml-2 hidden rounded-full bg-accent px-2 py-0.5 align-middle text-[11px] font-semibold leading-4 text-accent-on';
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

        if (control.type === 'checkbox' && textBox) {
            textBox.append(note);
        } else if (controlBox) {
            controlBox.append(note);
        } else {
            label?.after(note);
        }

        const mark = { badge, note, was };

        marks.set(control, mark);

        return mark;
    };

    const tabCounts = new Map(tabs.map((tab) => [tab.dataset.settingsTab, tab.querySelector('[data-tab-count]')]));

    const refreshChanges = () => {
        let count = 0;
        const perTab = {};

        for (const control of controls) {
            const saved = baseline.get(control);
            const changed = snapshot(control).key !== saved.key;
            const plain = control.type !== 'checkbox' && !control.multiple;

            if (changed) {
                count++;

                const tab = control.closest('[data-settings-panel]')?.dataset.settingsPanel;

                if (tab) {
                    perTab[tab] = (perTab[tab] ?? 0) + 1;
                }
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

        // A tab that holds edits says how many, so nothing changed on another tab is forgotten.
        tabCounts.forEach((badge, name) => {
            if (!badge) {
                return;
            }

            badge.textContent = String(perTab[name] ?? 0);
            badge.classList.toggle('hidden', !perTab[name]);
        });

        if (summary) {
            if (count === 0) {
                summary.textContent = copy('labelNone', 'No unsaved changes');
            } else {
                summary.textContent = count === 1
                    ? copy('labelChange', '1 unsaved change')
                    : copy('labelChanges', ':n unsaved changes').replace(':n', String(count));
            }

            // Quiet when there is nothing to save, strong when there is.
            summary.classList.toggle('font-medium', count > 0);
            summary.classList.toggle('text-gray-900', count > 0);
            summary.classList.toggle('dark:text-white', count > 0);
            summary.classList.toggle('text-gray-500', count === 0);
            summary.classList.toggle('dark:text-gray-400', count === 0);
        }
    };

    /* ---- the logo picked, shown before it is saved --------------------------
     *
     * The preview box holds the current logo (the server drew it). A picked file replaces it
     * with the file itself (an object URL: nothing is uploaded yet); emptying the box, or Undo,
     * puts the saved one back.
     */
    const logoInput = form.querySelector('#app-logo');
    const logoPreview = form.querySelector('#app-logo-preview');
    const savedLogoMarkup = logoPreview ? logoPreview.innerHTML : '';
    let previewUrl = null;

    logoInput?.addEventListener('change', () => {
        if (!logoPreview) {
            return;
        }

        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }

        const file = logoInput.files?.[0];

        if (!file) {
            logoPreview.innerHTML = savedLogoMarkup;

            return;
        }

        previewUrl = URL.createObjectURL(file);

        const image = document.createElement('img');

        image.src = previewUrl;
        image.alt = '';
        // Same shape as the logo component draws it everywhere else.
        image.className = 'aspect-square h-full w-full rounded-lg object-cover';
        logoPreview.replaceChildren(image);
    });

    /* ---- the colour theme, previewed before it is saved ---------------------------
     *
     * The server printed the saved theme as CSS variables (<style id="theme-vars">) and put the CSS of
     * EVERY theme in the swatches' data-themes. Picking one (a swatch, the dropdown, or Undo) lays that
     * theme's CSS in a second <style>, placed after the first so it wins: the whole page, menu included,
     * changes colour at once. Nothing is stored; leaving the page without saving removes it again, and a
     * save reloads the page (see below), which brings the saved theme in the normal way.
     */
    const swatchBox = form.querySelector('[data-theme-swatches]');
    const themeSelect = form.querySelector('#theme-accent');

    if (swatchBox && themeSelect) {
        let themes = {};

        try {
            themes = JSON.parse(swatchBox.dataset.themes || '{}');
        } catch (error) {
            // No preview then; the dropdown still saves.
        }

        const swatches = [...swatchBox.querySelectorAll('[data-theme-swatch]')];
        const themeName = form.querySelector('[data-theme-name]');
        const saved = baseline.get(themeSelect)?.value ?? themeSelect.value;
        let previewStyle = null;

        const showTheme = () => {
            const slug = themeSelect.value;

            swatches.forEach((swatch) => swatch.setAttribute('aria-pressed', swatch.dataset.themeSwatch === slug ? 'true' : 'false'));

            if (themeName) {
                themeName.textContent = themeSelect.selectedOptions[0]?.textContent.trim() ?? '';
            }

            if (slug === saved || !themes[slug]) {
                previewStyle?.remove();
                previewStyle = null;

                return;
            }

            if (!previewStyle) {
                previewStyle = document.createElement('style');
                previewStyle.id = 'theme-preview';
                document.head.appendChild(previewStyle);
            }

            previewStyle.textContent = themes[slug];
        };

        swatches.forEach((swatch) => swatch.addEventListener('click', () => {
            themeSelect.value = swatch.dataset.themeSwatch;
            themeSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }));

        themeSelect.addEventListener('change', showTheme);
        // A reload can bring a typed choice back: draw it straight away.
        showTheme();

        // Leaving without saving (soft navigation): the saved colours come back.
        signal?.addEventListener('abort', () => previewStyle?.remove());
    }

    form.addEventListener('input', refreshChanges);
    form.addEventListener('change', refreshChanges);
    // A reload can bring typed values back: show them as edits straight away.
    refreshChanges();

    let saving = false;

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        showErrors([]);

        // The browser's own message for an empty time, before asking anything. It cannot point at a
        // field inside a hidden tab or a folded block, so open the first wrong one first.
        const invalid = [...form.elements].find((control) => control.willValidate && !control.checkValidity());

        if (invalid) {
            reveal(invalid);
        }

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

            // The name, the logo and the colour theme are drawn on every page (sidebar, title, sign in, the
            // <head>'s colour variables): when one changed, reload once so every page shows the saved brand.
            const brandChanged = ['app-name', 'app-logo', 'remove-app-logo', 'theme-accent'].some((id) => {
                const control = form.querySelector('#' + id);

                return control && snapshot(control).key !== baseline.get(control).key;
            });

            // Saved: what is on the page is now the starting point for the next edit.
            controls.forEach((control) => baseline.set(control, snapshot(control)));
            refreshChanges();

            showToast(payload.message);

            if (brandChanged) {
                window.setTimeout(() => window.location.reload(), 900);
            }
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

// Started by resources/js/ui/soft-nav.js on every visit (see <x-page-module>); what start() adds to
// document / window is removed when the page is left.
export default start;
