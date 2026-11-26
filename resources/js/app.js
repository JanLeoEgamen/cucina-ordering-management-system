import './bootstrap';

import Alpine from 'alpinejs';
// Registers Alpine.data('passwordField') for <x-password-input>. Imports run before the lines
// below, so it is registered before Alpine.start() walks the page.
import './ui/password-field';

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
// Log out in one tab, every other tab leaves too (and any 401 sends the tab to the login page).
import './ui/session-guard';
// The notification bell's badge and toasts (a small poll; see the file).
import './ui/notifications';
// Sidebar links swap only the page area (no browser reload) and start / stop each page's module.
// Imported last: it starts the current page's module, which may use anything above.
import './ui/soft-nav';

/*
 * Every <select> gets the app's own dropdown (resources/js/ui/select-menu.js), the
 * same panel as the toolbar's sort menu, instead of the browser's list.
 */
import { autoEnhanceSelects } from './ui/select-menu';
// Every date input gets the matching calendar (resources/js/ui/date-picker.js).
import { autoEnhanceDatePickers } from './ui/date-picker';

const enhanceFields = () => {
    autoEnhanceSelects();
    autoEnhanceDatePickers();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhanceFields);
} else {
    enhanceFields();
}
