/**
 * Proof of Payment Gallery.
 *
 * Every uploaded proof as a picture, newest payment first, narrowed by payment method and
 * date. All of it is AJAX: the route answers one page of pictures plus the methods the
 * filter offers. Pictures carry `data-zoom`, so the shared image viewer
 * (resources/js/ui/image-viewer.js) enlarges and saves them.
 *
 * Flow:
 *   a filter changes       -> page 1 is fetched again
 *   Previous / Next        -> that page is fetched
 *   a newer request starts -> the older one is aborted, so a late answer never wins
 */
import { showToast } from '../ui/toast';

const start = () => {
    const root = document.querySelector('#proof-gallery');

    if (!root) {
        console.warn('The proof of payment gallery is incomplete; nothing was wired.');

        return;
    }

    const find = (id) => root.querySelector('#' + id);

    const methodFilter = find('proof-method-filter');
    const dateFrom = find('proof-date-from');
    const dateTo = find('proof-date-to');
    const clearButton = find('proof-clear');
    const errorBox = find('proof-error');
    const retryButton = find('proof-retry');
    const loading = find('proof-loading');
    const empty = find('proof-empty');
    const grid = find('proof-grid');
    const footer = find('proof-footer');
    const summary = find('proof-summary');
    const previousButton = find('proof-previous');
    const nextButton = find('proof-next');

    const copy = root.dataset;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const money = (value) => '₱' + Number(value ?? 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // 2026-11-10 14:05:00 -> Nov 10, 2026, 2:05 PM
    const prettyDateTime = (value) => {
        const parsed = new Date(String(value ?? '').replace(' ', 'T'));

        return Number.isNaN(parsed.getTime())
            ? '—'
            : parsed.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    };

    /* ---- drawing ---------------------------------------------------------- */

    const card = (payment) => {
        const caption = [payment.order_number, payment.customer_name, money(payment.amount)].filter(Boolean).join(' · ');

        // `contain`, not `cover`: a receipt cut off at the edges is not the proof.
        return `
        <li class="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <a href="${escapeHtml(payment.proof_url)}" target="_blank" rel="noopener" data-zoom data-zoom-caption="${escapeHtml(caption)}"
               aria-label="${escapeHtml((copy.proofLabel ?? '') + ': ' + caption)}"
               class="group block aspect-[3/4] cursor-zoom-in overflow-hidden bg-gray-50 focus:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-indigo-300 dark:bg-gray-900/40">
                <img src="${escapeHtml(payment.proof_url)}" alt="" loading="lazy" decoding="async"
                     class="h-full w-full object-contain transition duration-200 group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:scale-100">
            </a>
            <div class="flex flex-1 flex-col gap-1 border-t border-gray-100 p-3 dark:border-gray-700/60">
                <p class="truncate text-xs font-semibold text-gray-900 dark:text-white" title="${escapeHtml(payment.order_number)}">${escapeHtml(payment.order_number)}</p>
                <p class="truncate text-xs text-gray-600 dark:text-gray-300" title="${escapeHtml(payment.customer_name)}">${escapeHtml(payment.customer_name)}</p>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">${escapeHtml(payment.method_name ?? '—')}</p>
                <p class="truncate text-xs tabular-nums text-gray-500 dark:text-gray-400" title="${escapeHtml((copy.referenceLabel ?? '') + ': ' + (payment.reference_number ?? '—'))}">${escapeHtml(payment.reference_number ?? '—')}</p>
                <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-white">${money(payment.amount)}</p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">${escapeHtml(prettyDateTime(payment.created_at))}</p>
                <div class="mt-auto pt-1">${payment.status ?? ''}</div>
            </div>
        </li>`;
    };

    // A file that is gone from storage says so, instead of a broken picture that opens nothing.
    const markBroken = (image) => {
        const placeholder = document.createElement('div');

        placeholder.className = 'flex aspect-[3/4] items-center justify-center bg-gray-50 p-3 text-center text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400';
        placeholder.textContent = copy.brokenLabel ?? '';

        image.closest('a')?.replaceWith(placeholder);
    };

    const show = (element, visible, display = null) => {
        element.classList.toggle('hidden', !visible);

        if (display) {
            element.classList.toggle(display, visible);
        }
    };

    const filtered = () => methodFilter.value !== '' || dateFrom.value !== '' || dateTo.value !== '';

    // The methods come with the answer (only those a proof was paid with). Rebuilt when they
    // change, keeping the choice while it still exists.
    let methodsKey = '';

    const syncMethods = (methods) => {
        const key = JSON.stringify(methods);

        if (key === methodsKey) {
            return;
        }

        methodsKey = key;

        const chosen = methodFilter.value;
        const first = methodFilter.querySelector('option[value=""]')?.outerHTML ?? '<option value=""></option>';

        methodFilter.innerHTML = first + methods
            .map((method) => '<option value="' + escapeHtml(method.id) + '">' + escapeHtml(method.name) + '</option>')
            .join('');
        methodFilter.value = methods.some((method) => String(method.id) === chosen) ? chosen : '';
    };

    let page = 1;

    const render = (payload) => {
        const rows = payload.data ?? [];

        page = payload.page;
        syncMethods(payload.methods ?? []);

        grid.innerHTML = rows.map(card).join('');
        grid.querySelectorAll('img').forEach((image) => image.addEventListener('error', () => markBroken(image), { once: true }));

        show(grid, rows.length > 0, 'grid');
        show(footer, rows.length > 0, 'flex');
        show(empty, rows.length === 0);

        empty.textContent = (filtered() ? copy.emptyFiltered : copy.empty) ?? '';

        const first = (payload.page - 1) * payload.per_page + 1;

        summary.textContent = (copy.summary ?? '')
            .replace(':from', first.toLocaleString('en-US'))
            .replace(':to', (first + rows.length - 1).toLocaleString('en-US'))
            .replace(':total', Number(payload.total).toLocaleString('en-US'));

        previousButton.disabled = payload.page <= 1;
        nextButton.disabled = payload.page >= payload.last_page;
    };

    /* ---- fetching --------------------------------------------------------- */

    let request = null;
    let wantedPage = 1;

    const load = async (target = 1) => {
        request?.abort();

        const mine = new AbortController();

        request = mine;
        wantedPage = target;

        clearButton.disabled = !filtered();
        previousButton.disabled = true;
        nextButton.disabled = true;
        grid.classList.add('opacity-50');
        grid.setAttribute('aria-busy', 'true');
        show(errorBox, false, 'flex');

        // `page` is always sent, so this address is never the page's own: going Back cannot
        // show the browser's cached JSON.
        const query = new URLSearchParams({
            page: String(target),
            method: methodFilter.value,
            from: dateFrom.value,
            to: dateTo.value,
        });

        try {
            const response = await fetch(copy.endpoint + '?' + query.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                signal: mine.signal,
            });

            if (!response.ok) {
                throw new Error('The server answered ' + response.status);
            }

            const payload = await response.json();

            if (mine.signal.aborted) {
                return;
            }

            render(payload);
        } catch (error) {
            if (mine.signal.aborted) {
                return;
            }

            console.warn('The proofs could not be loaded.', error);

            // The old pictures belong to the old filters: do not leave them up.
            show(grid, false, 'grid');
            show(footer, false, 'flex');
            show(empty, false);
            show(errorBox, true, 'flex');
        }

        show(loading, false);
        grid.classList.remove('opacity-50');
        grid.removeAttribute('aria-busy');
    };

    /* ---- filters ---------------------------------------------------------- */

    // The date range can never run backwards: each box limits the other, and a typed date
    // that breaks the rule is pulled back to the other end of the range, with a message.
    const keepRangeValid = (changed) => {
        if (changed && dateFrom.value !== '' && dateTo.value !== '' && dateFrom.value > dateTo.value) {
            changed.value = changed === dateFrom ? dateTo.value : dateFrom.value;

            showToast(copy.dateRangeError ?? 'Date from cannot be later than date to.', 'warning');
        }

        dateTo.min = dateFrom.value;
        dateFrom.max = dateTo.value;
    };

    methodFilter.addEventListener('change', () => load(1));

    [dateFrom, dateTo].forEach((control) => control.addEventListener('change', () => {
        keepRangeValid(control);
        load(1);
    }));

    clearButton.addEventListener('click', () => {
        methodFilter.value = '';
        // The app's own dropdown repaints its label on `change`, which also loads page 1.
        dateFrom.value = '';
        dateTo.value = '';
        keepRangeValid(null);
        methodFilter.dispatchEvent(new Event('change', { bubbles: true }));
    });

    /* ---- pages ------------------------------------------------------------ */

    const turn = async (target) => {
        await load(target);

        // The pager is under the pictures: start the new page at its top.
        root.scrollIntoView({ block: 'start' });
    };

    previousButton.addEventListener('click', () => turn(page - 1));
    nextButton.addEventListener('click', () => turn(page + 1));
    retryButton.addEventListener('click', () => load(wantedPage));

    load(1);
};

// Started by resources/js/ui/soft-nav.js on every visit (see <x-page-module>).
export default start;
