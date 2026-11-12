import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

/*
 * That is all this entry point does.
 *
 * Each CRUD page is a self contained module in resources/js/pages/ (loaded by
 * its own view with @vite), and shares only the toast (resources/js/ui/toast.js).
 * DataTables is imported by those page modules, so it is only downloaded on
 * pages that render a table.
 */

/*
 * The image viewer is the one other thing loaded on every page: it listens for
 * clicks on anything marked `data-zoom` (see resources/js/ui/image-viewer.js).
 */
import './ui/image-viewer';
import './ui/theme';

/*
 * Every <select> gets the app's own dropdown (resources/js/ui/select-menu.js), the
 * same panel as the toolbar's sort menu, instead of the browser's list.
 */
import { autoEnhanceSelects } from './ui/select-menu';

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoEnhanceSelects);
} else {
    autoEnhanceSelects();
}
