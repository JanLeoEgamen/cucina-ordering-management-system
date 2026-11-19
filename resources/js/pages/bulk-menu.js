/**
 * Bulk menu page (customer).
 *
 * A lighter sibling of menu.js: the same job (pick dishes, pay, place the order) for
 * big quantities and with NO stock, so there is no stock polling and no sold-out
 * state. Everything the page needs - URLs, copy, the dishes, the payment methods,
 * the minimum - comes from the Blade view through `data-*` on #bulk-menu-root.
 *
 * Flow:
 *   [data-step]            -> +1 / -1 / +10 on a card, or type the plates in its box
 *   #bulk-review           -> checkout modal (enabled once the minimum is reached)
 *   [data-method-id]       -> pick how to pay; a bank / e-wallet method asks for a proof
 *   #bulk-place            -> POST the order; a 422 with `prices` sends the customer
 *                             back to review the cart, success shows the receipt
 */
import { showToast } from '../ui/toast';

const MAX_QUANTITY = 10000;

const start = () => {
    const root = document.querySelector('#bulk-menu-root');

    // Nothing is running (or the session has no dish): the view shows a message only.
    if (!root || !root.dataset.sessionId || !document.querySelector('#bulk-summary')) {
        return;
    }

    /* ---- data and helpers ------------------------------------------------ */

    const parse = (value, fallback) => {
        try {
            return JSON.parse(value) ?? fallback;
        } catch (error) {
            return fallback;
        }
    };

    const sessionId = root.dataset.sessionId;
    const orderUrl = root.dataset.orderUrl;
    const bankMethods = parse(root.dataset.methods, []);
    const cashMethod = parse(root.dataset.cash, null);
    let minQuantity = Number(root.dataset.minQuantity ?? 0);
    // 0 = no maximum (System Configuration leaves it empty).
    let maxQuantity = Number(root.dataset.maxQuantity ?? 0);

    // id -> { id, name, price }; a dish the server says is gone is dropped from it.
    const dishes = new Map(parse(root.dataset.dishes, []).map((dish) => [Number(dish.id), { ...dish, id: Number(dish.id) }]));

    // id -> plates ordered of that dish.
    const quantities = new Map();

    const copy = (name) => root.dataset[name] ?? '';

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const money = (value) => '₱' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    const element = (selector) => document.querySelector(selector);

    // Laravel's 422 carries `errors`; the app's own errors carry `message`.
    const messages = (payload) => {
        if (payload && payload.errors) {
            const list = Object.values(payload.errors).flat().filter(Boolean);

            if (list.length) {
                return list;
            }
        }

        return payload && typeof payload.message === 'string' ? [payload.message] : [];
    };

    const platesWord = (count) => (count === 1 ? copy('platesOne') : copy('platesMany'));

    // Cents, not floats: a total is a sum of rounded line totals.
    const lineTotal = (id) => Math.round((dishes.get(id)?.price ?? 0) * (quantities.get(id) ?? 0) * 100) / 100;

    const plates = () => [...quantities.values()].reduce((sum, quantity) => sum + quantity, 0);

    const total = () => Math.round([...quantities.keys()].reduce((sum, id) => sum + lineTotal(id), 0) * 100) / 100;

    /* ---- the dish cards -------------------------------------------------- */

    const summaryPlates = element('#bulk-plates');
    const summaryLabel = element('#bulk-plates-label');
    const summaryTotal = element('#bulk-total');
    const progress = element('#bulk-progress');
    const progressBar = element('#bulk-progress-bar');
    const hint = element('#bulk-hint');
    const reviewButton = element('#bulk-review');
    const search = element('#bulk-search');
    const emptyNote = element('#bulk-empty');

    const cardOf = (id) => document.querySelector('[data-dish-card="' + id + '"]');

    const renderCard = (id, { writeInput = true } = {}) => {
        const card = cardOf(id);

        if (!card) {
            return;
        }

        const quantity = quantities.get(id) ?? 0;
        const input = card.querySelector('[data-qty]');
        const less = card.querySelector('[data-step="-1"]');
        const share = card.querySelector('[data-line-total]');

        if (input && writeInput) {
            input.value = String(quantity);
        }

        if (less) {
            less.disabled = quantity < 1;
        }

        card.dataset.inCart = quantity > 0 ? 'true' : 'false';

        if (share) {
            share.classList.toggle('hidden', quantity < 1);
            share.textContent = quantity > 0
                ? quantity + ' × ' + money(dishes.get(id)?.price ?? 0) + ' = ' + money(lineTotal(id))
                : '';
        }
    };

    const renderSummary = () => {
        const count = plates();
        const need = Math.max(0, minQuantity - count);
        const over = maxQuantity > 0 && count > maxQuantity;
        const reached = count >= minQuantity && count > 0 && !over;

        summaryPlates.textContent = String(count);
        summaryLabel.textContent = platesWord(count);
        summaryTotal.textContent = money(total());

        const percent = minQuantity > 0 ? Math.min(100, Math.round((count / minQuantity) * 100)) : (count > 0 ? 100 : 0);

        progress.style.width = percent + '%';
        progressBar?.setAttribute('aria-valuenow', String(Math.min(count, minQuantity)));
        progressBar?.setAttribute('aria-valuemax', String(minQuantity));

        if (over) {
            hint.textContent = copy('overMax').replace(':n', String(count - maxQuantity)).replace(':max', String(maxQuantity));
        } else {
            hint.textContent = reached ? copy('minReached') : copy('needMore').replace(':n', String(need));
        }

        reviewButton.disabled = !reached;
    };

    // Set one dish's plates (0 takes it out of the order), clamped to a sane range.
    // `fromInput`: the customer is typing in the box, so do not rewrite it under them.
    const setQuantity = (id, value, { fromInput = false } = {}) => {
        if (!dishes.has(id)) {
            return;
        }

        const quantity = Math.min(MAX_QUANTITY, Math.max(0, Math.floor(Number(value) || 0)));

        if (quantity > 0) {
            quantities.set(id, quantity);
        } else {
            quantities.delete(id);
        }

        // Typing "7" on the way to "75": leave the box alone unless it went out of range.
        renderCard(id, { writeInput: !fromInput || String(value) !== String(quantity) });
        renderSummary();
    };

    document.querySelectorAll('[data-dish-card]').forEach((card) => {
        const id = Number(card.dataset.dishCard);
        const input = card.querySelector('[data-qty]');

        card.querySelectorAll('[data-step]').forEach((button) => {
            button.addEventListener('click', () => {
                setQuantity(id, (quantities.get(id) ?? 0) + Number(button.dataset.step));
            });
        });

        input?.addEventListener('input', () => setQuantity(id, input.value, { fromInput: true }));
        // Leaving the box tidies what was typed ("", "007", "12.5").
        input?.addEventListener('change', () => setQuantity(id, input.value));
    });

    search?.addEventListener('input', () => {
        const needle = search.value.trim().toLowerCase();
        let shown = 0;

        document.querySelectorAll('[data-dish-card]').forEach((card) => {
            const match = needle === '' || (card.dataset.name ?? '').includes(needle);

            card.classList.toggle('hidden', !match);
            shown += match ? 1 : 0;
        });

        emptyNote?.classList.toggle('hidden', shown > 0);
    });

    /* ---- the checkout modal ---------------------------------------------- */

    const formPanel = element('#bulk-checkout-form');
    const receiptPanel = element('#bulk-receipt');
    const errorsBox = element('#bulk-errors');
    const linesList = element('#bulk-lines');
    const checkoutTotal = element('#bulk-checkout-total');
    const placeButton = element('#bulk-place');
    const backButton = element('#bulk-back');
    const backLabel = backButton?.textContent ?? '';
    const placeLabel = placeButton?.textContent ?? '';

    let methodId = null;
    let proofFile = null;
    let placing = false;
    let orderKey = { value: '', fingerprint: '' };

    const showErrors = (list) => {
        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        errorsBox.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        errorsBox.classList.toggle('hidden', items.length === 0);

        if (items.length > 0) {
            errorsBox.scrollIntoView({ block: 'nearest' });
        }
    };

    // The red shake the proof box and the payment tiles get when they are what is wrong.
    const flag = (nodes) => {
        const list = (Array.isArray(nodes) ? nodes : [nodes]).filter(Boolean);

        list.forEach((node) => {
            node.classList.add('ring-2', 'ring-red-400');
            setTimeout(() => node.classList.remove('ring-2', 'ring-red-400'), 1800);
        });
    };

    const renderLines = () => {
        const rows = [...quantities.keys()].map((id) => ({
            name: dishes.get(id)?.name ?? '',
            quantity: quantities.get(id),
            sub: lineTotal(id),
        }));

        linesList.innerHTML = rows.map((row) => `
            <li class="flex items-center justify-between gap-4 px-4 py-2 text-sm">
                <span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200">
                    <span class="tabular-nums text-gray-500 dark:text-gray-400">${row.quantity}×</span> ${escapeHtml(row.name)}
                </span>
                <span class="tabular-nums text-gray-900 dark:text-white">${money(row.sub)}</span>
            </li>`).join('') + `
            <li class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                ${plates()} ${escapeHtml(platesWord(plates()))}
            </li>`;

        checkoutTotal.textContent = money(total());
    };

    /* ---- payment: the methods ------------------------------------------- */

    const baseTile = 'flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left transition focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:focus:ring-indigo-900';
    const idleTile = 'border-gray-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/40 dark:border-gray-600 dark:bg-gray-700/40 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20';
    const pickedTile = 'border-gray-900 bg-gray-50 ring-2 ring-gray-200 dark:border-gray-300 dark:bg-gray-700/60 dark:ring-gray-600';

    const bankHost = element('#bulk-bank-methods');
    const bankPanel = element('#bulk-bank-panel');
    const cashPanel = element('#bulk-cash-panel');
    const methodDetails = element('#bulk-method-details');
    const referenceInput = element('#bulk-reference');

    const pickedMethod = () => {
        if (methodId === null) {
            return null;
        }

        if (cashMethod && Number(cashMethod.method_id) === Number(methodId)) {
            return cashMethod;
        }

        return bankMethods.find((method) => Number(method.method_id) === Number(methodId)) ?? null;
    };

    const isCash = (method) => !!method && !!cashMethod && Number(method.method_id) === Number(cashMethod.method_id);

    const methodTemplate = (method) => {
        const picked = Number(method.method_id) === Number(methodId);
        const account = [method.account_name, method.account_number].filter(Boolean).join(' · ');

        return `
            <button type="button" data-method-id="${method.method_id}" aria-pressed="${picked ? 'true' : 'false'}"
                    class="${baseTile} ${picked ? pickedTile : idleTile}">
                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white ring-1 ring-gray-200 dark:bg-gray-700 dark:ring-gray-600">
                    ${method.qr_image
                        ? `<img src="${escapeHtml(method.qr_image)}" alt="" class="h-full w-full object-contain p-0.5" onerror="this.remove()">`
                        : '<svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5" /></svg>'}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-gray-900 dark:text-white">${escapeHtml(method.method_name)}</span>
                    ${account ? `<span class="mt-0.5 block truncate text-xs text-gray-500 dark:text-gray-400">${escapeHtml(account)}</span>` : ''}
                </span>
            </button>`;
    };

    // The file name a saved QR gets: "gcash-qr.png".
    const qrFileName = (method) => {
        const extension = (String(method.qr_image).split('?')[0].match(/\.(png|jpe?g|gif|webp)$/i) ?? [])[0] ?? '.png';
        const slug = String(method.method_name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'payment';

        return slug + '-qr' + extension;
    };

    const renderMethods = () => {
        if (bankHost) {
            bankHost.innerHTML = bankMethods.map(methodTemplate).join('');
        }

        // The cash tile is server rendered: only its look follows the choice.
        const cashTile = document.querySelector('[data-cash-tile]');

        if (cashTile && cashMethod) {
            const picked = Number(methodId) === Number(cashMethod.method_id);

            cashTile.className = baseTile + ' ' + (picked ? pickedTile : idleTile);
            cashTile.setAttribute('aria-pressed', picked ? 'true' : 'false');
        }

        const method = pickedMethod();

        cashPanel?.classList.toggle('hidden', !isCash(method));
        bankPanel?.classList.toggle('hidden', !method || isCash(method));

        if (!method || isCash(method) || !methodDetails) {
            return;
        }

        methodDetails.innerHTML = `
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                ${method.qr_image
                    ? `<div class="mx-auto flex-shrink-0 sm:mx-0">
                           <img src="${escapeHtml(method.qr_image)}" alt="${escapeHtml(method.method_name)}" data-zoom data-zoom-caption="${escapeHtml(method.method_name)}" title="${escapeHtml(copy('zoomHint'))}"
                                class="h-40 w-40 cursor-zoom-in rounded-xl bg-white object-contain p-1 ring-1 ring-gray-200 dark:ring-gray-600">
                       </div>`
                    : ''}
                <div class="min-w-0 flex-1">
                    <p class="text-xs text-gray-500 dark:text-gray-400">${escapeHtml(copy('scanLabel'))}</p>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy('methodLabel'))}</dt>
                            <dd class="text-gray-900 dark:text-white">${escapeHtml(method.method_name)}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy('accountNameLabel'))}</dt>
                            <dd class="text-gray-900 dark:text-white">${escapeHtml(method.account_name || '—')}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">${escapeHtml(copy('accountNumberLabel'))}</dt>
                            <dd class="tabular-nums text-gray-900 dark:text-white">${escapeHtml(method.account_number || '—')}</dd>
                        </div>
                    </dl>
                </div>
            </div>
            ${method.qr_image
                ? `<a href="${escapeHtml(method.qr_image)}" download="${escapeHtml(qrFileName(method))}"
                      class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                       <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" /></svg>
                       ${escapeHtml(copy('saveQrLabel') || 'Save QR')}
                   </a>`
                : ''}`;
    };

    formPanel?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-method-id]');

        if (!button) {
            return;
        }

        const clicked = Number(button.dataset.methodId);

        // Tapping the open method again closes it.
        methodId = clicked === Number(methodId) ? null : clicked;

        renderMethods();
        showErrors([]);

        if (methodId !== null && !isCash(pickedMethod())) {
            methodDetails?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });

    /* ---- payment: the proof of payment ----------------------------------- */

    const setupProofUpload = () => {
        const zone = element('#bulk-proof-zone');
        const input = element('#bulk-proof-input');
        const preview = element('#bulk-proof-preview');
        const remove = element('#bulk-proof-remove');
        const emptyContent = element('#bulk-proof-empty');
        const filled = element('#bulk-proof-filled');
        const info = element('#bulk-proof-info');

        if (!zone || !input || !preview) {
            return () => {};
        }

        const showPreview = (file) => {
            proofFile = file && file.type.startsWith('image/') ? file : null;

            if (!proofFile) {
                preview.src = '';
                filled?.classList.add('hidden');
                emptyContent?.classList.remove('hidden');
                info && (info.textContent = '');

                return;
            }

            const reader = new FileReader();

            reader.onload = (event) => {
                preview.src = event.target.result;
                filled?.classList.remove('hidden');
                emptyContent?.classList.add('hidden');
                info && (info.textContent = proofFile.name + ' · ' + Math.max(1, Math.round(proofFile.size / 1024)) + ' KB');
            };
            reader.readAsDataURL(proofFile);
        };

        // The hidden input covers the zone, so clicking the zone already opens the picker.
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

        ['dragenter', 'dragover'].forEach((name) => {
            zone.addEventListener(name, (event) => { event.preventDefault(); event.stopPropagation(); highlight(true); });
        });

        ['dragleave', 'drop'].forEach((name) => {
            zone.addEventListener(name, (event) => { event.preventDefault(); event.stopPropagation(); highlight(false); });
        });

        zone.addEventListener('drop', (event) => {
            const files = event.dataTransfer?.files;

            if (!files?.length) {
                return;
            }

            const transfer = new DataTransfer();

            transfer.items.add(files[0]);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        input.addEventListener('change', () => showPreview(input.files?.[0] ?? null));

        remove?.addEventListener('click', () => {
            input.value = '';
            showPreview(null);
        });

        // Placing the order empties it.
        return () => {
            input.value = '';
            showPreview(null);
        };
    };

    const clearProof = setupProofUpload();

    /* ---- opening the checkout -------------------------------------------- */

    const showForm = () => {
        formPanel.classList.remove('hidden');
        receiptPanel.classList.add('hidden');
        placeButton.classList.remove('hidden');
        backButton.textContent = backLabel;
    };

    reviewButton.addEventListener('click', () => {
        if (plates() < minQuantity || plates() === 0) {
            return;
        }

        showForm();
        showErrors([]);
        renderLines();
        renderMethods();
        emit('open-modal', 'bulk-checkout-modal');
    });

    backButton.addEventListener('click', () => emit('close-modal', 'bulk-checkout-modal'));

    /* ---- placing the order ----------------------------------------------- */

    const newKey = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2));

    // One key per distinct cart + payment method, so a retry of the same order after a
    // lost answer returns the same receipt, while a changed cart is a new order.
    const orderKeyFor = (identifier) => {
        const fingerprint = [...quantities.keys()].map((id) => id + 'x' + quantities.get(id) + '@' + (dishes.get(id)?.price ?? 0)).sort().join(',') + '|' + identifier;

        if (orderKey.fingerprint !== fingerprint) {
            orderKey = { value: newKey(), fingerprint };
        }

        return orderKey.value;
    };

    // The server's answer to "review your cart": new prices, dishes that left, a new minimum.
    const applyReview = (payload) => {
        if (payload.min_quantity !== undefined) {
            minQuantity = Number(payload.min_quantity);
        }

        // null (no limit) arrives as null: keep it as 0 here.
        if (payload.max_quantity !== undefined) {
            maxQuantity = Number(payload.max_quantity ?? 0);
        }

        if (payload.prices && typeof payload.prices === 'object') {
            const current = new Map(Object.entries(payload.prices).map(([id, price]) => [Number(id), Number(price)]));

            for (const id of [...dishes.keys()]) {
                if (!current.has(id)) {
                    // Off the bulk menu: out of the order, and off the page.
                    dishes.delete(id);
                    quantities.delete(id);
                    cardOf(id)?.remove();

                    continue;
                }

                dishes.get(id).price = current.get(id);

                const label = document.querySelector('[data-price-label="' + id + '"]');

                if (label) {
                    label.textContent = money(current.get(id));
                }

                renderCard(id);
            }
        }

        renderSummary();
        renderLines();
    };

    const renderReceipt = (receipt) => {
        formPanel.classList.add('hidden');
        placeButton.classList.add('hidden');
        backButton.textContent = copy('doneLabel') || 'Done';

        const ordersUrl = copy('myOrdersUrl');

        receiptPanel.innerHTML = `
            <div class="rounded-2xl bg-gray-50 p-6 text-center dark:bg-gray-700/40">
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4 4L19 7" /></svg>
                </span>
                <h4 class="mt-3 text-lg font-bold text-gray-900 dark:text-white">${escapeHtml(receipt.message)}</h4>
                <dl class="mx-auto mt-4 max-w-xs space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(copy('orderLabel'))}</dt>
                        <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">${escapeHtml(receipt.number)}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(platesWord(receipt.plates))}</dt>
                        <dd class="tabular-nums text-gray-900 dark:text-white">${receipt.plates}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">${escapeHtml(receipt.method)}</dt>
                        <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">${money(receipt.total)}</dd>
                    </div>
                </dl>
                ${receipt.cash ? '' : `<p class="mt-4 text-xs text-gray-500 dark:text-gray-400">${escapeHtml(copy('verifyingLabel'))}</p>`}
                ${ordersUrl ? `<a href="${escapeHtml(ordersUrl)}" class="mt-4 inline-flex items-center justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">${escapeHtml(copy('myOrdersLabel'))}</a>` : ''}
            </div>`;

        receiptPanel.classList.remove('hidden');
        checkoutTotal.textContent = money(receipt.total);
    };

    const placeOrder = async () => {
        if (placing) {
            return;
        }

        const method = pickedMethod();

        if (!method) {
            showErrors([cashMethod || bankMethods.length ? copy('chooseMethod') : copy('noOptions')]);
            flag([...document.querySelectorAll('#bulk-checkout-form [data-method-id]')]);

            return;
        }

        if (plates() < minQuantity) {
            showErrors([copy('belowMinimum').replace(':min', String(minQuantity)).replace(':n', String(plates()))]);

            return;
        }

        if (maxQuantity > 0 && plates() > maxQuantity) {
            showErrors([copy('aboveMaximum').replace(':max', String(maxQuantity)).replace(':n', String(plates()))]);

            return;
        }

        // Anything but cash is paid first, so the proof is required.
        if (!isCash(method) && !proofFile) {
            showErrors([copy('proofRequired')]);
            flag(element('#bulk-proof-zone'));

            return;
        }

        placing = true;
        placeButton.disabled = true;
        placeButton.textContent = placeButton.dataset.loadingLabel ?? placeLabel;

        try {
            // The order posts like a form: the lines are the items array, and the proof
            // rides along as a file.
            const data = new FormData();

            data.append('_token', csrfToken());
            data.append('bulk_session_id', sessionId);
            data.append('payment_method_id', String(method.method_id));
            data.append('idempotency_key', orderKeyFor(method.method_id));

            if (!isCash(method) && referenceInput?.value.trim()) {
                data.append('reference_number', referenceInput.value.trim());
            }

            if (!isCash(method) && proofFile) {
                data.append('proof_of_payment', proofFile);
            }

            for (const [id, quantity] of quantities) {
                data.append('items[' + id + ']', String(quantity));
                // The price the customer saw (and paid, for a bank order): the server
                // refuses the order when it is not the price any more.
                data.append('expected_prices[' + id + ']', String(dishes.get(id)?.price ?? 0));
            }

            const response = await fetch(orderUrl, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: data,
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                // Prices changed, a dish left, or the minimum moved: show the real numbers
                // and let the customer review the order instead of retrying into the same refusal.
                if (payload.prices) {
                    applyReview(payload);
                }

                showErrors(messages(payload));

                if (payload.errors) {
                    flag(payload.errors.proof_of_payment ? element('#bulk-proof-zone') : []);
                }

                return;
            }

            // Placed: the next order is a new one with a new key.
            orderKey = { value: '', fingerprint: '' };

            const receipt = {
                message: payload.message ?? '',
                number: payload.order_number ?? '',
                total: Number(payload.total_amount ?? 0),
                plates: Number(payload.plates ?? plates()),
                method: method.method_name,
                cash: isCash(method),
            };

            // Empty the order, then show the receipt.
            for (const id of [...quantities.keys()]) {
                quantities.delete(id);
                renderCard(id);
            }

            methodId = null;
            referenceInput && (referenceInput.value = '');
            clearProof();
            renderSummary();
            renderMethods();
            renderReceipt(receipt);
        } catch (error) {
            console.warn('The bulk order could not be sent.', error);
            showErrors([copy('sendFailed')]);
            showToast(copy('sendFailed'), 'error');
        } finally {
            placing = false;
            placeButton.disabled = false;
            placeButton.textContent = placeLabel;
        }
    };

    placeButton.addEventListener('click', placeOrder);

    renderSummary();
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
