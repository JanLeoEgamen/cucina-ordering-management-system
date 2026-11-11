/**
 * Toast for the AJAX responses (the Blade notification component only covers
 * full page loads).
 *
 * The only shared front end module: everything else a page needs is in its own
 * script (resources/js/pages/*.js).
 *
 * Kept quiet on purpose: the same message never stacks (a repeated action
 * refreshes the toast that is already up), and no more than three are ever on
 * screen, so hammering a button cannot fill the page with them.
 */
const VISIBLE_LIMIT = 3;
const LIFETIME_MS = 3500;

export function showToast(message, type = 'success') {
    if (!message) {
        return;
    }

    let host = document.getElementById('cucina-toast-host');

    if (!host) {
        host = document.createElement('div');
        host.id = 'cucina-toast-host';
        // Top right: a toast never sits on top of a drawer's or a modal's
        // buttons, which are anchored to the bottom of the screen.
        host.className = 'fixed right-4 top-4 z-[100] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2';
        document.body.appendChild(host);
    }

    const styles = {
        success: 'border-green-500 bg-green-50 text-gray-900 dark:bg-green-900/30 dark:text-white',
        error: 'border-red-500 bg-red-50 text-gray-900 dark:bg-red-900/30 dark:text-white',
        warning: 'border-amber-500 bg-amber-50 text-gray-900 dark:bg-amber-900/30 dark:text-white',
    };

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
    toast.setAttribute('role', 'status');
    toast.dataset.message = message;
    toast.className = 'cursor-pointer rounded-lg border-l-4 p-4 text-sm font-medium shadow-lg '
        + (styles[type] ?? styles.success);
    toast.textContent = message;
    toast.addEventListener('click', () => {
        clearTimeout(toast.timer);
        toast.remove();
    });
    host.appendChild(toast);

    restartTimer(toast);
}

// One timer per toast, resettable when its message repeats.
function restartTimer(toast) {
    clearTimeout(toast.timer);

    toast.timer = setTimeout(() => toast.remove(), LIFETIME_MS);
}
