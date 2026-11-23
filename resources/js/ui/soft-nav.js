/**
 * Soft navigation: moving between pages from the sidebar (and the dashboard tiles) does not
 * reload the browser. The shell stays alive (sidebar, notification bell, theme switch, the
 * account menu, the notification poll, Alpine's state) and only the page area, `#page-root`
 * in layouts/app.blade.php, is swapped for the one the server renders.
 *
 * Why not Turbo / htmx: every page here has its own script module that wires its DOM and
 * adds document level listeners. Those have to be started and torn down on every visit, which
 * is the part a generic library cannot do for us; the rest is small enough to own.
 *
 * How it works
 *   click on a link inside [data-soft-nav]  -> fetch(url) as HTML
 *   -> swap #page-root, the title, the sidebar's active link
 *   -> unmount the old page (aborts its listeners), mount the new one's modules
 *
 * Page modules (resources/js/pages/*.js) export a default `start()`. A view lists the ones it
 * needs with <x-page-module name="companies" /> (a hidden marker); this file imports them and
 * calls `start()` once per visit. Everything `start()` registers on `document` / `window` while
 * it runs is removed when the page is left (see `scoped`), so a page can not leak listeners
 * into the next one. Anything else that outlives a visit (a timer) must stop by itself when its
 * root is no longer in the document (menu.js does).
 *
 * Safety net: whatever is not a clean HTML page of the same layout (an error page, a login
 * redirect, a download, a new deploy, a view with an inline <script>, a failed request) falls
 * back to a normal full page load of that URL, so a soft visit can never leave a blank page.
 */

// One lazy chunk per page module; the key is './../pages/<name>.js'.
const modules = import.meta.glob('../pages/*.js');

const ROOT_ID = 'page-root';

let controller = null; // aborts the listeners of the page on screen
let request = null; // the fetch in flight
let currentPath = location.pathname + location.search;

const moduleName = (element) => element.getAttribute('data-page-module');

const namesIn = (doc) => [...doc.querySelectorAll('[data-page-module]')].map(moduleName).filter(Boolean);

/**
 * Run `run` with document / window listeners tied to `signal`: they are removed when it aborts.
 * Only the synchronous part of `run` is covered, which is where the page modules wire themselves.
 */
const scoped = (signal, run) => {
    const targets = [document, window];

    for (const target of targets) {
        const original = target.addEventListener;

        target.addEventListener = function (type, listener, options) {
            const given = typeof options === 'object' && options !== null ? options : { capture: Boolean(options) };

            return original.call(this, type, listener, { ...given, signal });
        };
    }

    try {
        run();
    } finally {
        // Back to the prototype's own method.
        for (const target of targets) {
            delete target.addEventListener;
        }
    }
};

const unmount = () => {
    controller?.abort();
    controller = null;
};

const mount = async (names) => {
    const mine = new AbortController();

    controller = mine;

    for (const name of names) {
        const load = modules['../pages/' + name + '.js'];

        if (!load) {
            console.warn('Unknown page module: ' + name);

            continue;
        }

        try {
            const loaded = await load();

            // The person already moved on while the chunk downloaded.
            if (mine.signal.aborted) {
                return;
            }

            if (typeof loaded.default === 'function') {
                scoped(mine.signal, () => loaded.default({ signal: mine.signal }));
            }
        } catch (error) {
            console.error('Page module "' + name + '" failed.', error);
        }
    }
};

/* ---- the top progress bar ---------------------------------------------------- */

let bar = null;
let barTimer = null;
let creepTimer = null;

const progress = {
    start() {
        clearTimeout(barTimer);

        // A quick page never shows it: the bar only appears when the wait is noticeable.
        barTimer = setTimeout(() => {
            if (!bar) {
                bar = document.createElement('div');
                bar.setAttribute('aria-hidden', 'true');
                bar.className = 'pointer-events-none fixed left-0 top-0 z-[70] h-0.5 w-full origin-left bg-gray-900 transition-[transform,opacity] duration-300 ease-out motion-reduce:transition-none dark:bg-mist';
                document.body.appendChild(bar);
            }

            let value = 0.15;

            bar.style.opacity = '1';
            bar.style.transform = 'scaleX(' + value + ')';

            clearInterval(creepTimer);
            creepTimer = setInterval(() => {
                value += (0.9 - value) * 0.15;
                bar.style.transform = 'scaleX(' + value + ')';
            }, 250);
        }, 150);
    },

    done() {
        clearTimeout(barTimer);
        clearInterval(creepTimer);

        if (!bar) {
            return;
        }

        const finished = bar;

        finished.style.transform = 'scaleX(1)';
        finished.style.opacity = '0';
        bar = null;
        setTimeout(() => finished.remove(), 350);
    },
};

/* ---- sidebar: the active link and the group that holds it ------------------------ */

const syncNav = (doc) => {
    const current = document.querySelector('#app-sidebar nav');
    const next = doc.querySelector('#app-sidebar nav');

    if (!current || !next) {
        return false;
    }

    const from = [...current.querySelectorAll('a[href]')];
    const to = [...next.querySelectorAll('a[href]')];

    // Another set of links (the person's permissions changed): do not guess, load the page.
    if (from.length !== to.length || from.some((link, index) => link.getAttribute('href') !== to[index].getAttribute('href'))) {
        return false;
    }

    from.forEach((link, index) => {
        // The pill's colours are classes; copying them lets the highlight ease over.
        link.className = to[index].className;

        if (to[index].hasAttribute('aria-current')) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    });

    for (const group of current.querySelectorAll('[data-nav-group]')) {
        const same = next.querySelector('[data-nav-group="' + group.getAttribute('data-nav-group') + '"]');

        group.toggleAttribute('data-has-active', Boolean(same?.hasAttribute('data-has-active')));
    }

    // Same rule as the script under the nav: the group holding the page is open on arrival
    // (nothing is stored), and every header says whether its group is open.
    const root = document.documentElement;
    let closed = (root.getAttribute('data-nav-closed') || '').split(' ').filter(Boolean);

    for (const group of current.querySelectorAll('[data-nav-group][data-has-active]')) {
        closed = closed.filter((key) => key !== group.getAttribute('data-nav-group'));
    }

    if (closed.length) {
        root.setAttribute('data-nav-closed', closed.join(' '));
    } else {
        root.removeAttribute('data-nav-closed');
    }

    for (const group of current.querySelectorAll('[data-nav-group]')) {
        group.querySelector('button[aria-controls]')?.setAttribute(
            'aria-expanded',
            closed.includes(group.getAttribute('data-nav-group')) ? 'false' : 'true',
        );
    }

    return true;
};

/* ---- visiting a page --------------------------------------------------------------- */

const fullLoad = (url) => {
    progress.done();
    window.location.assign(url);
};

const remember = () => {
    try {
        history.replaceState({ ...(history.state ?? {}), scrollY: window.scrollY }, '');
    } catch (error) {
        // Without it, going back simply starts at the top.
    }
};

const visit = async (target, { push = true, scrollY = 0 } = {}) => {
    request?.abort();

    const mine = new AbortController();

    request = mine;
    progress.start();

    let response;
    let text;

    try {
        response = await fetch(target, {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
            signal: mine.signal,
        });

        if (!(response.headers.get('content-type') || '').includes('text/html')) {
            return fullLoad(target);
        }

        text = await response.text();
    } catch (error) {
        // A newer click replaced this one; anything else (offline) is shown by the browser.
        if (error?.name === 'AbortError') {
            return undefined;
        }

        return fullLoad(target);
    }

    if (mine.signal.aborted) {
        return undefined;
    }

    const finalUrl = new URL(response.url, location.href);
    const doc = new DOMParser().parseFromString(text, 'text/html');
    const incoming = doc.getElementById(ROOT_ID);
    const outgoing = document.getElementById(ROOT_ID);

    // Not this layout (an error page, the login page after a redirect), another build of the
    // assets, a script that an innerHTML swap would not run: let the browser do it.
    const hash = (name) => document.querySelector('meta[name="' + name + '"]')?.content ?? '';
    const assetsChanged = (doc.querySelector('meta[name="asset-hash"]')?.content ?? '') !== hash('asset-hash');

    if (!response.ok || !incoming || !outgoing || assetsChanged || finalUrl.origin !== location.origin || incoming.querySelector('script')) {
        return fullLoad(response.url || target);
    }

    if (!syncNav(doc)) {
        return fullLoad(response.url || target);
    }

    const destination = finalUrl.pathname + finalUrl.search + (finalUrl.hash || new URL(target, location.href).hash);

    if (push) {
        remember();
        history.pushState({ scrollY: 0 }, '', destination);
    } else {
        history.replaceState(history.state, '', destination);
    }

    unmount();

    document.title = doc.title;
    outgoing.replaceChildren(...document.adoptNode(incoming).childNodes);
    currentPath = location.pathname + location.search;

    const anchor = location.hash ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;

    if (anchor) {
        anchor.scrollIntoView();
    } else {
        window.scrollTo(0, scrollY);
    }

    // Keyboard and screen reader users start at the new page, not on the link they used.
    const main = outgoing.querySelector('main');

    if (main) {
        main.setAttribute('tabindex', '-1');
        main.style.outline = 'none';
        main.focus({ preventScroll: true });
    }

    progress.done();

    // One tick, so Alpine has initialised the new markup (x-data drawers, modals) before the
    // page module looks for it, as it has on a full page load.
    await new Promise((resolve) => setTimeout(resolve, 0));

    await mount(namesIn(doc));

    document.dispatchEvent(new CustomEvent('soft-nav:load', { detail: { url: destination } }));

    return undefined;
};

/* ---- wiring (once, for the life of the tab) ------------------------------------------- */

const eligible = (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return null;
    }

    const link = event.target.closest?.('a[href]');

    if (!link || !link.closest('[data-soft-nav]') || link.hasAttribute('download') || link.dataset.softNav === 'off') {
        return null;
    }

    if (link.target && link.target !== '_self') {
        return null;
    }

    const url = new URL(link.href, location.href);

    if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)) {
        return null;
    }

    // A jump inside the page is the browser's business.
    if (url.pathname === location.pathname && url.search === location.search && url.hash) {
        return null;
    }

    return url;
};

if (document.getElementById(ROOT_ID)) {
    try {
        // We save and restore the scroll ourselves: the browser would do it before the page is there.
        history.scrollRestoration = 'manual';

        if (history.state?.scrollY) {
            window.scrollTo(0, history.state.scrollY);
        }
    } catch (error) {
        // Fine: the browser keeps its own behaviour.
    }

    window.addEventListener('pagehide', remember);

    document.addEventListener('click', (event) => {
        const url = eligible(event);

        if (!url) {
            return;
        }

        event.preventDefault();

        // The link of the page you are on is a refresh, not a new history entry.
        visit(url.href, { push: url.pathname + url.search !== location.pathname + location.search });
    });

    window.addEventListener('popstate', (event) => {
        // Only the hash changed (a #link inside the page): nothing to fetch.
        if (location.pathname + location.search === currentPath) {
            return;
        }

        visit(location.href, { push: false, scrollY: event.state?.scrollY ?? 0 });
    });

    const first = () => mount(namesIn(document));

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', first);
    } else {
        first();
    }
}
