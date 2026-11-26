/**
 * A styled calendar for a native <input type="date">.
 *
 * The browser draws a date input's calendar itself (blue squares, the OS font), which does not
 * match the app's dropdowns. As in select-menu.js, the real input is hidden and a button +
 * panel is put in its place. The input stays the source of truth: its value is still
 * "YYYY-MM-DD", the form still posts it, `required` / `min` / `max` / `disabled` still count,
 * and picking a day fires bubbling `input` and `change`, so the page scripts listening on the
 * input keep working. A script that writes `input.value` (a drawer filling its form, "Clear
 * filters", the From / To rule) or resets the form redraws the button by itself.
 *
 * The panel has three views: the days of a month, the months of a year (click the title) and
 * a page of years (click the title again). Arrow keys, PageUp / PageDown (with Shift: a
 * year), Home / End, Enter and Escape work in the day view.
 *
 *   data-menu-size="sm"   a smaller button (a compact filter row), as on a dropdown
 *
 * Every date input in the app gets it (autoEnhanceDatePickers, called once from app.js).
 */
const icon = (classes, path) => '<svg class="' + classes + '" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + path + '"/></svg>';

const CALENDAR = icon('h-4 w-4 shrink-0 text-gray-500 dark:text-gray-400', 'M6 3v3m8-3v3M3.5 8.5h13M5 4.5h10a1.5 1.5 0 0 1 1.5 1.5v9.5A1.5 1.5 0 0 1 15 17H5a1.5 1.5 0 0 1-1.5-1.5V6A1.5 1.5 0 0 1 5 4.5Z');
const PREVIOUS = icon('h-4 w-4', 'm12 5-5 5 5 5');
const NEXT = icon('h-4 w-4', 'm8 5 5 5-5 5');
const CHEVRON = icon('h-4 w-4 shrink-0 text-gray-500 dark:text-gray-400', 'm5 8 5 5 5-5');

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

const FOCUS = 'focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-400 dark:focus-visible:ring-gray-500';
const ROUND_BUTTON = 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-gray-200 text-gray-700 transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 ' + FOCUS;
const FOOT_BUTTON = 'rounded-full border border-gray-200 px-3.5 py-1.5 text-xs font-semibold text-gray-700 transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 ' + FOCUS;

// Dates are handled as local year / month / day. `new Date('2026-11-25')` would be midnight UTC,
// which is the day before west of Greenwich.
const pad = (number) => String(number).padStart(2, '0');
const isoOf = (date) => date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());

const parse = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');

    return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
};

const addDays = (date, days) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);

// The same day of another month, or that month's last day (31 January + 1 month = 28 February).
const addMonths = (date, months) => {
    const first = new Date(date.getFullYear(), date.getMonth() + months, 1);
    const last = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();

    return new Date(first.getFullYear(), first.getMonth(), Math.min(date.getDate(), last));
};

const format = (date, options) => date.toLocaleDateString('en-US', options);

export function enhanceDatePicker(input) {
    if (!input || input.dataset.pickerReady) {
        return;
    }

    input.dataset.pickerReady = '1';

    const small = input.dataset.menuSize === 'sm';
    const labels = [...(input.labels ?? [])];
    // Read before the button goes in: a wrapping label's text would then hold the button's too.
    const name = input.getAttribute('aria-label')
        || [...(labels[0]?.childNodes ?? [])].filter((node) => node.nodeType === 3).map((node) => node.textContent).join(' ').trim()
        || labels[0]?.textContent.trim()
        || 'Date';
    // A field with no visible label ("From" / "To" in a filter panel) says which one it is.
    const placeholder = input.dataset.placeholder || input.getAttribute('aria-label') || 'Select date';
    const margins = [...input.classList].filter((item) => /^mt-/.test(item));

    const wrap = document.createElement('div');
    wrap.className = ['relative min-w-0', small ? 'min-w-[10rem]' : '', ...margins].join(' ').trim();
    input.after(wrap);

    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.className = 'flex w-full items-center justify-between gap-1.5 ' + (small ? 'rounded-full px-3.5 py-1.5' : 'rounded-xl px-3 py-2.5') + ' border border-gray-300 bg-white text-left text-sm font-normal text-gray-800 shadow-sm transition hover:border-gray-400 focus:outline-none focus:ring-4 focus:ring-gray-200 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:border-gray-500 dark:focus:ring-gray-600';
    button.innerHTML = '<span data-picker-label class="truncate"></span>' + CALENDAR;

    // After the button, so a wrapping <label> counts the button as its control. The input keeps a
    // place (bottom left of the button) for the browser's "Please fill out this field" bubble.
    wrap.append(button, input);
    input.className = 'sr-only bottom-0 left-0';
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');

    let panel = null;
    let view = 'days';
    let cursor = new Date(); // the day the keyboard is on; its month is the one shown

    const part = (selector) => panel.querySelector(selector);

    const paint = () => {
        const date = parse(input.value);
        const label = button.querySelector('[data-picker-label]');
        const text = date ? format(date, { month: 'short', day: 'numeric', year: 'numeric' }) : placeholder;

        label.textContent = text;
        label.classList.toggle('text-gray-500', !date);
        label.classList.toggle('dark:text-gray-400', !date);
        button.setAttribute('aria-label', name + ': ' + (date ? text : 'no date'));
        button.disabled = input.disabled || input.readOnly;
    };

    // "YYYY-MM-DD" compares correctly as text.
    const allowed = (iso) => (!input.min || iso >= input.min) && (!input.max || iso <= input.max);

    // Does any day from `first` to `last` fall inside min / max?
    const reachable = (first, last) => (!input.min || isoOf(last) >= input.min) && (!input.max || isoOf(first) <= input.max);

    const yearPage = () => Math.floor(cursor.getFullYear() / 12) * 12;

    // The first and last day of what is on screen, `step` screens away.
    const span = (step) => {
        if (view === 'days') {
            const first = new Date(cursor.getFullYear(), cursor.getMonth() + step, 1);

            return [first, new Date(first.getFullYear(), first.getMonth() + 1, 0)];
        }

        const year = view === 'months' ? cursor.getFullYear() + step : yearPage() + step * 12;

        return [new Date(year, 0, 1), new Date(year + (view === 'months' ? 0 : 11), 11, 31)];
    };

    const shifted = (step) => {
        if (view === 'days') {
            return addMonths(cursor, step);
        }

        return addMonths(cursor, step * (view === 'months' ? 12 : 144));
    };

    const cell = (classes, { text, label, selected, current, enabled, data }) => {
        const item = document.createElement('button');

        item.type = 'button';
        item.textContent = text;
        item.disabled = !enabled;
        item.setAttribute('aria-label', label);
        item.setAttribute('aria-pressed', selected ? 'true' : 'false');
        Object.assign(item.dataset, data);

        if (current) {
            item.setAttribute('aria-current', 'date');
        }

        // The theme's colour for the chosen one (the literal `bg-accent` class, so a gradient theme
        // paints it too); a thin ring marks today.
        let look = 'hover:bg-gray-100 dark:hover:bg-gray-700 ' + (current ? 'border-gray-400 font-semibold dark:border-gray-400' : 'border-transparent');

        if (selected) {
            look = 'border-transparent bg-accent font-semibold text-accent-on';
        }

        item.className = classes + ' border text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent ' + look + ' ' + FOCUS;

        return item;
    };

    const renderDays = (body) => {
        const year = cursor.getFullYear();
        const month = cursor.getMonth();
        const chosen = input.value;
        const today = isoOf(new Date());

        const weekdays = document.createElement('div');
        weekdays.className = 'grid grid-cols-7 text-center text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400';
        weekdays.setAttribute('aria-hidden', 'true');

        for (const day of WEEKDAYS) {
            const heading = document.createElement('span');

            heading.className = 'py-1.5';
            heading.textContent = day;
            weekdays.append(heading);
        }

        const grid = document.createElement('div');
        grid.className = 'grid grid-cols-7 gap-y-0.5';

        const start = addDays(new Date(year, month, 1), -new Date(year, month, 1).getDay());

        // Always six weeks, so the panel does not change height from month to month.
        for (let index = 0; index < 42; index++) {
            const date = addDays(start, index);
            const iso = isoOf(date);
            const item = cell('mx-auto h-9 w-9 rounded-full tabular-nums', {
                text: String(date.getDate()),
                label: format(date, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }),
                selected: iso === chosen,
                current: iso === today,
                enabled: allowed(iso),
                data: { day: iso },
            });

            if (date.getMonth() !== month && iso !== chosen) {
                item.classList.add('text-gray-400', 'dark:text-gray-500');
            }

            item.tabIndex = iso === isoOf(cursor) ? 0 : -1;
            grid.append(item);
        }

        body.append(weekdays, grid);
    };

    const renderChoices = (body) => {
        const chosen = parse(input.value);
        const today = new Date();
        const grid = document.createElement('div');

        grid.className = 'grid grid-cols-3 gap-2 py-1';

        for (let index = 0; index < 12; index++) {
            const months = view === 'months';
            const year = months ? cursor.getFullYear() : yearPage() + index;
            const first = new Date(year, months ? index : 0, 1);
            const last = new Date(year, months ? index + 1 : 12, 0);
            const same = (date) => Boolean(date) && date.getFullYear() === year && (!months || date.getMonth() === index);

            grid.append(cell('h-12 rounded-xl', {
                text: months ? format(first, { month: 'short' }) : String(year),
                label: months ? format(first, { month: 'long', year: 'numeric' }) : String(year),
                selected: same(chosen),
                current: same(today),
                enabled: reachable(first, last),
                data: months ? { month: String(index) } : { year: String(year) },
            }));
        }

        body.append(grid);
    };

    const render = () => {
        const body = part('[data-body]');
        const title = part('[data-title]');
        const step = { days: 'month', months: 'year', years: 'years' }[view];

        body.replaceChildren();

        if (view === 'days') {
            renderDays(body);
        } else {
            renderChoices(body);
        }

        title.querySelector('span').textContent = view === 'days'
            ? format(cursor, { month: 'long', year: 'numeric' })
            : (view === 'months' ? String(cursor.getFullYear()) : yearPage() + ' – ' + (yearPage() + 11));

        for (const [selector, direction, word] of [['[data-previous]', -1, 'Previous '], ['[data-next]', 1, 'Next ']]) {
            const arrow = part(selector);

            arrow.disabled = !reachable(...span(direction));
            arrow.setAttribute('aria-label', word + step);
        }

        part('[data-today]').disabled = !allowed(isoOf(new Date()));
        part('[data-clear]').hidden = input.required;
    };

    const focusCursor = () => {
        const target = view === 'days'
            ? part('[data-day="' + isoOf(cursor) + '"]')
            : part(view === 'months' ? '[data-month="' + cursor.getMonth() + '"]' : '[data-year="' + cursor.getFullYear() + '"]');

        (target && !target.disabled ? target : part('[data-title]')).focus({ preventScroll: true });
    };

    // Below the button, or above it when only there is room; on a short screen, wherever it fits.
    const place = () => {
        const rect = button.getBoundingClientRect();

        // The page was swapped (soft navigation), or the panel holding the field was closed.
        if (!wrap.isConnected || rect.width === 0) {
            close();

            return;
        }

        const gap = 6;
        const edge = 8;

        panel.style.maxHeight = (window.innerHeight - edge * 2) + 'px';

        const height = panel.offsetHeight;
        let top = rect.bottom + gap;

        if (top + height > window.innerHeight - edge) {
            top = rect.top - gap - height >= edge ? rect.top - gap - height : Math.max(edge, window.innerHeight - edge - height);
        }

        panel.style.top = top + 'px';
        panel.style.left = Math.max(edge, Math.min(rect.left, window.innerWidth - panel.offsetWidth - edge)) + 'px';
    };

    function close() {
        if (!panel) {
            return;
        }

        panel.remove();
        panel = null;
        button.setAttribute('aria-expanded', 'false');
        window.removeEventListener('resize', place);
        window.removeEventListener('scroll', place, true);
        document.removeEventListener('click', closeOnOutsideClick, true);
    }

    // Capture phase: a handler that stops the click cannot leave the calendar hanging.
    function closeOnOutsideClick(event) {
        if (!wrap.contains(event.target) && !panel?.contains(event.target)) {
            close();
        }
    }

    const commit = (value) => {
        close();
        button.focus({ preventScroll: true });

        if (value === input.value) {
            return;
        }

        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const onClick = (event) => {
        // The panel sits on <body>: to a "click outside closes this" handler (the table's filter
        // panel, the My Orders filters, a drawer) a click in the calendar must not exist.
        event.stopPropagation();

        const target = event.target.closest('button');

        if (!target || target.disabled) {
            return;
        }

        const { day, month, year } = target.dataset;

        if (day) {
            commit(day);
        } else if (target.hasAttribute('data-clear')) {
            commit('');
        } else if (target.hasAttribute('data-today')) {
            commit(isoOf(new Date()));
        } else if (month !== undefined) {
            cursor = addMonths(cursor, Number(month) - cursor.getMonth());
            view = 'days';
            render();
            focusCursor();
        } else if (year !== undefined) {
            cursor = addMonths(cursor, (Number(year) - cursor.getFullYear()) * 12);
            view = 'months';
            render();
            focusCursor();
        } else if (target.hasAttribute('data-title')) {
            view = { days: 'months', months: 'years', years: 'days' }[view];
            render();
        } else {
            cursor = shifted(target.hasAttribute('data-previous') ? -1 : 1);
            render();

            if (target.disabled) {
                part('[data-title]').focus();
            }
        }
    };

    const onKeydown = (event) => {
        if (event.key === 'Escape') {
            // Only the calendar closes, not the drawer or the filter panel behind it.
            event.preventDefault();
            event.stopPropagation();
            close();
            button.focus({ preventScroll: true });

            return;
        }

        if (event.key === 'Tab') {
            const stops = [...panel.querySelectorAll('button')].filter((item) => !item.disabled && !item.hidden && item.tabIndex !== -1);

            // Past either end: carry on from the field, as if the calendar sat right after it.
            if (event.target === stops[event.shiftKey ? 0 : stops.length - 1]) {
                button.focus({ preventScroll: true });
                close();
            }

            return;
        }

        if (!event.target.dataset.day) {
            return;
        }

        const moves = {
            ArrowLeft: () => addDays(cursor, -1),
            ArrowRight: () => addDays(cursor, 1),
            ArrowUp: () => addDays(cursor, -7),
            ArrowDown: () => addDays(cursor, 7),
            Home: () => addDays(cursor, -cursor.getDay()),
            End: () => addDays(cursor, 6 - cursor.getDay()),
            PageUp: () => addMonths(cursor, event.shiftKey ? -12 : -1),
            PageDown: () => addMonths(cursor, event.shiftKey ? 12 : 1),
        };

        if (!moves[event.key]) {
            return;
        }

        event.preventDefault();

        const next = moves[event.key]();

        if (allowed(isoOf(next))) {
            cursor = next;
            render();
            focusCursor();
        }
    };

    const open = () => {
        // Start on the chosen day, else today, pulled inside min / max.
        let start = input.value || isoOf(new Date());

        if (input.min && start < input.min) {
            start = input.min;
        }

        if (input.max && start > input.max) {
            start = input.max;
        }

        cursor = parse(start) ?? new Date();
        view = 'days';

        panel = document.createElement('div');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', name);
        // Treated like a dropdown's list by the table toolbar (a click or focus in it is "inside").
        panel.dataset.selectList = '';
        panel.dataset.datePanel = '';
        panel.className = 'fixed z-[95] w-72 overflow-auto rounded-2xl border border-gray-200 bg-white p-3 text-gray-700 shadow-card-lift dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200';
        panel.innerHTML = '<div class="mb-1 flex items-center gap-1.5">'
            + '<button type="button" data-title aria-live="polite" class="mr-auto inline-flex items-center gap-1.5 rounded-xl px-2.5 py-1.5 text-sm font-semibold text-gray-900 transition-colors hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700 ' + FOCUS + '"><span></span>' + CHEVRON + '</button>'
            + '<button type="button" data-previous class="' + ROUND_BUTTON + '">' + PREVIOUS + '</button>'
            + '<button type="button" data-next class="' + ROUND_BUTTON + '">' + NEXT + '</button>'
            + '</div><div data-body></div>'
            + '<div class="mt-2 flex items-center justify-between gap-2 border-t border-gray-100 pt-2.5 dark:border-gray-700">'
            + '<button type="button" data-clear class="' + FOOT_BUTTON + '">Clear</button>'
            + '<button type="button" data-today class="ml-auto ' + FOOT_BUTTON + '">Today</button>'
            + '</div>';
        panel.addEventListener('click', onClick);
        panel.addEventListener('keydown', onKeydown);

        document.body.append(panel);
        render();
        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        document.addEventListener('click', closeOnOutsideClick, true);
        button.setAttribute('aria-expanded', 'true');
        focusCursor();
    };

    button.addEventListener('click', () => (panel ? close() : open()));

    button.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && !panel) {
            event.preventDefault();
            open();
        } else if (event.key === 'Escape' && panel) {
            event.stopPropagation();
            close();
        }
    });

    // A click on the field's label goes to the button, without opening the calendar.
    for (const label of labels) {
        label.addEventListener('click', (event) => {
            if (!wrap.contains(event.target)) {
                event.preventDefault();
                button.focus();
            }
        });
    }

    // Writing `input.value` fires no event, so the property itself is watched.
    const native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

    Object.defineProperty(input, 'value', {
        configurable: true,
        get() {
            return native.get.call(this);
        },
        set(value) {
            native.set.call(this, value);
            paint();
        },
    });

    input.addEventListener('input', paint);
    input.addEventListener('change', paint);
    // The values are back only after the reset event has run.
    input.form?.addEventListener('reset', () => setTimeout(paint));
    new MutationObserver(paint).observe(input, { attributes: true, attributeFilter: ['disabled', 'readonly', 'value'] });

    paint();
}

export function enhanceDatePickers(root = document) {
    root.querySelectorAll('input[type="date"]').forEach(enhanceDatePicker);
}

/** Give every date input on the page the app's calendar, now and for any added later. */
export function autoEnhanceDatePickers() {
    enhanceDatePickers(document);

    new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node.nodeType !== 1) {
                    continue;
                }

                if (node.matches('input[type="date"]')) {
                    enhanceDatePicker(node);
                } else {
                    enhanceDatePickers(node);
                }
            }
        }
    }).observe(document.body, { childList: true, subtree: true });
}
