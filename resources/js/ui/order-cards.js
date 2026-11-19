/**
 * Clickable order cards (My Orders, My Pre-Orders): a click opens the order popup.
 *
 *   initOrderCards(document.querySelector('#my-orders-root'));
 *
 * Each card carries what the popup shows as JSON in `data-order` (built by the controller, see
 * Controller::customerOrderPopup), so opening it costs no request. Two ways in:
 *
 *   - the card's `Details` button (`[data-order-open]`): the way for the keyboard and a screen
 *     reader, always opens;
 *   - a click on the card itself, for the mouse and the finger, EXCEPT on anything interactive
 *     inside it (links, buttons, form fields, the Fix your payment panel, a zoomable proof) or
 *     while text is being selected: those do their own thing.
 *
 * The popup is the one staff use (ui/order-popup.js); it is read only here, since the data has
 * no action URLs and no customer / verifier, so it offers no Complete / Cancel / Verify / Reject.
 */
import { initOrderPopup } from './order-popup';

const INTERACTIVE = 'a, button, input, select, textarea, label, form, summary, [data-fix-payment], [data-zoom]';

export function initOrderCards(root) {
    if (!root) {
        return;
    }

    const popup = initOrderPopup();

    const orderOf = (card) => {
        try {
            return JSON.parse(card.dataset.order);
        } catch (error) {
            return null;
        }
    };

    root.addEventListener('click', (event) => {
        const card = event.target.closest('[data-order]');

        if (!card || !root.contains(card)) {
            return;
        }

        if (!event.target.closest('[data-order-open]')) {
            // Something inside the card has its own job; so does a drag to copy a number.
            if (event.target.closest(INTERACTIVE) || window.getSelection()?.toString()) {
                return;
            }
        }

        popup.open(orderOf(card));
    });
}
