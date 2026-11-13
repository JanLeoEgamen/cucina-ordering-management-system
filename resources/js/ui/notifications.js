/**
 * The notification bell (the top right corner of every page, <x-ui.account-bar>): keeps the
 * unread badge up to date, swings the bell and pops a toast when something new arrives, and
 * fills the panel under the bell with the latest few notifications.
 *
 * Loaded on every page (resources/js/app.js) but it does nothing unless the layout
 * drew a badge (`[data-notif-badge]`; the page has two bells, the phone's and the desktop's,
 * and everything below is painted on all of them). Plain AJAX polling, built to be cheap:
 *
 *   - the page itself runs no notification query: the badge is filled by the first
 *     call, which answers only the unread count and the newest id (no list);
 *   - after that one small GET about every 8 s (+ jitter, so a room full of people
 *     does not ask at the same second) with `after=<newest id seen>`; the answer
 *     carries the new ones, and almost always none (an indexed range read);
 *   - nothing is asked while the tab is hidden; coming back to it (or coming back
 *     online) asks at once;
 *   - a failed call is simply tried again on the next tick;
 *   - the panel's list is read ONLY when the panel is opened (`notif-open`, sent by the bell
 *     button), and again when something new arrives while it is open: `?recent=1`.
 */
import { showToast } from './toast';

const POLL_MS = 8000;
const MAX_TOASTS = 2;

// A notification row of the panel. Built with textContent, never innerHTML: the title and
// body are text, whatever they contain.
const buildRow = (item) => {
    const li = document.createElement('li');
    const link = document.createElement('a');

    link.href = item.open_url;
    link.className = 'flex items-start gap-3 px-4 py-3 transition hover:bg-gray-50 focus:bg-gray-50 focus:outline-none dark:hover:bg-gray-700/40 dark:focus:bg-gray-700/40'
        + (item.unread ? ' bg-gray-50/70 dark:bg-gray-700/20' : '');

    const dot = document.createElement('span');
    dot.className = 'mt-1.5 h-2 w-2 flex-shrink-0 rounded-full '
        + (item.unread ? 'bg-indigo-600 dark:bg-mist' : 'bg-transparent');
    dot.setAttribute('aria-hidden', 'true');

    const text = document.createElement('span');
    text.className = 'min-w-0 flex-1';

    const title = document.createElement('span');
    title.className = 'block text-sm '
        + (item.unread ? 'font-semibold text-gray-900 dark:text-white' : 'font-medium text-gray-700 dark:text-gray-300');
    title.textContent = item.title;
    text.append(title);

    if (item.body) {
        const body = document.createElement('span');
        body.className = 'mt-0.5 line-clamp-2 block text-xs text-gray-500 dark:text-gray-400';
        body.textContent = item.body;
        text.append(body);
    }

    const time = document.createElement('span');
    time.className = 'mt-1 block text-xs text-gray-400 dark:text-gray-500';
    time.textContent = item.time;
    text.append(time);

    link.append(dot, text);
    li.append(link);

    return li;
};

const start = () => {
    const badges = [...document.querySelectorAll('[data-notif-badge]')];

    if (badges.length === 0) {
        return;
    }

    const url = badges[0].dataset.feedUrl;
    const panels = [...document.querySelectorAll('[data-notif-panel]')];
    const icons = [...document.querySelectorAll('[data-notif-icon]')];

    // null until the first answer: the first call must not toast what was already there.
    let newest = null;
    let busy = false;
    let timer = null;
    let unreadNow = 0;
    let panelOpened = false;

    const paint = (unread) => {
        unreadNow = unread;

        for (const badge of badges) {
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.classList.toggle('hidden', unread < 1);
        }

        for (const button of document.querySelectorAll('[data-notif-read-all]')) {
            button.classList.toggle('hidden', unread < 1);
        }
    };

    // The bell swings once; the class is taken off again so the next arrival can swing it.
    const ring = () => {
        for (const icon of icons) {
            icon.classList.remove('animate-bell-ring');
            // Reading offsetWidth restarts the animation when it was still running.
            void icon.offsetWidth;
            icon.classList.add('animate-bell-ring');
            icon.addEventListener('animationend', () => icon.classList.remove('animate-bell-ring'), { once: true });
        }
    };

    const showList = (recent) => {
        for (const panel of panels) {
            const list = panel.querySelector('[data-notif-list]');
            const empty = panel.querySelector('[data-notif-empty]');
            const loading = panel.querySelector('[data-notif-loading]');

            list?.replaceChildren(...recent.map(buildRow));
            empty?.classList.toggle('hidden', recent.length > 0);
            loading?.classList.add('hidden');
        }
    };

    const request = async (query) => {
        const response = await fetch(url + query, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            cache: 'no-store',
        });

        if (!response.ok) {
            throw new Error('feed ' + response.status);
        }

        return response.json();
    };

    // The panel's list. The first time it shows "Loading..."; later it is replaced in place.
    const loadRecent = async () => {
        try {
            // No `after`: the poll alone hands out (and toasts) new ones, this only lists.
            const payload = await request('?recent=1');

            paint(Number(payload.unread ?? 0));
            showList(payload.recent ?? []);
            panelOpened = true;
        } catch (error) {
            // Offline: keep what is there (or say so on the first try); opening again retries.
            for (const loading of document.querySelectorAll('[data-notif-loading]')) {
                loading.textContent = loading.dataset.failedText ?? loading.textContent;
            }
        }
    };

    const panelIsOpen = () => panels.some((panel) => panel.offsetParent !== null);

    const refresh = async () => {
        if (!url || busy || document.hidden) {
            return;
        }

        busy = true;

        try {
            const payload = await request(newest === null ? '' : '?after=' + newest);
            const items = payload.items ?? [];

            paint(Number(payload.unread ?? 0));

            // A toast for what arrived while this page was open (the newest ones
            // only: a long absence must not fill the screen), and the bell swings.
            for (const item of items.slice(-MAX_TOASTS)) {
                showToast(item.title, 'success');
            }

            if (items.length > 0) {
                ring();

                // The panel is open right now: show the new one in it at once.
                if (panelOpened && panelIsOpen()) {
                    loadRecent();
                }
            }

            newest = Number(payload.latest_id ?? 0);
        } catch (error) {
            // Offline or a hiccup: the next tick tries again.
        } finally {
            busy = false;
        }
    };

    const schedule = () => {
        clearTimeout(timer);

        timer = setTimeout(async () => {
            await refresh();
            schedule();
        }, POLL_MS + Math.random() * 3000);
    };

    // "Mark all as read" in the panel: one POST, then the badge and the list are repainted.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-notif-read-all]');

        if (!button) {
            return;
        }

        button.disabled = true;

        try {
            await fetch(button.dataset.readAllUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });

            paint(0);
            await loadRecent();
        } catch (error) {
            // The button comes back; the person can try again.
        } finally {
            button.disabled = false;
        }
    });

    // The bell button sends this when it opens the panel.
    document.addEventListener('notif-open', loadRecent);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            refresh();
        }
    });

    window.addEventListener('online', refresh);

    refresh();
    schedule();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
