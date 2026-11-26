/**
 * The "Add Session" modal of the Order Sessions page, in three steps:
 *
 *   1 When     the date and the window. An end before the start, or a window that overlaps another
 *              open session, is said here, not after saving.
 *   2 Dishes   what is offered and how much of each (the shared dish + stock list), with one box
 *              that gives every ticked dish the same stock.
 *   3 Review   what is about to be created. Its button creates the session: this step IS the
 *              confirmation, so no second "are you sure?" is asked.
 *
 * The server judges everything again; these checks only say it earlier. A refused save goes back to
 * the step that holds the wrong field. All copy comes from the form's data-* (see the view).
 *
 * Imported by pages/order-sessions.js, which owns the requests and the dish list helpers. Everything
 * is wired on elements inside the page, so it leaves with the page.
 */
const LAST_STEP = 3;

const DOT_NOW = ['bg-accent', 'text-accent-on'];
const DOT_DONE = ['bg-indigo-600', 'text-white'];
const DOT_TODO = ['bg-gray-200', 'text-gray-600', 'dark:bg-gray-700', 'dark:text-gray-300'];

const minutes = (time) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));

export function initSessionWizard({ form, errors, send, busy, showErrors, messages, reset, conflictOf, onCreated }) {
    const modal = form.dataset.modal;
    const root = form.closest('[role="dialog"]') ?? form;
    const field = (name) => form.elements[name];

    const back = form.querySelector('[data-wizard-back]');
    const next = form.querySelector('[data-wizard-next]');
    const submit = form.querySelector('[data-wizard-submit]');
    const note = form.querySelector('[data-wizard-window]');
    const warning = form.querySelector('[data-wizard-warning]');
    const total = form.querySelector('[data-wizard-total]');
    const bulk = form.querySelector('[data-wizard-bulk]');
    const list = form.querySelector('[data-dish-stock-list]');

    let step = 1;
    let saving = false;

    const copy = (name, values = {}) => Object.entries(values)
        .reduce((text, [key, value]) => text.replace(':' + key, String(value)), form.dataset[name] ?? '');

    const longDate = (value) => new Date(value + 'T00:00:00')
        .toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

    const lasting = (start, end) => {
        const span = minutes(end) - minutes(start);
        const hours = Math.floor(span / 60);
        const rest = span % 60;

        return [hours ? hours + (hours === 1 ? ' hour' : ' hours') : '', rest ? rest + ' min' : ''].filter(Boolean).join(' ');
    };

    const ticked = () => [...list.querySelectorAll('input[name="dish_ids[]"]:checked')].map((box) => {
        const stock = form.querySelector('input[name="stocks[' + box.value + ']"]');

        return { name: box.dataset.name ?? '', stock: Math.max(0, Number(stock?.value) || 0) };
    });

    const plates = (dishes) => dishes.reduce((sum, dish) => sum + dish.stock, 0);

    /* ---- step 1: the window ------------------------------------------------ */

    // What is wrong with the window, if anything: it is shown under the fields as they are typed.
    const windowProblem = () => {
        const date = field('order_date').value;
        const start = field('start_time').value;
        const end = field('end_time').value;

        if (!date || !start || !end) {
            return { message: copy('missing'), quiet: true };
        }

        if (end <= start) {
            return { message: copy('endBeforeStart') };
        }

        const other = conflictOf(date, start, end);

        return other ? { message: copy('overlap', { window: other }) } : null;
    };

    const paintWindow = () => {
        const date = field('order_date').value;
        const start = field('start_time').value;
        const end = field('end_time').value;
        const problem = windowProblem();
        // Only a warning: the server takes a window that has passed (it is saved as ended).
        const passed = !problem && new Date(date + 'T' + end) < new Date();

        note.textContent = problem
            ? (problem.quiet ? '' : problem.message)
            : longDate(date) + ' · ' + start + ' – ' + end + ' · ' + lasting(start, end);
        note.classList.toggle('hidden', note.textContent === '');

        for (const [names, on] of [
            [['bg-red-50', 'text-red-700', 'dark:bg-red-900/30', 'dark:text-red-300'], Boolean(problem)],
            [['bg-gray-100', 'text-gray-700', 'dark:bg-gray-700/50', 'dark:text-gray-200'], !problem],
        ]) {
            names.forEach((name) => note.classList.toggle(name, on));
        }

        warning.textContent = passed ? copy('passed') : '';
        warning.classList.toggle('hidden', !passed);

        // The chip of the picked day is the highlighted one.
        form.querySelectorAll('[data-wizard-day]').forEach((chip) => {
            const on = date === dayFromNow(Number(chip.dataset.wizardDay));

            chip.setAttribute('aria-pressed', String(on));
            ['bg-accent', 'text-accent-on', 'border-transparent'].forEach((name) => chip.classList.toggle(name, on));
            ['bg-white', 'text-gray-700', 'border-gray-300', 'dark:bg-gray-700', 'dark:text-gray-200', 'dark:border-gray-600'].forEach((name) => chip.classList.toggle(name, !on));
        });
    };

    function dayFromNow(days) {
        const date = new Date();

        date.setDate(date.getDate() + days);

        // The local day as YYYY-MM-DD (toISOString would be UTC).
        return date.toLocaleDateString('en-CA');
    }

    /* ---- step 2: dishes and stock ------------------------------------------ */

    const paintTotal = () => {
        const dishes = ticked();

        total.textContent = dishes.length === 0
            ? copy('noneTicked')
            : copy('total', { dishes: dishes.length, plates: plates(dishes) });
    };

    // Every stock box is posted, ticked or not, so the server refuses any that is not a whole number.
    const dishProblem = () => {
        if ([...list.querySelectorAll('input[name^="stocks["]')].some((input) => !input.checkValidity())) {
            return copy('badStock');
        }

        return ticked().length === 0 ? copy('pickDish') : null;
    };

    const applyBulk = () => {
        const value = Number(bulk.value);

        if (bulk.value === '' || !Number.isInteger(value) || value < 0) {
            showErrors(errors, [copy('badStock')]);
            bulk.focus();

            return;
        }

        const boxes = [...list.querySelectorAll('input[name="dish_ids[]"]:checked')];

        if (boxes.length === 0) {
            showErrors(errors, [copy('tickFirst')]);

            return;
        }

        showErrors(errors, []);

        for (const box of boxes) {
            const stock = form.querySelector('input[name="stocks[' + box.value + ']"]');

            if (stock) {
                stock.value = String(value);
            }
        }

        paintTotal();
    };

    /* ---- step 3: the review ------------------------------------------------ */

    const paintReview = () => {
        const dishes = ticked();
        const empty = dishes.filter((dish) => dish.stock === 0).length;
        const set = (key, text) => {
            form.querySelector('[data-review="' + key + '"]').textContent = text;
        };
        const start = field('start_time').value;
        const end = field('end_time').value;

        set('date', longDate(field('order_date').value));
        set('window', start + ' – ' + end + ' (' + lasting(start, end) + ')');
        set('dishes', copy('total', { dishes: dishes.length, plates: plates(dishes) }));

        form.querySelector('[data-review-list]').replaceChildren(...dishes.map((dish) => {
            const item = document.createElement('li');
            const name = document.createElement('span');
            const stock = document.createElement('span');

            item.className = 'flex items-center justify-between gap-3 px-3 py-2 text-sm';
            name.className = 'min-w-0 truncate text-gray-800 dark:text-gray-200';
            name.textContent = dish.name;
            stock.className = 'shrink-0 tabular-nums ' + (dish.stock === 0 ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-gray-600 dark:text-gray-300');
            stock.textContent = String(dish.stock);
            item.append(name, stock);

            return item;
        }));

        const zero = form.querySelector('[data-review-zero]');

        zero.textContent = empty ? copy('zeroStock', { count: empty }) : '';
        zero.classList.toggle('hidden', empty === 0);

        const passed = form.querySelector('[data-review-passed]');
        const gone = new Date(field('order_date').value + 'T' + end) < new Date();

        passed.textContent = gone ? copy('passed') : '';
        passed.classList.toggle('hidden', !gone);
    };

    /* ---- moving between steps ---------------------------------------------- */

    const show = (to) => {
        step = Math.min(Math.max(to, 1), LAST_STEP);
        showErrors(errors, []);

        form.querySelectorAll('[data-wizard-step]').forEach((section) => {
            section.classList.toggle('hidden', Number(section.dataset.wizardStep) !== step);
        });

        root.querySelectorAll('[data-wizard-stepper]').forEach((item) => {
            const number = Number(item.dataset.wizardStepper);
            const dot = item.querySelector('[data-wizard-dot]');
            const name = item.querySelector('[data-wizard-name]');

            dot.classList.remove(...DOT_NOW, ...DOT_DONE, ...DOT_TODO);
            dot.classList.add(...(number === step ? DOT_NOW : number < step ? DOT_DONE : DOT_TODO));
            dot.textContent = number < step ? '✓' : String(number);

            ['font-semibold', 'text-gray-900', 'dark:text-white'].forEach((style) => name.classList.toggle(style, number === step));

            if (number === step) {
                item.setAttribute('aria-current', 'step');
            } else {
                item.removeAttribute('aria-current');
            }
        });

        back.classList.toggle('invisible', step === 1);
        next.classList.toggle('hidden', step === LAST_STEP);
        submit.classList.toggle('hidden', step !== LAST_STEP);

        if (step === 1) {
            paintWindow();
        } else if (step === 2) {
            paintTotal();
        } else {
            paintReview();
        }

        root.scrollTop = 0;
    };

    // The step holds when what it asks for is right; otherwise it says why.
    const problemOf = (number) => {
        if (number === 1) {
            return windowProblem()?.message ?? null;
        }

        return number === 2 ? dishProblem() : null;
    };

    const advance = () => {
        const problem = problemOf(step);

        if (problem) {
            showErrors(errors, [problem]);
            // The message is at the top: a scrolled modal (a phone) must show it.
            root.scrollTop = 0;

            return;
        }

        show(step + 1);
        // The button that was pressed may be gone now: keep the keyboard inside the dialog.
        (step === LAST_STEP ? submit : next).focus({ preventScroll: true });
    };

    next.addEventListener('click', advance);
    back.addEventListener('click', () => show(step - 1));

    form.querySelectorAll('[data-wizard-day]').forEach((chip) => chip.addEventListener('click', () => {
        field('order_date').value = dayFromNow(Number(chip.dataset.wizardDay));
        field('order_date').dispatchEvent(new Event('change', { bubbles: true }));
    }));

    ['order_date', 'start_time', 'end_time'].forEach((name) => {
        for (const type of ['input', 'change']) {
            field(name).addEventListener(type, () => {
                showErrors(errors, []);
                paintWindow();
            });
        }
    });

    // The list is Alpine's; these two only add comfort to it. A box that reads 0 is selected on
    // focus, so typing replaces it. A number typed on an unticked dish ticks it (the server only
    // reads the stock of ticked dishes, so it would otherwise be lost). On `change`, not while
    // typing: the "Not offered" filter would hide the row under the cursor.
    list.addEventListener('focusin', (event) => {
        if (event.target.matches('input[name^="stocks["]')) {
            event.target.select();
        }
    });

    list.addEventListener('change', (event) => {
        const input = event.target;

        if (input.matches('input[name^="stocks["]') && Number(input.value) > 0) {
            const box = form.querySelector('input[name="dish_ids[]"][value="' + input.name.slice(7, -1) + '"]');

            if (box && !box.checked) {
                box.checked = true;
                box.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });

    form.addEventListener('change', paintTotal);
    form.addEventListener('input', paintTotal);
    // Select all / Clear tick the boxes without an event.
    list.addEventListener('click', () => setTimeout(paintTotal));

    form.querySelector('[data-wizard-bulk-apply]').addEventListener('click', applyBulk);

    // Enter in a text box must not skip through the steps; in the "same stock" box it applies it.
    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && step === 2 && event.target.matches('input')) {
            event.preventDefault();

            if (event.target === bulk) {
                applyBulk();
            }
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (step < LAST_STEP) {
            advance();

            return;
        }

        // A second click while the request is in flight would create the session twice.
        if (saving) {
            return;
        }

        saving = true;

        const done = busy(submit);

        try {
            const { ok, payload } = await send(form);

            if (!ok) {
                const fields = Object.keys(payload.errors ?? {});
                const reasons = messages(payload);

                if (fields.some((name) => ['order_date', 'start_time', 'end_time'].includes(name))) {
                    show(1);
                } else if (fields.length) {
                    show(2);
                }

                showErrors(errors, reasons.length ? reasons : [copy('failed')]);
                root.scrollTop = 0;

                return;
            }

            window.dispatchEvent(new CustomEvent('close-modal', { detail: modal }));
            onCreated(payload);
        } finally {
            saving = false;
            done();
        }
    });

    return {
        // A fresh form every time, starting today with the times from System Configuration.
        open() {
            reset(form);
            field('order_date').value = dayFromNow(0);
            field('start_time').value = form.dataset.defaultStart ?? '';
            field('end_time').value = form.dataset.defaultEnd ?? '';
            bulk.value = '';
            show(1);
            window.dispatchEvent(new CustomEvent('open-modal', { detail: modal }));
        },
        // The rows the overlap check reads were refreshed.
        refresh() {
            if (step === 1) {
                paintWindow();
            }
        },
    };
}
