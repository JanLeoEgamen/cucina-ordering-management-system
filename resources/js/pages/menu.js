/**
 * Menu page: the dishes the running session offers, and the checkout wizard.
 *
 * One self contained script. The only import is the toast
 * (resources/js/ui/toast.js); the session, the payment methods, the copy, the
 * URLs and the dish data all come from the Blade view through `data-*`
 * attributes, so nothing is hard coded here.
 *
 * Checkout is one modal with three steps, shown by showStep():
 *
 *   review   the cart: change amounts, take a dish out, see the total
 *   payment  cash on pickup, or bank / e-wallet (pick a method, see the QR and
 *            the account, send the reference and the proof)
 *   confirm  the dishes and the payment, then place the order
 *   placed   the receipt
 *
 * The progress bar and the step dots follow the step, so the customer always
 * knows how far along they are.
 */
import { showToast } from '../ui/toast';

const start = () => {
    const root = document.querySelector('#menu-root');

    if (!root) {
        return;
    }

    const sessionId = root.dataset.sessionId ?? '';
    const orderUrl = root.dataset.orderUrl ?? '';
    const bankMethods = JSON.parse(root.dataset.methods ?? '[]');
    const cashMethod = JSON.parse(root.dataset.cash ?? 'null');

    // The round cart in the lower right corner opens the checkout.
    const openCartButtons = [...document.querySelectorAll('[data-open-cart]')];
    const cartLines = document.querySelector('#cart-lines');
    const cartEmpty = document.querySelector('#cart-empty');
    const checkoutTotal = document.querySelector('#checkout-total');
    const checkoutErrors = document.querySelector('#checkout-errors');
    const stepName = document.querySelector('#checkout-step-name');
    const stepProgress = document.querySelector('#checkout-step-progress');
    const progressFill = document.querySelector('#checkout-progress');
    const progressBar = document.querySelector('#checkout-progress-bar');
    const backButton = document.querySelector('#checkout-back');
    const nextButton = document.querySelector('#checkout-next');
    const bankMethodsHost = document.querySelector('#bank-methods');
    const methodDetails = document.querySelector('#method-details');
    const paymentFields = document.querySelector('#payment-fields');
    const referenceInput = document.querySelector('#checkout-reference');
    const confirmSummary = document.querySelector('#confirm-summary');
    const placedSummary = document.querySelector('#placed-summary');
    const dishModalImage = document.querySelector('#dish-modal-image');
    const dishModalPlaceholder = document.querySelector('#dish-modal-placeholder');
    const dishModalName = document.querySelector('#dish-modal-name');
    const dishModalTypes = document.querySelector('#dish-modal-types');
    const dishModalPrice = document.querySelector('#dish-modal-price');
    const dishModalStock = document.querySelector('#dish-modal-stock');
    const dishModalDescription = document.querySelector('#dish-modal-description');
    const dishModalIngredients = document.querySelector('#dish-modal-ingredients');
    const dishModalCalories = document.querySelector('#dish-modal-calories');
    const dishModalAdd = document.querySelector('#dish-modal-add');
    const dishModalClose = document.querySelector('#dish-modal-close');

    /* The kiosk bar: search, the price and calorie boxes, the dish types. */
    const searchInput = document.querySelector('#menu-search');
    const filterToggle = document.querySelector('#filter-toggle');
    const filterPanel = document.querySelector('#filter-panel');
    const filterCount = document.querySelector('#filter-count');
    const filterClear = document.querySelector('#filter-clear');
    const menuCount = document.querySelector('#menu-count');
    const menuEmpty = document.querySelector('#menu-empty');
    const typeHost = document.querySelector('#menu-types');
    const typeChips = [...document.querySelectorAll('[data-filter-type]')];
    const minPriceInput = document.querySelector('#filter-min-price');
    const maxPriceInput = document.querySelector('#filter-max-price');
    const minCaloriesInput = document.querySelector('#filter-min-calories');
    const maxCaloriesInput = document.querySelector('#filter-max-calories');
    const allFilterInputs = [searchInput, minPriceInput, maxPriceInput, minCaloriesInput, maxCaloriesInput];

    /* The kiosk's cart, round in the lower right corner. */
    const cartFab = document.querySelector('#cart-fab');
    const cartBarCount = document.querySelector('#cart-bar-count');

    // The dishes on this page, by session_dish_id. The cards and the dish modal
    // both read from here, so opening a dish never costs a request and a card
    // does not have to carry the whole record in its attributes.
    const dishesById = new Map(
        JSON.parse(root.dataset.dishes ?? '[]').map((dish) => [Number(dish.session_dish_id), dish]),
    );

    if (!cartLines || !nextButton || !confirmSummary || !placedSummary) {
        console.warn('The menu page is incomplete; nothing was wired.');

        return;
    }

    /* ---- state ----------------------------------------------------------- */

    // session_dish_id -> { id, name, price, stock, quantity }
    const lines = new Map();

    let step = 'review';
    let choice = null;        // 'cash' | 'bank'
    let methodId = null;
    let proofFile = null;
    let receipt = null;
    let placing = false;

    /* ---- helpers --------------------------------------------------------- */

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const money = (value) => '$' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const total = () => [...lines.values()].reduce((sum, line) => sum + line.price * line.quantity, 0);

    const itemCount = () => [...lines.values()].reduce((sum, line) => sum + line.quantity, 0);

    const pickedMethod = () => bankMethods.find((method) => Number(method.method_id) === Number(methodId)) ?? null;

    const messages = (payload) => {
        if (payload && payload.errors) {
            const list = Object.values(payload.errors).flat().filter(Boolean);

            if (list.length) {
                return list;
            }
        }

        return payload && typeof payload.message === 'string' ? [payload.message] : [];
    };

    const showErrors = (list) => {
        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        if (!checkoutErrors) {
            return;
        }

        checkoutErrors.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        checkoutErrors.classList.toggle('hidden', items.length === 0);
    };

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // "3 items", or "1 item" for one.
    const countLabel = (count) => count + ' ' + (count === 1 ? (root.dataset.itemLabel ?? '') : (root.dataset.itemsLabel ?? ''));

    // An empty field reads better as a dash than as nothing at all.
    const fillText = (element, value) => {
        if (! element) {
            return;
        }

        element.textContent = value === null || value === undefined || value === '' ? '—' : value;
    };

    // A short bump on the cart count: a tap on "Add to Cart" is answered without
    // a toast, which would stack up as the customer picks several dishes.
    const bumpCartCount = () => {
        if (!cartBarCount) {
            return;
        }

        cartBarCount.classList.add('scale-125');
        setTimeout(() => cartBarCount.classList.remove('scale-125'), 180);
    };

    /* ---- the cart lines -------------------------------------------------- */

    const lineTemplate = (line) => `
        <div class="flex items-center gap-4 rounded-2xl border border-gray-100 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800" data-line-id="${line.id}">
            <div class="relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-700">
                <svg class="h-6 w-6 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                </svg>
                ${line.image ? `<img src="${escapeHtml(line.image)}" alt="" class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">` : ''}
            </div>

            <div class="min-w-0 flex-1">
                <p class="truncate text-base font-semibold text-gray-900 dark:text-white">${escapeHtml(line.name)}</p>
                ${line.types ? `<p class="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">${escapeHtml(line.types)}</p>` : ''}
            </div>

            <div class="flex shrink-0 flex-col items-end gap-2">
                <span class="text-base font-bold tabular-nums text-indigo-600 dark:text-indigo-400">${money(line.price * line.quantity)}</span>

                <div class="flex items-center rounded-full border border-gray-200 dark:border-gray-600">
                    <button type="button" data-step="-1" aria-label="${escapeHtml(root.dataset.lessLabel ?? '')}"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-lg leading-none text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:hover:bg-gray-700 dark:hover:text-white">−</button>

                    <input type="text" inputmode="numeric" data-qty value="${line.quantity}"
                           class="w-9 border-0 bg-transparent p-0 text-center text-sm font-medium tabular-nums text-gray-900 focus:outline-none focus:ring-0 dark:text-white">

                    <button type="button" data-step="1" aria-label="${escapeHtml(root.dataset.moreLabel ?? '')}"
                            class="flex h-9 w-9 items-center justify-center rounded-full text-lg leading-none text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:hover:bg-gray-700 dark:hover:text-white">+</button>
                </div>

                ${line.quantity > 1 ? `<span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">× ${money(line.price)} ${escapeHtml(root.dataset.eachLabel ?? '')}</span>` : ''}
            </div>

            <button type="button" data-remove aria-label="${escapeHtml(root.dataset.removeLabel ?? '')}"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-gray-300 transition hover:bg-red-50 hover:text-red-600 focus:outline-none dark:hover:bg-red-900/30 dark:hover:text-red-400">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>`;

    const renderCart = () => {
        const rows = [...lines.values()];
        const empty = rows.length === 0;

        cartLines.innerHTML = rows.map(lineTemplate).join('');
        cartLines.classList.toggle('hidden', empty);
        cartEmpty?.classList.toggle('hidden', ! empty);
        checkoutTotal.textContent = money(total());

        // The round cart is always visible; only its count changes.
        if (cartBarCount) {
            cartBarCount.textContent = String(itemCount());
        }

        // The round button says what it holds, for anyone not reading the badge.
        cartFab?.setAttribute('aria-label', (root.dataset.cartLabel ?? '') + ', ' + countLabel(itemCount()));

        // Nothing in the cart: the wizard cannot move on.
        if (step === 'review') {
            nextButton.disabled = empty;
        }

        // The cards show what is in the cart (0 until something is added).
        syncCards();
    };

    // A quantity the customer typed, kept inside what the session still has.
    const setQuantity = (id, quantity) => {
        const line = lines.get(id);

        if (! line) {
            return;
        }

        const wanted = Math.max(1, Math.min(Number(quantity) || 1, line.stock));

        if (Number(quantity) > line.stock) {
            showToast(line.name + ' — ' + line.stock + ' ' + (root.dataset.stockLeft ?? ''), 'warning');
        }

        line.quantity = wanted;
        renderCart();
    };

    // The cart is filled from the menu cards, so the lines are handled by
    // delegation: the rows are replaced on every change.
    cartLines.addEventListener('click', (event) => {
        const row = event.target.closest('[data-line-id]');

        if (! row) {
            return;
        }

        const id = Number(row.dataset.lineId);
        const line = lines.get(id);

        if (! line) {
            return;
        }

        if (event.target.closest('[data-remove]')) {
            lines.delete(id);
            renderCart();

            return;
        }

        const stepButton = event.target.closest('[data-step]');

        if (stepButton) {
            setQuantity(id, line.quantity + Number(stepButton.dataset.step));
        }
    });

    cartLines.addEventListener('change', (event) => {
        const input = event.target.closest('[data-qty]');
        const row = input?.closest('[data-line-id]');

        if (input && row) {
            setQuantity(Number(row.dataset.lineId), input.value);
        }
    });

    /* ---- the menu cards -------------------------------------------------- */

    const addLabel = root.dataset.addLabel ?? 'Add to Cart';

    // Stock left of one dish: the label, and whether it can still be ordered.
    const setStock = (card, left) => {
        const id = card.dataset.dishCard;
        const label = card.querySelector('[data-stock-label="' + id + '"]');

        if (label) {
            label.textContent = left > 0 ? left + ' ' + (root.dataset.stockLeft ?? '') : (root.dataset.stockNone ?? '');
        }
    };

    /* ---- the quantity on a card is the dish's quantity in the cart -------- */

    // There is no Add to Cart button on a card. The stepper starts at 0, "+"
    // puts one in the cart, "-" takes one out, and the card says "Added to
    // cart" while at least one is in. Anything past what the session has left
    // is refused. A function declaration, so renderCart (above) can call it.
    function syncCards() {
        for (const card of document.querySelectorAll('[data-dish-card]')) {
            const id = Number(card.dataset.dishCard);
            const stock = Number(dishesById.get(id)?.available_stock ?? 0);
            const quantity = lines.get(id)?.quantity ?? 0;
            const inCart = quantity > 0;

            const qty = card.querySelector('[data-card-qty]');
            const less = card.querySelector('[data-card-step="-1"]');
            const more = card.querySelector('[data-card-step="1"]');

            if (qty) {
                qty.textContent = String(quantity);
            }

            if (less) {
                less.disabled = ! inCart;
            }

            if (more) {
                more.disabled = stock < 1 || quantity >= stock;
            }

            // The indicator: a tick and a label, and a ring around the card.
            const badge = card.querySelector('[data-in-cart]');

            badge?.classList.toggle('hidden', ! inCart);
            badge?.classList.toggle('inline-flex', inCart);
            card.classList.toggle('ring-2', inCart);
            card.classList.toggle('ring-indigo-500', inCart);
        }
    }

    document.addEventListener('click', (event) => {
        const step = event.target.closest('[data-card-step]');

        if (! step || step.disabled) {
            return;
        }

        const card = step.closest('[data-dish-card]');
        const id = Number(card?.dataset.dishCard);
        const line = lines.get(id);

        if (Number(step.dataset.cardStep) > 0) {
            addDish(id, 1);

            return;
        }

        if (! line) {
            return;
        }

        if (line.quantity <= 1) {
            lines.delete(id);
            renderCart();
        } else {
            setQuantity(id, line.quantity - 1);
        }
    });

    // Put a dish in the cart, up to what the session has left. The card's
    // stepper and the dish modal both come through here.
    const addDish = (sessionDishId, quantity = 1) => {
        const id = Number(sessionDishId);
        const dish = dishesById.get(id);
        const stock = Number(dish?.available_stock ?? 0);
        const wanted = Math.max(1, Number(quantity) || 1);

        if (! dish || stock < 1) {
            return;
        }

        const existing = lines.get(id);

        if (existing) {
            setQuantity(id, existing.quantity + wanted);
        } else {
            lines.set(id, {
                id,
                name: dish.dish_name,
                // Shown under the name on the checkout line.
                types: (dish.dish_types ?? []).map((type) => type.type_name).join(' · '),
                image: dish.image ?? '',
                price: Number(dish.price),
                stock,
                quantity: Math.min(wanted, stock),
            });
        }

        // No toast here on purpose: the cart count and the lines in the checkout
        // already say the dish went in. The count gives a short bump so the tap
        // reads as answered.
        renderCart();
        bumpCartCount();
    };

    /* ---- the dish modal -------------------------------------------------- */

    // What the customer is looking at: the picture, the price, and what is in it.
    const openDish = (sessionDishId) => {
        const dish = dishesById.get(Number(sessionDishId));

        if (! dish) {
            return;
        }

        const stock = Number(dish.available_stock ?? 0);
        const soldOut = stock < 1;

        if (dishModalImage) {
            // No src at all when the dish has no picture: an empty src makes
            // some browsers ask for the page itself.
            if (dish.image) {
                dishModalImage.src = dish.image;
                dishModalImage.dataset.zoomCaption = dish.dish_name ?? '';
                dishModalImage.classList.remove('hidden');
            } else {
                dishModalImage.removeAttribute('src');
                dishModalImage.classList.add('hidden');
            }
        }

        dishModalPlaceholder?.classList.toggle('hidden', Boolean(dish.image));

        fillText(dishModalName, dish.dish_name);
        fillText(dishModalPrice, money(dish.price));
        fillText(dishModalStock, soldOut ? (root.dataset.stockNone ?? '') : stock + ' ' + (root.dataset.stockLeft ?? ''));
        fillText(dishModalDescription, dish.description);
        fillText(dishModalIngredients, dish.main_ingredients);

        // Calories sit next to the stock, and go away when the dish has none.
        if (dishModalCalories) {
            dishModalCalories.textContent = dish.calories ? dish.calories + ' ' + (root.dataset.kcalLabel ?? '') : '';
            dishModalCalories.classList.toggle('hidden', ! dish.calories);
        }

        if (dishModalTypes) {
            dishModalTypes.innerHTML = (dish.dish_types ?? [])
                .map((type) => '<span class="inline-flex items-center rounded-md border border-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">' + escapeHtml(type.type_name) + '</span>')
                .join('');
        }

        // Ordering from the modal is the same code path as the card's button.
        if (dishModalAdd) {
            dishModalAdd.disabled = soldOut;
            dishModalAdd.textContent = soldOut ? (root.dataset.stockNone ?? '') : addLabel;
            dishModalAdd.dataset.sessionDishId = String(dish.session_dish_id);
        }

        emit('open-modal', 'dish-modal');
    };

    // A card opens the dish modal: on click, and on Enter / Space when it has
    // the focus (the card is a role="button", not a real one).
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-dish-view]');

        if (trigger) {
            openDish(trigger.dataset.dishView);
        }
    });

    document.addEventListener('keydown', (event) => {
        const trigger = event.target.closest('[data-dish-view]');

        if (! trigger || (event.key !== 'Enter' && event.key !== ' ')) {
            return;
        }

        event.preventDefault();
        openDish(trigger.dataset.dishView);
    });

    // Add from the modal, then out of the way.
    dishModalAdd?.addEventListener('click', () => {
        addDish(dishModalAdd.dataset.sessionDishId);
        emit('close-modal', 'dish-modal');
    });

    dishModalClose?.addEventListener('click', () => emit('close-modal', 'dish-modal'));

    /* ---- filters --------------------------------------------------------- */

    // "3 dishes", or "1 dish" for one.
    const dishLabel = (count) => count + ' ' + (count === 1 ? (root.dataset.dishLabel ?? '') : (root.dataset.dishesLabel ?? ''));

    // What the kiosk is narrowed down to. An empty box reads as null: ignored.
    const filters = { type: '', search: '', minPrice: null, maxPrice: null, minCalories: null, maxCalories: null };

    const numberOrNull = (input) => {
        const value = String(input?.value ?? '').trim();

        return value === '' ? null : Number(value);
    };

    // The boxes, read back into the state above.
    const readFilters = () => {
        filters.search = (searchInput?.value ?? '').trim().toLowerCase();
        filters.minPrice = numberOrNull(minPriceInput);
        filters.maxPrice = numberOrNull(maxPriceInput);
        filters.minCalories = numberOrNull(minCaloriesInput);
        filters.maxCalories = numberOrNull(maxCaloriesInput);
    };

    // One dish against everything the customer narrowed down to.
    const shows = (dish) => {
        const price = Number(dish.price ?? 0);
        const calories = Number(dish.calories ?? 0);
        // Types are matched by name: the rail groups them that way, so two type
        // rows sharing a name stay one category.
        const types = (dish.dish_types ?? []).map((type) => String(type.type_name));

        if (filters.type !== '' && ! types.includes(filters.type)) {
            return false;
        }

        if (filters.search !== '' && ! String(dish.dish_name ?? '').toLowerCase().includes(filters.search)) {
            return false;
        }

        if (filters.minPrice !== null && price < filters.minPrice) {
            return false;
        }

        if (filters.maxPrice !== null && price > filters.maxPrice) {
            return false;
        }

        if (filters.minCalories !== null && calories < filters.minCalories) {
            return false;
        }

        if (filters.maxCalories !== null && calories > filters.maxCalories) {
            return false;
        }

        return true;
    };

    // How many filters are doing something, for the badge on the button.
    const activeFilters = () => [filters.type, filters.search, filters.minPrice, filters.maxPrice, filters.minCalories, filters.maxCalories]
        .filter((value) => value !== null && value !== '').length;

    // The cards are hidden rather than taken out: the page already has them all,
    // so narrowing the menu is instant and costs nothing.
    const applyFilters = () => {
        let showing = 0;

        for (const card of document.querySelectorAll('[data-dish-card]')) {
            const dish = dishesById.get(Number(card.dataset.dishCard));
            const visible = dish ? shows(dish) : true;

            card.classList.toggle('hidden', ! visible);
            showing += visible ? 1 : 0;
        }

        const active = activeFilters();

        if (filterCount) {
            filterCount.textContent = String(active);
            filterCount.classList.toggle('hidden', active === 0);
        }

        if (menuCount) {
            menuCount.textContent = dishLabel(showing);
        }

        menuEmpty?.classList.toggle('hidden', showing > 0);
    };

    // The picked category is filled in, the rest stay outlined.
    const paintChips = () => {
        for (const chip of typeChips) {
            const picked = (chip.dataset.filterType ?? '') === filters.type;

            chip.className = picked ? (typeHost?.dataset.chipOn ?? '') : (typeHost?.dataset.chipOff ?? '');
            chip.setAttribute('aria-pressed', picked ? 'true' : 'false');
        }
    };

    for (const chip of typeChips) {
        chip.addEventListener('click', () => {
            filters.type = chip.dataset.filterType ?? '';
            paintChips();
            applyFilters();
        });
    }

    for (const input of allFilterInputs) {
        input?.addEventListener('input', () => {
            readFilters();
            applyFilters();
        });
    }

    filterToggle?.addEventListener('click', () => {
        // toggle() answers whether the class went on, so it is now hidden.
        const open = filterPanel?.classList.toggle('hidden') === false;

        filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    filterClear?.addEventListener('click', () => {
        for (const input of allFilterInputs) {
            if (input) {
                input.value = '';
            }
        }

        filters.type = '';
        readFilters();
        paintChips();
        applyFilters();
    });

    /* ---- steps ----------------------------------------------------------- */

    // Where each step sits on the bar, and what the footer buttons say.
    const STEP_PROGRESS = { review: 33, payment: 66, confirm: 100, placed: 100 };

    const stepLabels = {};

    for (const dot of document.querySelectorAll('[data-step-dot]')) {
        stepLabels[dot.dataset.stepDot] = dot.dataset.stepName;
    }

    stepLabels.placed = root.dataset.placedLabel ?? 'Order Placed';

    const showStep = (next) => {
        step = next;

        // 0-based: 'placed' is past the three steps the bar counts.
        const currentIndex = step === 'placed' ? STEPS.length : STEPS.indexOf(step);

        for (const panel of document.querySelectorAll('[data-step-panel]')) {
            panel.classList.toggle('hidden', panel.dataset.stepPanel !== step);
        }

        // Progress bar and dots: what is behind us reads as done.
        if (progressFill) {
            progressFill.style.width = STEP_PROGRESS[step] + '%';
        }

        if (progressBar) {
            progressBar.setAttribute('aria-valuenow', String(STEP_PROGRESS[step]));
        }

        for (const dot of document.querySelectorAll('[data-step-dot]')) {
            const key = dot.dataset.stepDot;
            const index = Number(dot.dataset.stepNumber) - 1;
            // Everything before the current step reads as done; the receipt is
            // past all three.
            const state = index < currentIndex ? 'done' : (index === currentIndex ? 'active' : 'todo');

            dot.className = 'flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full border text-xs font-semibold ' + (
                state === 'todo'
                    ? 'border-gray-300 bg-white text-gray-400 dark:border-gray-600 dark:bg-gray-700'
                    : 'border-indigo-600 bg-indigo-600 text-white'
            );

            dot.textContent = state === 'done' ? '✓' : String(index + 1);

            const caption = document.querySelector('[data-step-caption="' + key + '"]');

            if (caption) {
                caption.className = 'truncate text-xs font-medium ' + (
                    state === 'todo' ? 'text-gray-400 dark:text-gray-500' : 'text-indigo-700 dark:text-indigo-300'
                );
            }
        }

        if (stepName) {
            stepName.textContent = stepLabels[step] ?? '';
        }

        if (stepProgress) {
            // "Step 2 of 3" — the receipt is not one of the three steps.
            stepProgress.textContent = step === 'placed'
                ? ''
                : (root.dataset.stepProgress ?? '').replace(':current', String(currentIndex + 1)).replace(':total', String(STEPS.length));
        }

        // Footer: the labels, and what is possible from here.
        backButton?.classList.toggle('hidden', step === 'placed');

        if (step === 'review') {
            nextButton.textContent = root.dataset.nextReview ?? nextButton.textContent;
            nextButton.disabled = lines.size === 0;
        }

        if (step === 'payment') {
            nextButton.textContent = root.dataset.nextPayment ?? 'Continue';
            nextButton.disabled = false;
        }

        if (step === 'confirm') {
            nextButton.textContent = root.dataset.nextConfirm ?? 'Place Order';
            nextButton.disabled = false;
        }

        if (step === 'placed') {
            nextButton.textContent = root.dataset.nextPlaced ?? 'Done';
            nextButton.disabled = false;
        }

        if (backButton) {
            backButton.textContent = step === 'review'
                ? (root.dataset.backMenu ?? 'Back to Menu')
                : (root.dataset.backStep ?? 'Back');
        }

        showErrors([]);
    };

    // The three steps the progress bar counts; the receipt is past them.
    const STEPS = ['review', 'payment', 'confirm'];

    /* ---- payment: the two choices ---------------------------------------- */

    const choiceButtons = [...document.querySelectorAll('[data-pay-choice]')];
    const choicePanels = [...document.querySelectorAll('[data-pay-panel]')];

    const idleTile = 'border-gray-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/40 dark:border-gray-600 dark:bg-gray-700/40 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20';
    const pickedTile = 'border-indigo-500 bg-indigo-50/60 ring-2 ring-indigo-200 dark:border-indigo-400 dark:bg-indigo-900/30 dark:ring-indigo-800';

    const renderChoice = () => {
        for (const button of choiceButtons) {
            const picked = button.dataset.payChoice === choice;

            button.className = 'flex w-full items-start gap-3 rounded-xl border p-4 text-left transition focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:focus:ring-indigo-900 ' + (picked ? pickedTile : idleTile);

            // A bank / e-wallet method is open: cash steps out of the way too.
            button.classList.toggle('hidden', choice === 'bank');

            const check = button.querySelector('[data-pay-check]');

            if (check) {
                check.classList.toggle('hidden', ! picked);
                check.classList.toggle('flex', picked);
            }
        }

        for (const panel of choicePanels) {
            panel.classList.toggle('hidden', panel.dataset.payPanel !== choice);
        }
    };

    choiceButtons.forEach((button) => {
        button.addEventListener('click', () => {
            choice = button.dataset.payChoice;

            // Cash needs nothing else: no bank method stays ticked.
            methodId = null;

            renderChoice();
            renderMethods();
            showErrors([]);

            // Cash has nothing to fill in, so go straight to the confirmation.
            // (Back returns to this list.)
            if (choice === 'cash') {
                renderConfirm();
                showStep('confirm');
            }
        });
    });

    /* ---- payment: the bank / e-wallet list ------------------------------- */

    const methodTemplate = (method) => {
        const picked = Number(method.method_id) === Number(methodId);
        // One method picked: the others step out of the way, this one spans the row.
        const others = methodId !== null && ! picked;
        const account = [method.account_name, method.account_number].filter(Boolean).join(' · ');

        return `
            <button type="button" data-method-id="${method.method_id}" aria-expanded="${picked ? 'true' : 'false'}"
                    class="flex w-full items-center gap-3 rounded-xl border px-4 py-3 text-left transition focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:focus:ring-indigo-900 ${picked ? pickedTile + ' sm:col-span-2' : idleTile}${others ? ' hidden' : ''}">
                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white ring-1 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600">
                    ${method.qr_image
                        ? `<img src="${escapeHtml(method.qr_image)}" alt="" class="h-full w-full object-contain p-0.5" onerror="this.remove()">`
                        : '<svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5" /></svg>'}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">${escapeHtml(method.method_name)}</span>
                    ${account ? `<span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">${escapeHtml(account)}</span>` : ''}
                </span>
                ${picked ? `<span class="flex-shrink-0 text-xs font-medium text-indigo-600 dark:text-indigo-400">${escapeHtml(root.dataset.changeLabel ?? '')}</span>` : ''}
                <!-- A chevron, not a radio dot: the row opens its panel below it. -->
                <svg class="h-4 w-4 flex-shrink-0 transition-transform duration-200 ${picked ? 'rotate-90 text-indigo-600 dark:text-indigo-400' : 'text-gray-400'}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
            </button>`;
    };

    // The file name a saved QR gets: "gcash-qr.png".
    const qrFileName = (method) => {
        const extension = (String(method.qr_image).split('?')[0].match(/\.(png|jpe?g|gif|webp)$/i) ?? [])[0] ?? '.png';
        const slug = String(method.method_name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'payment';

        return slug + '-qr' + extension;
    };

    // The panel with the details and the reference / proof inputs is one DOM
    // node that moves under the picked row, so what was typed or uploaded in it
    // survives the list being redrawn.
    const bankPanel = document.querySelector('[data-pay-panel="bank"]');
    const bankPanelHome = bankPanel?.parentElement ?? null;

    const renderMethods = () => {
        if (! bankMethodsHost) {
            return;
        }

        // Park the panel outside the list first: redrawing the list would
        // otherwise throw the panel (and the inputs in it) away.
        if (bankPanel && bankPanelHome) {
            bankPanelHome.append(bankPanel);
        }

        // No bank methods: nothing to list (the view already warns when there is
        // no payment option at all).
        bankMethodsHost.innerHTML = bankMethods.map(methodTemplate).join('');

        // The chosen method, shown in full: where to send the money.
        const method = pickedMethod();

        // Open the panel right under the picked row.
        if (method && bankPanel) {
            bankMethodsHost.querySelector('[data-method-id="' + method.method_id + '"]')?.after(bankPanel);
        }

        if (! methodDetails || ! paymentFields) {
            return;
        }

        methodDetails.classList.toggle('hidden', ! method);
        paymentFields.classList.toggle('hidden', ! method);

        if (! method) {
            return;
        }

        methodDetails.innerHTML = `
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                ${method.qr_image
                    ? `<div class="mx-auto flex-shrink-0 sm:mx-0">
                           <img src="${escapeHtml(method.qr_image)}" alt="${escapeHtml(method.method_name)}" data-zoom data-zoom-caption="${escapeHtml(method.method_name)}" title="${escapeHtml(root.dataset.zoomHint ?? '')}"
                                class="h-40 w-40 cursor-zoom-in rounded-xl bg-white object-contain p-1 ring-1 ring-gray-200 dark:ring-gray-600">
                       </div>`
                    : ''}
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.scanLabel ?? '')}</p>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.methodLabel ?? '')}</dt>
                            <dd class="text-gray-900 dark:text-white">${escapeHtml(method.method_name)}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.accountNameLabel ?? '')}</dt>
                            <dd class="text-gray-900 dark:text-white">${escapeHtml(method.account_name || '—')}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.accountNumberLabel ?? '')}</dt>
                            <dd class="tabular-nums text-gray-900 dark:text-white">${escapeHtml(method.account_number || '—')}</dd>
                        </div>
                    </dl>
                </div>
            </div>
            ${method.qr_image
                ? `<a href="${escapeHtml(method.qr_image)}" download="${escapeHtml(qrFileName(method))}"
                      class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                       <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" /></svg>
                       ${escapeHtml(root.dataset.saveQrLabel ?? 'Save QR')}
                   </a>`
                : ''}`;
    };

    bankMethodsHost?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-method-id]');

        if (! button) {
            return;
        }

        const clicked = Number(button.dataset.methodId);

        // Tapping the open method again closes its panel.
        if (clicked === Number(methodId)) {
            methodId = null;
            choice = null;

            renderChoice();
            renderMethods();
            showErrors([]);

            return;
        }

        methodId = clicked;
        choice = 'bank';

        renderChoice();
        renderMethods();
        showErrors([]);

        // Where to send the money is what the customer needs next: bring the
        // details into view instead of leaving them below the fold.
        methodDetails?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    /* ---- payment: the proof of payment ----------------------------------- */

    const setupProofUpload = () => {
        const zone = document.querySelector('#proof-drop-zone');
        const input = document.querySelector('#proof-image');
        const preview = document.querySelector('#proof-image-preview');
        const remove = document.querySelector('#proof-remove-image');
        const uploadContent = document.querySelector('#proof-upload-content');
        const wrapper = document.querySelector('#proof-image-wrapper');
        const info = document.querySelector('#proof-file-info');

        if (! zone || ! input || ! preview) {
            return;
        }

        // The hidden input covers the zone, so clicking the zone already opens
        // the picker; the zone's own handler skips the input and the X.
        zone.addEventListener('click', (event) => {
            if (event.target === input || (remove && remove.contains(event.target))) {
                return;
            }

            input.click();
        });

        const highlight = (on) => {
            zone.classList.toggle('border-indigo-400', on);
            zone.classList.toggle('bg-indigo-50/60', on);
        };

        ['dragenter', 'dragover'].forEach((event) => {
            zone.addEventListener(event, (e) => { e.preventDefault(); e.stopPropagation(); highlight(true); });
        });

        ['dragleave', 'drop'].forEach((event) => {
            zone.addEventListener(event, (e) => { e.preventDefault(); e.stopPropagation(); highlight(false); });
        });

        zone.addEventListener('drop', (e) => {
            const files = e.dataTransfer?.files;

            if (! files?.length) {
                return;
            }

            const transfer = new DataTransfer();
            transfer.items.add(files[0]);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        const showPreview = (file) => {
            proofFile = file && file.type.startsWith('image/') ? file : null;

            if (! proofFile) {
                preview.src = '';
                preview.classList.add('hidden');
                wrapper?.classList.add('hidden');
                uploadContent?.classList.remove('hidden');
                info && (info.textContent = info.dataset.default ?? '');

                return;
            }

            const reader = new FileReader();
            reader.onload = (event) => {
                preview.src = event.target.result;
                preview.classList.remove('hidden');
                wrapper?.classList.remove('hidden');
                uploadContent?.classList.add('hidden');
                info && (info.textContent = proofFile.name + ' · ' + Math.max(1, Math.round(proofFile.size / 1024)) + ' KB');
            };
            reader.readAsDataURL(proofFile);
        };

        input.addEventListener('change', () => showPreview(input.files?.[0] ?? null));

        remove?.addEventListener('click', () => {
            input.value = '';
            showPreview(null);
        });
    };

    setupProofUpload();

    /* ---- the confirm step, and the receipt ------------------------------- */

    const paymentSummary = () => {
        const method = choice === 'bank' ? pickedMethod() : cashMethod;
        const name = method?.method_name ?? '—';
        const reference = referenceInput?.value.trim() ?? '';

        return `
            <div class="rounded-xl border border-gray-200 dark:border-gray-700">
                <h5 class="border-b border-gray-200 px-4 py-2 text-sm font-semibold text-gray-900 dark:border-gray-700 dark:text-white">${escapeHtml(root.dataset.methodLabel ?? '')}</h5>
                <dl class="space-y-2 px-4 py-3 text-sm">
                    <div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(name)}</dt>
                        <dd class="text-right font-medium text-gray-900 dark:text-white">${money(total())}</dd>
                    </div>
                    ${reference ? `<div class="flex items-start justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.referenceLabel ?? '')}</dt>
                        <dd class="text-right tabular-nums text-gray-900 dark:text-white">${escapeHtml(reference)}</dd>
                    </div>` : ''}
                    ${proofFile ? `<div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.proofLabel ?? '')}</dt>
                        <dd class="flex items-center gap-2 text-right text-gray-900 dark:text-white">
                            <img src="${proofPreviewSrc()}" alt="" data-zoom data-zoom-caption="${escapeHtml(root.dataset.proofLabel ?? '')}" class="h-10 w-10 cursor-zoom-in rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                            <span class="max-w-[10rem] truncate text-xs">${escapeHtml(proofFile.name)}</span>
                        </dd>
                    </div>` : ''}
                </dl>
            </div>`;
    };

    const proofPreviewSrc = () => document.querySelector('#proof-image-preview')?.getAttribute('src') ?? '';

    const renderConfirm = () => {
        const rows = [...lines.values()];

        confirmSummary.innerHTML = `
            <div class="rounded-xl border border-gray-200 dark:border-gray-700">
                <h5 class="border-b border-gray-200 px-4 py-2 text-sm font-semibold text-gray-900 dark:border-gray-700 dark:text-white">
                    ${escapeHtml(root.dataset.linesLabel ?? '')}
                    <span class="ml-1 font-normal text-gray-500 dark:text-gray-400">${escapeHtml(countLabel(itemCount()))}</span>
                </h5>
                <ul class="divide-y divide-gray-100 dark:divide-gray-700/70">
                    ${rows.map((line) => `
                        <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                            <span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200">
                                <span class="tabular-nums text-gray-500 dark:text-gray-400">${line.quantity}×</span> ${escapeHtml(line.name)}
                            </span>
                            <span class="tabular-nums text-gray-900 dark:text-white">${money(line.price * line.quantity)}</span>
                        </li>`).join('')}
                </ul>
                <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">${escapeHtml(root.dataset.totalLabel ?? 'Total')}</span>
                    <span class="text-base font-semibold tabular-nums text-gray-900 dark:text-white">${money(total())}</span>
                </div>
            </div>
            ${paymentSummary()}`;
    };

    const renderReceipt = () => {
        placedSummary.innerHTML = `
            <div class="py-2 text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100 text-green-600 dark:bg-green-900/40 dark:text-green-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </span>
                <h4 class="mt-3 text-lg font-semibold text-gray-900 dark:text-white">${escapeHtml(root.dataset.placedLabel ?? '')}</h4>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">${escapeHtml(countLabel(receipt.items))} · ${money(receipt.total)}</p>

                <dl class="mx-auto mt-4 max-w-sm space-y-2 rounded-xl border border-gray-200 px-4 py-3 text-left text-sm dark:border-gray-700">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.orderLabel ?? '')}</dt>
                        <dd class="font-medium tabular-nums text-gray-900 dark:text-white">${escapeHtml(receipt.number)}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.methodLabel ?? '')}</dt>
                        <dd class="text-gray-900 dark:text-white">${escapeHtml(receipt.method)}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.totalLabel ?? 'Total')}</dt>
                        <dd class="text-gray-900 dark:text-white">${money(receipt.total)}</dd>
                    </div>
                </dl>

                <p class="mx-auto mt-3 max-w-sm text-xs text-gray-500 dark:text-gray-400">${escapeHtml(root.dataset.verifyingLabel ?? '')}</p>

                ${root.dataset.myOrdersUrl ? `
                    <a href="${escapeHtml(root.dataset.myOrdersUrl)}"
                       class="mt-4 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        ${escapeHtml(root.dataset.myOrdersLabel ?? '')}
                    </a>` : ''}
            </div>`;
    };

    /* ---- moving between the steps ---------------------------------------- */

    // The stock the server returns after an order, keyed by session_dish_id.
    const applyStock = (stock) => {
        for (const card of document.querySelectorAll('[data-dish-card]')) {
            const id = Number(card.dataset.dishCard);
            const left = Number(stock[id] ?? 0);
            const dish = dishesById.get(id);

            // The dish map is what the modal reads, so it moves with the card.
            if (dish) {
                dish.available_stock = left;
            }

            setStock(card, left);
        }

        // The steppers may not promise more than is left.
        syncCards();
    };

    const placeOrder = async () => {
        if (placing) {
            return;
        }

        const method = choice === 'bank' ? pickedMethod() : cashMethod;

        if (! method) {
            showErrors([choice === 'bank' ? (root.dataset.chooseMethod ?? '') : (root.dataset.noOptions ?? '')]);
            showStep('payment');

            return;
        }

        placing = true;

        // The button says what is happening; showStep() puts the right label
        // back for whichever step we end up on.
        nextButton.disabled = true;
        nextButton.textContent = nextButton.dataset.loadingLabel ?? nextButton.textContent;

        try {
            // The order posts like a form: the lines are the items array, and the
            // proof rides along as a file.
            const data = new FormData();

            data.append('_token', csrfToken());
            data.append('session_id', sessionId);
            data.append('payment_method_id', String(method.method_id));

            if (choice === 'bank' && referenceInput?.value.trim()) {
                data.append('reference_number', referenceInput.value.trim());
            }

            if (proofFile) {
                data.append('proof_of_payment', proofFile);
            }

            for (const line of lines.values()) {
                data.append('items[' + line.id + ']', String(line.quantity));
            }

            const response = await fetch(orderUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: data,
            });

            const payload = await response.json().catch(() => ({}));

            if (! response.ok) {
                // The order stays where it is: the customer can fix the cart or
                // the payment and send it again.
                showErrors(messages(payload));
                showStep('payment');

                return;
            }

            receipt = {
                number: payload.order_number ?? '',
                total: Number(payload.total_amount ?? 0),
                items: itemCount(),
                method: method.method_name,
            };

            // Placed: empty the cart, move the stock numbers on, show the receipt.
            lines.clear();
            methodId = null;
            choice = null;
            proofFile = null;
            referenceInput && (referenceInput.value = '');

            const input = document.querySelector('#proof-image');
            input && (input.value = '');
            document.querySelector('#proof-image-preview') && (document.querySelector('#proof-image-preview').src = '');
            document.querySelector('#proof-image-wrapper')?.classList.add('hidden');
            document.querySelector('#proof-upload-content')?.classList.remove('hidden');

            renderCart();
            renderChoice();
            renderMethods();
            renderReceipt();
            showStep('placed');
            applyStock(payload.stock ?? {});

            checkoutTotal.textContent = money(receipt.total);

            // No toast: the receipt on screen is the confirmation, and the
            // order is on the customer's own orders page.
        } catch (error) {
            console.warn('The order could not be sent.', error);
            showErrors([root.dataset.sendFailed ?? '']);
            showStep('payment');
        } finally {
            placing = false;
        }
    };

    nextButton.addEventListener('click', () => {
        showErrors([]);

        if (step === 'review') {
            if (lines.size === 0) {
                showErrors([root.dataset.emptyCart ?? '']);

                return;
            }

            renderChoice();
            renderMethods();
            showStep('payment');

            return;
        }

        if (step === 'payment') {
            if (! choice) {
                showErrors([root.dataset.choosePayment ?? '']);

                return;
            }

            if (choice === 'bank' && ! pickedMethod()) {
                showErrors([root.dataset.chooseMethod ?? '']);

                return;
            }

            // Anything but cash is paid first, so the proof is required.
            if (choice === 'bank' && ! proofFile) {
                showErrors([root.dataset.proofRequired ?? '']);

                return;
            }

            renderConfirm();
            showStep('confirm');

            return;
        }

        if (step === 'confirm') {
            placeOrder();

            return;
        }

        // The receipt: nothing left to do but close.
        emit('close-modal', 'checkout-modal');
    });

    backButton?.addEventListener('click', () => {
        showErrors([]);

        if (step === 'review') {
            emit('close-modal', 'checkout-modal');

            return;
        }

        if (step === 'payment') {
            showStep('review');

            return;
        }

        showStep('payment');
    });

    /* ---- wiring ---------------------------------------------------------- */

    // The header's Cart button and the kiosk bar both open the checkout.
    for (const button of openCartButtons) {
        button.addEventListener('click', () => {
            showErrors([]);
            renderCart();
            renderChoice();
            renderMethods();
            showStep('review');
            emit('open-modal', 'checkout-modal');
        });
    }

    // The escape key closes the modal through Alpine, so the wizard is reset
    // when it is opened again — nothing to do here but start in a clean state.
    renderCart();
    renderChoice();
    renderMethods();
    showStep('review');
    applyFilters();
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
