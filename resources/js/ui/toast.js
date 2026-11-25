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
 * Built to be noticed, not to nag:
 *   - it stays long enough to read (errors and warnings longer than successes), and the
 *     countdown PAUSES while the pointer or the keyboard focus is on it;
 *   - it glides in from the right, and on the way out it fades and folds its height away,
 *     so the toasts under it slide up instead of jumping;
 *   - toasts that arrive together are shown one after another (GAP_MS apart), not all in
 *     the same frame, so each one gets its moment;
 *   - the same message never stacks (a repeated action refreshes the toast that is
 *     already up), and no more than three are ever on screen.
 *
 * `onShow` (third argument) runs the moment the toast really appears, after any wait in
 * the line: the bell uses it to swing in step with each toast.
 */
const VISIBLE_LIMIT = 3;
const LIFETIME_MS = { success: 7000, error: 9000, warning: 9000 };
const LEAVE_MS = 300;
const GAP_MS = 700;

// The icon of each kind, as the inner markup of a 24px stroke svg.
const ICONS = {
    success: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    error: '<path d="M7 7l10 10M17 7 7 17"/>',
    warning: '<path d="M12 7.5v5.5m0 3.5h.01"/>',
};

// Round icon, and the colour of the countdown bar.
const TONES = {
    success: { icon: 'bg-accent text-accent-on', bar: 'bg-accent' },
    error: { icon: 'bg-red-600 text-white dark:bg-red-500', bar: 'bg-red-600 dark:bg-red-500' },
    warning: { icon: 'bg-amber-500 text-white', bar: 'bg-amber-500' },
};

// When the next toast may appear (a timestamp): toasts that arrive together queue up behind it.
let nextSlot = 0;

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

export function showToast(message, type = 'success', onShow = null) {
    if (!message) {
        return;
    }

    const now = Date.now();
    const slot = Math.max(now, nextSlot);

    // The pause lasts only while something else is in the line: a lone toast is immediate.
    nextSlot = slot + GAP_MS;

    if (slot > now) {
        setTimeout(() => render(message, type, onShow), slot - now);
    } else {
        render(message, type, onShow);
    }
}

function render(message, type, onShow) {
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

    const live = [...host.children].filter((toast) => !toast.dataset.leaving);

    // The same message is already up: let it live a little longer instead of
    // adding a second copy of it.
    const existing = live.find((toast) => toast.dataset.message === message);

    if (existing) {
        startCountdown(existing);
        onShow?.();

        return;
    }

    // Oldest first: three at a time is plenty.
    for (const old of live.slice(0, Math.max(0, live.length - VISIBLE_LIMIT + 1))) {
        dismiss(old);
    }

    const toast = document.createElement('div');

    toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    toast.dataset.message = message;
    toast.dataset.kind = kind;
    toast.className = 'pointer-events-auto relative flex animate-toast-in items-center gap-3 overflow-hidden rounded-2xl border border-gray-200 bg-white py-3 pl-3.5 pr-3 shadow-card-lift dark:border-gray-700 dark:bg-gray-800';

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

    // Reading time: the countdown stands still while the toast is being looked at.
    toast.addEventListener('mouseenter', () => toast.countdown?.pause());
    toast.addEventListener('mouseleave', () => {
        if (!toast.contains(document.activeElement)) {
            toast.countdown?.play();
        }
    });
    toast.addEventListener('focusin', () => toast.countdown?.pause());
    toast.addEventListener('focusout', () => toast.countdown?.play());

    host.appendChild(toast);

    startCountdown(toast);
    onShow?.();
}

// Fade the toast out and fold its height away, then take it away.
function dismiss(toast) {
    if (toast.dataset.leaving) {
        return;
    }

    toast.dataset.leaving = '1';
    toast.countdown?.cancel();

    if (reducedMotion() || !toast.animate) {
        toast.remove();

        return;
    }

    const box = toast.getBoundingClientRect().height;
    const folded = { height: '0px', paddingTop: '0px', paddingBottom: '0px', borderWidth: '0px', marginBottom: '-10px', opacity: 0, transform: 'translateX(28px)' };
    const leave = toast.animate(
        [{ height: box + 'px', opacity: 1, transform: 'translateX(0)' }, folded],
        { duration: LEAVE_MS, easing: 'cubic-bezier(0.4, 0, 0.2, 1)', fill: 'forwards' },
    );

    leave.onfinish = () => toast.remove();
}

// One countdown per toast, restartable when its message repeats. The bar below the message
// IS the clock: when it has run down, the toast goes. Pausing the bar pauses the toast.
function startCountdown(toast) {
    const bar = toast.querySelector('[data-bar]');

    toast.countdown?.cancel();

    toast.countdown = bar.animate(
        [{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }],
        { duration: LIFETIME_MS[toast.dataset.kind], easing: 'linear', fill: 'forwards' },
    );

    toast.countdown.onfinish = () => dismiss(toast);

    // The pointer may already be on it when the same message comes again.
    if (toast.matches(':hover') || toast.contains(document.activeElement)) {
        toast.countdown.pause();
    }
}
