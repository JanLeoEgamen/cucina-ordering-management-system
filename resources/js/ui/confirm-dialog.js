/**
 * A small "are you sure?" dialog, for scripts that cannot render a Blade <x-ui.modal>.
 *
 *   const yes = await confirmDialog({ title, text, yes: 'Export', no: 'Cancel' });
 *
 * Resolves true when the person confirms, false on Cancel, the X, Escape or a click on the
 * dark area.
 *
 * It is built to look and move EXACTLY like <x-ui.modal> (the Verify / Reject / Undo payment
 * confirmations use that one): the same blurred backdrop that fades in over 300 ms, the same
 * panel that scales up from 95 % over 200 ms, the same header with a title and an X, the
 * same footer with a pill Cancel and a pill primary button. Change one, change the other.
 * Reduced motion needs nothing here: app.css cuts every transition to nothing.
 *
 * The texts come from the caller (the Blade view translates them); nothing is written here.
 * The element is built on the first call and reused. Focus goes to Cancel (so Enter never
 * confirms by accident), Tab stays inside, and focus returns to what opened it.
 */
const CLOSE_AFTER = 200;

const cancelClasses = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
const yesClasses = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800';
const closeClasses = 'rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white';

let root = null;
let settle = null;
let opener = null;
let hideTimer = null;

const parts = () => ({
    backdrop: root.querySelector('[data-confirm-backdrop]'),
    panel: root.querySelector('[data-confirm-panel]'),
    title: root.querySelector('[data-confirm-title]'),
    text: root.querySelector('[data-confirm-text]'),
    yes: root.querySelector('[data-confirm-yes]'),
    no: root.querySelector('[data-confirm-no]'),
    close: root.querySelector('[data-confirm-close]'),
});

const isOpen = () => root !== null && root.dataset.open === 'true';

const finish = (answer) => {
    if (!isOpen()) {
        return;
    }

    const { backdrop, panel } = parts();

    root.dataset.open = 'false';

    // The same two closing moves as x-ui.modal: the backdrop fades, the panel shrinks back.
    backdrop.classList.remove('opacity-100');
    backdrop.classList.add('opacity-0', 'pointer-events-none');
    panel.classList.remove('!opacity-100', '!scale-100', '!pointer-events-auto');
    panel.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('overflow-hidden');

    // Once it has faded, take it out of the tab order and the screen readers' reach.
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => root.classList.add('invisible'), CLOSE_AFTER);

    opener?.focus?.();
    opener = null;

    const done = settle;

    settle = null;
    done?.(answer);
};

const build = () => {
    const element = document.createElement('div');

    element.className = 'invisible';
    element.dataset.open = 'false';

    element.innerHTML = ''
        + '<div data-confirm-backdrop aria-hidden="true" class="pointer-events-none fixed inset-0 z-[80] bg-gray-900/60 opacity-0 backdrop-blur-sm transition-opacity duration-300 ease-out"></div>'
        + '<div class="pointer-events-none fixed inset-0 z-[90] flex items-center justify-center p-4">'
        + '<div data-confirm-panel role="alertdialog" aria-modal="true" aria-hidden="true" aria-labelledby="confirm-dialog-title" aria-describedby="confirm-dialog-text"'
        + ' class="pointer-events-none max-h-full w-full max-w-md scale-95 overflow-y-auto rounded-2xl border border-gray-200 bg-white opacity-0 shadow-xl transition duration-200 ease-out dark:border-gray-700 dark:bg-gray-800">'
        + '<div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">'
        + '<h3 id="confirm-dialog-title" data-confirm-title class="text-lg font-semibold text-gray-900 dark:text-white"></h3>'
        + '<button type="button" data-confirm-close class="' + closeClasses + '">'
        + '<span class="sr-only" data-confirm-close-label></span>'
        + '<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" /></svg>'
        + '</button>'
        + '</div>'
        + '<div class="p-6"><p id="confirm-dialog-text" data-confirm-text class="text-gray-600 dark:text-gray-300"></p></div>'
        + '<div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">'
        + '<button type="button" data-confirm-no class="' + cancelClasses + '"></button>'
        + '<button type="button" data-confirm-yes class="' + yesClasses + '"></button>'
        + '</div>'
        + '</div>'
        + '</div>';

    element.addEventListener('click', (event) => {
        const target = event.target;

        if (target.closest('[data-confirm-yes]')) {
            finish(true);
        } else if (target.closest('[data-confirm-no], [data-confirm-close]') || target.matches('[data-confirm-backdrop]')) {
            finish(false);
        }
    });

    // The wrapper around the panel swallows clicks (pointer-events-none lets them fall through
    // to the backdrop), so a click on the dark area reaches the backdrop above and cancels.

    // Capture phase: Escape and Tab belong to the dialog while it is open, and nothing under
    // it (a toolbar popover, a drawer) may react to them.
    window.addEventListener('keydown', (event) => {
        if (!isOpen()) {
            return;
        }

        if (event.key === 'Escape') {
            event.stopImmediatePropagation();
            event.preventDefault();
            finish(false);

            return;
        }

        if (event.key === 'Tab') {
            const focusable = Array.from(root.querySelectorAll('button')).filter((button) => !button.disabled);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            } else if (!root.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        }
    }, true);

    document.body.appendChild(element);

    return element;
};

export function confirmDialog({ title = '', text = '', yes = 'OK', no = 'Cancel' } = {}) {
    root ??= build();

    // A second call while one is open: refuse it rather than stacking dialogs.
    if (isOpen()) {
        return Promise.resolve(false);
    }

    const { backdrop, panel, title: titleElement, text: textElement, yes: yesButton, no: noButton } = parts();

    titleElement.textContent = title;
    textElement.textContent = text;
    yesButton.textContent = yes;
    noButton.textContent = no;
    root.querySelector('[data-confirm-close-label]').textContent = no;

    opener = document.activeElement;

    clearTimeout(hideTimer);
    root.classList.remove('invisible');
    root.dataset.open = 'true';
    document.body.classList.add('overflow-hidden');

    return new Promise((resolve) => {
        settle = resolve;

        // One frame after it is visible, so the move from "closed" to "open" is animated.
        requestAnimationFrame(() => {
            backdrop.classList.remove('opacity-0', 'pointer-events-none');
            backdrop.classList.add('opacity-100');
            panel.classList.add('!opacity-100', '!scale-100', '!pointer-events-auto');
            panel.setAttribute('aria-hidden', 'false');

            // The safe choice has focus: Enter does not export by accident.
            noButton.focus();
        });
    });
}
