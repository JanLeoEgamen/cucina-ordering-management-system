/**
 * A styled dropdown for a native <select>.
 *
 * The browser draws the open list of a <select> itself (a blue bar, the OS font), which
 * does not match the rest of the app. This hides the real select and puts a button +
 * listbox in its place. The select stays the source of truth: choosing an option sets
 * `select.value` and fires a bubbling `change`, so the page scripts that listen on the
 * select (and "Clear filters", which sets the value and fires `change`) keep working.
 * Options added later (a filter filled by the page) are picked up by a MutationObserver.
 *
 * The open list is the same panel as the toolbar's sort menu: a rounded card, roomy
 * rows, the chosen row shaded and bold with a check on the right, a lighter shade
 * under the pointer. Every <select> in the app gets it (see autoEnhanceSelects), so
 * no page shows the browser's own list.
 *
 * A <select multiple> becomes a checkbox list: each row toggles its option and the list
 * stays open, so several can be ticked in a row. The button reads the placeholder
 * (data-placeholder, "All dish types") when nothing is ticked, the names when one or two
 * are, and data-count-label ("{n} dish types") beyond that. The select's selected
 * options are the value, and every toggle fires a bubbling `change`.
 *
 * Two optional looks, picked up from the select itself:
 *   data-menu-size="sm"        a smaller button (inside a drawer's compact filter row)
 *   data-menu-placement="top"  the list opens upward (the table footer's page length,
 *                              which sits at the bottom of the card; it is chosen
 *                              automatically for a select inside .dt-length)
 */
const CHEVRON = '<svg class="h-4 w-4 shrink-0 text-gray-500 transition-transform duration-200 dark:text-gray-400" data-chevron viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>';
const CHECK = '<svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg>';

export function enhanceSelect(select) {
    if (!select || select.dataset.menuReady) {
        return;
    }

    select.dataset.menuReady = '1';
    select.classList.add('sr-only');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');

    const wrap = document.createElement('div');
    wrap.className = 'relative';
    select.after(wrap);
    wrap.append(select);

    const small = select.dataset.menuSize === 'sm' || Boolean(select.closest('.dt-length'));
    const upward = select.dataset.menuPlacement === 'top' || Boolean(select.closest('.dt-length'));

    const multiple = select.multiple;

    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', select.getAttribute('aria-label') || '');
    button.className = 'flex w-full items-center justify-between gap-2 ' + (small ? 'rounded-full px-3.5 py-1.5' : 'rounded-xl px-3.5 py-2.5') + ' border border-gray-300 bg-white text-left text-sm text-gray-800 shadow-sm transition hover:border-gray-400 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:border-gray-500 dark:focus:ring-gray-600';
    button.innerHTML = '<span data-menu-label class="truncate"></span>' + CHEVRON;

    const list = document.createElement('ul');
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-multiselectable', multiple ? 'true' : 'false');
    list.hidden = true;
    list.className = 'absolute z-50 max-h-60 overflow-auto rounded-2xl border border-gray-200 bg-white p-2 shadow-card-lift dark:border-gray-700 dark:bg-gray-800 '
        + (small ? 'min-w-full w-max left-0 ' : 'inset-x-0 ')
        + (upward ? 'bottom-full mb-1.5' : 'top-full mt-1.5');

    wrap.append(button, list);

    let active = -1;

    const items = () => [...list.children];

    const isOn = (index) => (multiple ? Boolean(select.options[index]?.selected) : index === select.selectedIndex);

    const labelText = () => {
        if (!multiple) {
            return select.options[select.selectedIndex]?.textContent ?? '';
        }

        const picked = [...select.selectedOptions];

        if (picked.length === 0) {
            return select.dataset.placeholder || select.getAttribute('aria-label') || '';
        }

        if (picked.length <= 2) {
            return picked.map((option) => option.textContent).join(', ');
        }

        return (select.dataset.countLabel || '{n} selected').replace('{n}', String(picked.length));
    };

    const paint = () => {
        const label = button.querySelector('[data-menu-label]');

        label.textContent = labelText();
        // A multi select with nothing ticked reads as a placeholder.
        label.classList.toggle('text-gray-500', multiple && select.selectedOptions.length === 0);
        label.classList.toggle('dark:text-gray-400', multiple && select.selectedOptions.length === 0);
        restyle();
    };

    // The chosen row is shaded and bold with a check; the row under the pointer or
    // the keyboard is shaded lighter (and never both, so the two shades cannot fight).
    const restyle = () => {
        items().forEach((li, i) => {
            const on = isOn(i);
            const hot = i === active && !on;

            li.setAttribute('aria-selected', on ? 'true' : 'false');
            li.classList.toggle('bg-gray-100', on);
            li.classList.toggle('dark:bg-gray-700', on);
            li.classList.toggle('font-semibold', on);
            li.classList.toggle('text-gray-900', on);
            li.classList.toggle('dark:text-white', on);
            li.classList.toggle('bg-gray-50', hot);
            li.classList.toggle('dark:bg-gray-700/50', hot);
            li.querySelector('[data-check]').style.visibility = on ? 'visible' : 'hidden';

            // The tick box of a checkbox list fills in when its row is ticked.
            const box = li.querySelector('[data-box]');

            if (box) {
                for (const name of ['bg-gray-900', 'border-gray-900', 'dark:bg-mist', 'dark:border-mist', 'dark:text-ink']) {
                    box.classList.toggle(name, on);
                }

                for (const name of ['border-gray-300', 'dark:border-gray-500']) {
                    box.classList.toggle(name, !on);
                }
            }
        });
    };

    const build = () => {
        list.innerHTML = '';
        [...select.options].forEach((option, i) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.dataset.index = String(i);
            li.className = 'flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-gray-700 transition-colors dark:text-gray-200 ' + (multiple ? '' : 'justify-between');
            li.innerHTML = multiple
                ? '<span data-box class="flex h-5 w-5 shrink-0 items-center justify-center rounded-md border text-white transition-colors"><span data-check>' + CHECK + '</span></span><span data-text class="truncate"></span>'
                : '<span data-text class="truncate"></span><span data-check>' + CHECK + '</span>';
            li.querySelector('[data-text]').textContent = option.textContent;
            list.append(li);
        });
        paint();
    };

    const highlight = (index) => {
        active = index;
        restyle();
        items()[index]?.scrollIntoView({ block: 'nearest' });
    };

    const close = () => {
        list.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        button.querySelector('[data-chevron]').classList.remove('rotate-180');
    };

    const open = () => {
        build();
        list.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        button.querySelector('[data-chevron]').classList.add('rotate-180');
        highlight(Math.max(multiple ? [...select.options].findIndex((option) => option.selected) : select.selectedIndex, 0));
    };

    const choose = (index) => {
        if (multiple) {
            // Tick or untick, and stay open for the next one.
            select.options[index].selected = !select.options[index].selected;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            paint();
            button.focus();

            return;
        }

        if (index !== select.selectedIndex) {
            select.selectedIndex = index;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        paint();
        close();
        button.focus();
    };

    button.addEventListener('click', () => (list.hidden ? open() : close()));

    list.addEventListener('click', (event) => {
        const li = event.target.closest('li');
        if (li) {
            choose(Number(li.dataset.index));
        }
    });

    list.addEventListener('mousemove', (event) => {
        const li = event.target.closest('li');
        if (li && Number(li.dataset.index) !== active) {
            highlight(Number(li.dataset.index));
        }
    });

    button.addEventListener('keydown', (event) => {
        const count = select.options.length;

        if (list.hidden) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                open();
            }
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlight(Math.min(active + 1, count - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlight(Math.max(active - 1, 0));
        } else if (event.key === 'Home') {
            event.preventDefault();
            highlight(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            highlight(count - 1);
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            choose(active);
        } else if (event.key === 'Escape') {
            // The toolbar closes its own panel on Escape; this only closes the list.
            event.stopPropagation();
            close();
        } else if (event.key === 'Tab') {
            close();
        }
    });

    document.addEventListener('click', (event) => {
        if (!wrap.contains(event.target)) {
            close();
        }
    });

    // The page or "Clear filters" changed the value, or filled the options.
    select.addEventListener('change', paint);
    new MutationObserver(build).observe(select, { childList: true });

    build();
}

export function enhanceSelects(root = document) {
    root.querySelectorAll('select').forEach(enhanceSelect);
}

/**
 * Give every <select> on the page the app's dropdown, now and for any added later
 * (a drawer, the DataTables page-length box, a filter). Called once from app.js.
 * Already enhanced selects are skipped, so the toolbar's own call does no harm.
 */
export function autoEnhanceSelects() {
    enhanceSelects(document);

    new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node.nodeType !== 1) {
                    continue;
                }

                if (node.matches('select')) {
                    enhanceSelect(node);
                } else {
                    enhanceSelects(node);
                }
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
}
