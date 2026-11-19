/**
 * My Bulk Orders page: a click on an order card opens the order popup (see ui/order-cards.js).
 * The page itself is server rendered; this is the only script it needs.
 */
import { initOrderCards } from '../ui/order-cards';

const start = () => initOrderCards(document.querySelector('#bulk-my-orders-root'));

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
