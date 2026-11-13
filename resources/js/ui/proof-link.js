/**
 * "View proof" as a button-looking pill: the link to a payment's receipt, which opens in the
 * image viewer (`data-zoom`, resources/js/ui/image-viewer.js). A small outlined pill with a
 * picture icon, so it reads as something to press instead of a plain label.
 *
 * Returns HTML for the modules that build rows and popups in JS. The Blade twin is
 * resources/views/components/ui/proof-link.blade.php: change both together.
 * Tailwind finds these class names because it scans resources/js.
 */
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[char]));

const CLASSES = 'inline-flex items-center gap-1.5 rounded-full border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-800 shadow-sm transition hover:border-gray-400 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-300 dark:border-gray-500 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600 dark:focus:ring-gray-600';

const ICON = '<svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.41a2.25 2.25 0 0 1 3.182 0l2.909 2.91M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z"/></svg>';

export const proofLink = (url, label, caption, extraClass = '') => '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener" data-zoom data-zoom-caption="' + escapeHtml(caption) + '"'
    + ' class="' + CLASSES + (extraClass ? ' ' + extraClass : '') + '">' + ICON + escapeHtml(label) + '</a>';
