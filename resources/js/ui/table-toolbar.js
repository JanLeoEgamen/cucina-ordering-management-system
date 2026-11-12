import { enhanceSelects } from './select-menu.js';

/**
 * The toolbar above a table: search, Sort and Filter.
 *
 * The markup is <x-ui.table-toolbar> (resources/views/components/ui/table-toolbar.blade.php).
 * A page script creates its DataTable with the table's own search box switched off
 * (`layout.topEnd: null`) and then calls
 *
 *     initTableToolbar(table);
 *
 *  - the search field narrows the table (`table.search(...)`), so the card grid
 *    and the calendar, which are built from the filtered rows, follow;
 *  - the Sort options call `table.order(...)`; the label follows the table when a
 *    header is clicked instead;
 *  - the Filter dropdown holds the page's own controls (selects, dates...). The page
 *    keeps its listeners on them; this only counts how many are away from their
 *    default (the badge) and offers "Clear filters", which puts them all back and
 *    tells the page with a `change` event;
 *  - the dropdowns close on an outside click or Escape.
 */
export function initTableToolbar(table, root = document.querySelector('[data-table-toolbar]')) {
    if (!root || !table) {
        return null;
    }

    const search = root.querySelector('[data-table-search]');
    const sortToggle = root.querySelector('[data-table-sort-toggle]');
    const sortLabel = root.querySelector('[data-table-sort-label]');
    const sortOptions = [...root.querySelectorAll('[data-table-sort]')];
    const filterToggle = root.querySelector('[data-table-filter-toggle]');
    const filterPanel = root.querySelector('#table-filter-panel');
    const filterCount = root.querySelector('[data-table-filter-count]');
    const clearButton = root.querySelector('[data-table-clear]');
    const popovers = [...root.querySelectorAll('[data-popover]')];

    // The filter selects get the app's own dropdown instead of the browser's.
    if (filterPanel) {
        enhanceSelects(filterPanel);
    }

    /* ---- search ----------------------------------------------------------- */

    let searchTimer = null;

    search?.addEventListener('input', () => {
        // A short pause, so typing is not one redraw per key.
        clearTimeout(searchTimer);

        searchTimer = setTimeout(() => {
            table.search(search.value.trim()).draw();
        }, 150);
    });

    /* ---- sort ------------------------------------------------------------- */

    const sameAsTable = (option) => {
        const [column, direction] = table.order()[0] ?? [];

        return Number(option.dataset.col) === column && option.dataset.dir === direction;
    };

    // Tick the option the table is sorted by, and say so on the button. A header
    // click can leave the table sorted by something the list does not offer.
    const syncSort = () => {
        let current = null;

        for (const option of sortOptions) {
            const on = sameAsTable(option);

            option.setAttribute('aria-checked', on ? 'true' : 'false');
            current = on ? option : current;
        }

        if (sortLabel) {
            sortLabel.textContent = current ? current.textContent.trim() : (sortLabel.dataset.default ?? sortLabel.textContent);
        }
    };

    if (sortLabel) {
        sortLabel.dataset.default = sortLabel.textContent;
    }

    for (const option of sortOptions) {
        option.addEventListener('click', () => {
            table.order([Number(option.dataset.col), option.dataset.dir]).draw();
            closePopovers();
        });
    }

    table.on('order', syncSort);
    syncSort();

    /* ---- the filter dropdown ---------------------------------------------- */

    const controls = () => [...(filterPanel?.querySelectorAll('select, input') ?? [])];

    // "Away from its default": a select not on its first option, a box with
    // something in it, a ticked checkbox.
    const isSet = (control) => {
        if (control.tagName === 'SELECT') {
            // A multi select (the dish type filter) is set when anything is ticked.
            return control.multiple ? control.selectedOptions.length > 0 : control.selectedIndex > 0;
        }

        return control.type === 'checkbox' || control.type === 'radio' ? control.checked : control.value !== '';
    };

    const updateBadge = () => {
        const active = controls().filter(isSet).length;

        if (filterCount) {
            filterCount.textContent = String(active);
            filterCount.classList.toggle('hidden', active === 0);
        }
    };

    filterPanel?.addEventListener('change', updateBadge);
    filterPanel?.addEventListener('input', updateBadge);

    clearButton?.addEventListener('click', () => {
        for (const control of controls()) {
            if (!isSet(control)) {
                continue;
            }

            if (control.tagName === 'SELECT') {
                control.selectedIndex = control.multiple ? -1 : 0;
            } else if (control.type === 'checkbox' || control.type === 'radio') {
                control.checked = false;
            } else {
                control.value = '';
            }

            // The page script listens on the control itself.
            control.dispatchEvent(new Event('input', { bubbles: true }));
            control.dispatchEvent(new Event('change', { bubbles: true }));
        }

        updateBadge();
    });

    updateBadge();

    /* ---- dropdowns -------------------------------------------------------- */

    function closePopovers(except = null) {
        for (const panel of popovers) {
            if (panel === except) {
                continue;
            }

            panel.classList.add('hidden');
            root.querySelector('[aria-controls="' + panel.id + '"]')?.setAttribute('aria-expanded', 'false');
        }
    }

    for (const toggle of [sortToggle, filterToggle]) {
        toggle?.addEventListener('click', () => {
            const panel = document.getElementById(toggle.getAttribute('aria-controls'));

            if (!panel) {
                return;
            }

            closePopovers(panel);

            // toggle() answers whether the class went on, so it is now hidden.
            const open = panel.classList.toggle('hidden') === false;

            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-popover], [data-table-sort-toggle], [data-table-filter-toggle]')) {
            closePopovers();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closePopovers();
        }
    });

    return { syncSort, updateBadge };
}
