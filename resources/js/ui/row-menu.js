/**
 * The "three dots" menu for a table row's action buttons.
 *
 * `rowMenuHtml(actionsHtml, label)` wraps the buttons a controller already built (the
 * `data-row-action` ones) in a small dots button + a dropdown, so a wide table spends one
 * narrow column on Actions. The buttons keep their attributes, so the page's own delegated
 * click listener serves them exactly as before; this module only opens and closes the menu.
 * Use it in a column's `render` for the table view only (cards keep their plain buttons):
 *
 *     { data: 'action', render: (value, type) => (type === 'display' ? rowMenuHtml(value, 'Actions') : value) }
 *     initRowMenus();
 *
 * The dropdown is `position: fixed`, placed from the dots button's rectangle: the table sits in
 * a sideways scroller, and an absolutely placed panel would be clipped by it (the last rows
 * would need a scrollbar). Because it is fixed it also closes on scroll and resize, instead of
 * floating away from its row. Styling of the items: "Row menu" in resources/css/app.css.
 */

const escapeAttr = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
}[character]));

const DOTS = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';

const TOGGLE_CLASS = 'inline-flex h-8 w-8 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-600 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-300 aria-expanded:bg-gray-100 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-600 dark:aria-expanded:bg-gray-600';

const PANEL_CLASS = 'fixed z-50 hidden min-w-[9rem] animate-pop gap-0.5 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-card-lift grid dark:border-gray-700 dark:bg-gray-800';

// The markup of one row's menu; an empty string when the row has no action to offer.
export const rowMenuHtml = (actionsHtml, label = 'Actions') => {
    if (!actionsHtml) {
        return '';
    }

    const name = escapeAttr(label);

    return '<div data-row-menu class="inline-block text-left">'
        + '<button type="button" data-row-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="' + name + '" title="' + name + '" class="' + TOGGLE_CLASS + '">' + DOTS + '</button>'
        + '<div data-row-menu-panel class="' + PANEL_CLASS + '">' + actionsHtml + '</div>'
        + '</div>';
};

let openMenu = null;

const close = (returnFocus = false) => {
    if (!openMenu) {
        return;
    }

    const toggle = openMenu.querySelector('[data-row-menu-toggle]');

    openMenu.querySelector('[data-row-menu-panel]')?.classList.add('hidden');
    toggle?.setAttribute('aria-expanded', 'false');

    if (returnFocus) {
        toggle?.focus();
    }

    openMenu = null;
};

const open = (menu) => {
    close();

    const toggle = menu.querySelector('[data-row-menu-toggle]');
    const panel = menu.querySelector('[data-row-menu-panel]');

    if (!toggle || !panel) {
        return;
    }

    // Shown first so it can be measured, then placed under the dots (or above them near the
    // bottom of the screen), right edges aligned, never off the left edge.
    panel.classList.remove('hidden');

    const anchor = toggle.getBoundingClientRect();
    const width = panel.offsetWidth;
    const height = panel.offsetHeight;
    const gap = 6;

    const below = anchor.bottom + gap + height <= window.innerHeight - 8;
    const top = below ? anchor.bottom + gap : Math.max(8, anchor.top - gap - height);
    const left = Math.max(8, Math.min(anchor.right - width, window.innerWidth - width - 8));

    panel.style.top = top + 'px';
    panel.style.left = left + 'px';

    toggle.setAttribute('aria-expanded', 'true');
    openMenu = menu;
};

// Once per page: the rows are injected (and re-injected) by DataTables, so everything is delegated.
export const initRowMenus = () => {
    // The mark sits on the page's <main>, not in a module variable: a soft navigation (ui/soft-nav.js)
    // replaces <main> and removes these listeners, so the next page has to wire them again.
    const page = document.querySelector('main') ?? document.body;

    if (page.dataset.rowMenusWired) {
        return;
    }

    page.dataset.rowMenusWired = '1';
    openMenu = null;

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-row-menu-toggle]');

        if (toggle) {
            const menu = toggle.closest('[data-row-menu]');

            if (openMenu === menu) {
                close();
            } else {
                open(menu);
            }

            return;
        }

        if (!openMenu) {
            return;
        }

        // Choosing an item closes the menu (the page's own listener still gets the click);
        // so does a click anywhere else.
        if (!event.target.closest('[data-row-menu-panel]') || event.target.closest('button, a')) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && openMenu) {
            close(true);
        }
    });

    // A fixed panel would stay behind while its row moves.
    window.addEventListener('scroll', () => close(), true);
    window.addEventListener('resize', () => close());
};
