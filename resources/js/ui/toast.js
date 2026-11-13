/**
 * Toast for the AJAX responses (the Blade notification component only covers
 * full page loads).
 *
 * The only shared front end module: everything else a page needs is in its own
 * script (resources/js/pages/*.js).
 *
 * The look follows the theme (docs/frontend.md): a white rounded card that floats on the
 * paper, a round status icon (ink for success, red for an error, amber for a warning,
 * like the status dots elsewhere), a round outlined close button, and a thin bar that
 * runs down while the toast waits to go. The text is the only thing that varies.
 *
 * Kept quiet on purpose: the same message never stacks (a repeated action
 * refreshes the toast that is already up), and no more than three are ever on
 * screen, so hammering a button cannot fill the page with them.
 */
const VISIBLE_LIMIT = 3;
const LIFETIME_MS = 4000;
const LEAVE_MS = 180;

// The icon of each kind, as the inner markup of a 24px stroke svg.
const ICONS = {
    success: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    error: '<path d="M7 7l10 10M17 7 7 17"/>',
    warning: '<path d="M12 7.5v5.5m0 3.5h.01"/>',
};

// Round icon, and the colour of the countdown bar.
const TONES = {
    success: { icon: 'bg-gray-900 text-white dark:bg-mist dark:text-ink', bar: 'bg-gray-900 dark:bg-mist' },
    error: { icon: 'bg-red-600 text-white dark:bg-red-500', bar: 'bg-red-600 dark:bg-red-500' },
    warning: { icon: 'bg-amber-500 text-white', bar: 'bg-amber-500' },
};

export function showToast(message, type = 'success') {
    if (!message) {
        return;
    }

    const kind = TONES[type] ? type : 'success';

    let host = document.getElementById('cucina-toast-host');

    if (!host) {
        host = document.createElement('div');
        host.id = 'cucina-toast-host';
        // Top right: a toast never sits on top of a drawer's or a modal's
        // buttons, which are anchored to the bottom of the screen. The host lets
        // clicks through between toasts.
        host.className = 'pointer-events-none fixed right-4 top-4 z-[100] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2.5';
        document.body.appendChild(host);
    }

    // The same message is already up: let it live a little longer instead of
    // adding a second copy of it.
    const existing = [...host.children].find((toast) => toast.dataset.message === message);

    if (existing) {
        restartTimer(existing);

        return;
    }

    // Oldest first: three at a time is plenty.
    while (host.children.length >= VISIBLE_LIMIT) {
        host.firstElementChild.remove();
    }

    const toast = document.createElement('div');

    toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    toast.dataset.message = message;
    toast.className = 'pointer-events-auto relative flex animate-pop items-center gap-3 overflow-hidden rounded-2xl border border-gray-200 bg-white py-3 pl-3.5 pr-3 shadow-card-lift transition duration-200 dark:border-gray-700 dark:bg-gray-800';

    // Fixed markup; the message itself goes in as text, never as HTML.
    toast.innerHTML = '<span data-icon class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full ' + TONES[kind].icon + '">'
        + '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[kind] + '</svg>'
        + '</span>'
        + '<p data-text class="min-w-0 flex-1 text-sm font-medium leading-snug text-gray-900 dark:text-white"></p>'
        + '<button type="button" data-close aria-label="Dismiss" class="shrink-0 rounded-full border border-gray-200 p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white dark:focus:ring-gray-600">'
        + '<svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17"/></svg>'
        + '</button>'
        + '<span class="absolute inset-x-0 bottom-0 h-0.5 bg-gray-100 dark:bg-gray-700" aria-hidden="true"><span data-bar class="block h-full origin-left ' + TONES[kind].bar + '"></span></span>';

    toast.querySelector('[data-text]').textContent = message;
    toast.addEventListener('click', () => dismiss(toast));

    host.appendChild(toast);

    restartTimer(toast);
}

// Fade the toast out, then take it away.
function dismiss(toast) {
    clearTimeout(toast.timer);
    toast.classList.add('-translate-y-1', 'opacity-0');
    setTimeout(() => toast.remove(), LEAVE_MS);
}

// One timer per toast, resettable when its message repeats. The bar below the message
// runs down over the same time (a transform, so it is cheap); with reduced motion the
// transition is cut to nothing by app.css, so the bar is simply gone at once.
function restartTimer(toast) {
    clearTimeout(toast.timer);

    const bar = toast.querySelector('[data-bar]');

    if (bar) {
        bar.style.transition = 'none';
        bar.style.transform = 'scaleX(1)';
        // Force a reflow so the reset above is painted before the run starts.
        void bar.offsetWidth;
        bar.style.transition = 'transform ' + LIFETIME_MS + 'ms linear';
        bar.style.transform = 'scaleX(0)';
    }

    toast.timer = setTimeout(() => dismiss(toast), LIFETIME_MS);
}
