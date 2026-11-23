/**
 * My Pre-Orders page: a click on an order card opens the order popup (see ui/order-cards.js).
 * The page itself is server rendered; this is the only script it needs.
 */
import { initOrderCards } from '../ui/order-cards';

const start = () => initOrderCards(document.querySelector('#my-pre-orders-root'));

// Started by resources/js/ui/soft-nav.js on every visit (see <x-page-module>); what start() adds to
// document / window is removed when the page is left.
export default start;
