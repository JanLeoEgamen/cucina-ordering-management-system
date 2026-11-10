/**
 * Toast for the AJAX responses (the Blade notification component only covers
 * full page loads).
 *
 * The only shared front end module: everything else a page needs is in its own
 * script (resources/js/pages/*.js).
 */
export function showToast(message, type = 'success') {
    if (!message) {
        return;
    }

    let host = document.getElementById('cucina-toast-host');

    if (!host) {
        host = document.createElement('div');
        host.id = 'cucina-toast-host';
        host.className = 'fixed bottom-4 right-4 z-[100] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2';
        document.body.appendChild(host);
    }

    const styles = {
        success: 'border-green-500 bg-green-50 text-gray-900 dark:bg-green-900/30 dark:text-white',
        error: 'border-red-500 bg-red-50 text-gray-900 dark:bg-red-900/30 dark:text-white',
        warning: 'border-amber-500 bg-amber-50 text-gray-900 dark:bg-amber-900/30 dark:text-white',
    };

    const toast = document.createElement('div');
    toast.setAttribute('role', 'status');
    toast.className = 'cursor-pointer rounded-lg border-l-4 p-4 text-sm font-medium shadow-lg '
        + (styles[type] ?? styles.success);
    toast.textContent = message;
    toast.addEventListener('click', () => toast.remove());
    host.appendChild(toast);

    setTimeout(() => toast.remove(), 5000);
}
