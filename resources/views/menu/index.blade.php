@php
    // The window the running session is open, for the header line.
    $window = $session ? substr($session->start_time, 0, 5) . ' – ' . substr($session->end_time, 0, 5) : '';
    $date = $session ? \Illuminate\Support\Carbon::parse($session->order_date)->format('M j, Y') : '';
    $typeChip = 'inline-flex items-center rounded-md border border-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300';

    // The +/- buttons on a card. There is no Add to Cart button: the card's
    // quantity starts at 0 and IS the dish's quantity in the cart.
    $stepButtonClass = 'flex h-12 w-12 items-center justify-center text-gray-600 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-300 dark:hover:bg-gray-700';

    // The two ways to pay, as the checkout offers them. Cash on pickup is paid
    // when the order is collected; a bank or e-wallet pays is sent first and
    // proved with a reference number and a screenshot.
    $payTile = 'flex w-full items-start gap-3 rounded-xl border p-3 text-left transition focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:focus:ring-indigo-900';
    $payTileIdle = 'border-gray-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/40 dark:border-gray-600 dark:bg-gray-700/40 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Menu') }}</h2>

                @if ($session)
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        {{ $date }} · <span class="tabular-nums">{{ $window }}</span>
                        · {{ __('Open until') }} <span class="tabular-nums">{{ substr($session->end_time, 0, 5) }}</span>
                    </p>
                @else
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('No order session is running right now.') }}</p>
                @endif
            </div>

            @if ($session && $dishes->isNotEmpty())
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                    {{-- Behind the same permission the page checks. --}}
                    @can('view my orders')
                        <a href="{{ route('my-orders.index') }}"
                           class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" />
                            </svg>
                            {{ __('My Orders') }}
                        </a>
                    @endcan
                </div>
            @else
                @can('view my orders')
                    <a href="{{ route('my-orders.index') }}"
                       class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        {{ __('My Orders') }}
                    </a>
                @endcan
            @endif
        </div>
    </x-slot>

    {{--
        The page wiring: resources/js/pages/menu.js reads these `data-*`
        attributes, so no URL, copy or choice is hard coded in the script.
        `data-session-id` is empty when nothing is running, `data-methods` is the
        bank / e-wallet list and `data-cash` the pay-on-pickup method.
    --}}
    <div id="menu-root"
         class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8"
         data-session-id="{{ $session->session_id ?? '' }}"
         data-order-url="{{ route('orders.store') }}"
         data-methods='@json($methods)'
         data-cash='@json($cash)'
         data-dishes='@json($dishes)'
         data-step-progress="{{ __('Step :current of :total') }}"
         data-empty-cart="{{ __('Your cart is empty. Pick a dish from the menu.') }}"
         data-stock-left="{{ __('left') }}"
         data-stock-none="{{ __('Sold out') }}"
         data-kcal-label="{{ __('kcal') }}"
         data-add-label="{{ __('Add to Cart') }}"
         data-in-cart-label="{{ __('Added to cart') }}"
         data-cart-label="{{ __('Cart') }}"
         data-each-label="{{ __('each') }}"
         data-items-label="{{ __('items') }}"
         data-item-label="{{ __('item') }}"
         data-dishes-label="{{ __('dishes') }}"
         data-dish-label="{{ __('dish') }}"
         data-choose-payment="{{ __('Choose a payment method.') }}"
         data-proof-required="{{ __('Upload the proof of payment to continue.') }}"
         data-choose-method="{{ __('Choose a payment method.') }}"
         data-no-options="{{ __('No payment option has been set up yet.') }}"
         data-no-methods="{{ __('There is no payment method to choose from yet.') }}"
         data-send-failed="{{ __('The order could not be sent. Please try again.') }}"
         data-remove-label="{{ __('Remove') }}"
         data-less-label="{{ __('One less') }}"
         data-more-label="{{ __('One more') }}"
         data-method-label="{{ __('Payment Method') }}"
         data-account-name-label="{{ __('Account Name') }}"
         data-account-number-label="{{ __('Account Number') }}"
         data-reference-label="{{ __('Reference Number') }}"
         data-proof-label="{{ __('Proof of Payment') }}"
         data-lines-label="{{ __('Your Order') }}"
         data-placed-label="{{ __('Order Placed') }}"
         data-verifying-label="{{ __('Your payment is waiting to be verified. Keep your reference number.') }}"
         data-zoom-hint="{{ __('Click to enlarge') }}"
         data-change-label="{{ __('Change') }}"
         data-save-qr-label="{{ __('Save QR') }}"
         data-scan-label="{{ __('Scan the QR code, or send the payment to the account below.') }}"
         data-next-review="{{ __('Proceed to Payment') }}"
         data-next-payment="{{ __('Continue') }}"
         data-next-confirm="{{ __('Place Order') }}"
         data-next-placed="{{ __('Done') }}"
         data-back-menu="{{ __('Back to Menu') }}"
         data-back-step="{{ __('Back') }}"
         data-my-orders-url="{{ auth()->user()->can('view my orders') ? route('my-orders.index') : '' }}"
         data-my-orders-label="{{ __('See my orders') }}"
         data-order-label="{{ __('Order Number') }}">

        @if (! $session)
            {{-- Nothing is running: say so instead of showing an empty menu. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <svg class="mx-auto h-10 w-10 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <h3 class="mt-3 text-base font-semibold text-gray-900 dark:text-white">{{ __('No order session is running') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('The menu opens when an order session starts.') }}</p>
            </div>
        @elseif ($dishes->isEmpty())
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Nothing on the menu yet') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('This order session does not offer any dish.') }}</p>
            </div>
        @else
            @php
                // The dish types this session offers, with how many dishes carry
                // each: that is the kiosk's category rail, and the first filter.
                // Grouped by name, so two type rows that share a name read as one
                // category instead of two identical chips.
                $types = [];
                foreach ($dishes as $dish) {
                    foreach ($dish->dish_types ?? [] as $type) {
                        $types[$type['type_name']] = ($types[$type['type_name']] ?? 0) + 1;
                    }
                }
                ksort($types);

                // What the price and calorie boxes can be narrowed to.
                $prices = array_map(static fn ($dish) => (float) $dish->price, $dishes->all());
                $calories = array_filter(array_map(static fn ($dish) => (int) ($dish->calories ?? 0), $dishes->all()));

                $chip = 'inline-flex shrink-0 items-center gap-2 rounded-full border px-5 py-2.5 text-sm font-medium transition focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:focus:ring-indigo-900';
                $chipOff = $chip . ' border-gray-200 bg-white text-gray-700 hover:border-indigo-400 hover:text-indigo-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:border-indigo-500';
                $chipOn = $chip . ' border-indigo-600 bg-indigo-600 text-white shadow-sm';
                $field = 'h-12 w-full rounded-xl border border-gray-300 bg-white px-4 text-base text-gray-900 placeholder:text-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:placeholder:text-gray-500 dark:focus:ring-indigo-900';
            @endphp

            {{--
                The kiosk bar: search on the left, the price and calorie boxes
                behind the Filters button, and the dish types as categories along
                the bottom. All of it works on the dishes already on the page, so
                narrowing the menu never asks the server for anything — see
                resources/js/pages/menu.js.
            --}}
            <section class="mb-5 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <label class="relative block flex-1">
                        <span class="sr-only">{{ __('Search the menu') }}</span>
                        <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                        </svg>
                        <input type="search" id="menu-search" autocomplete="off"
                               class="{{ $field }} pl-12"
                               placeholder="{{ __('Search a dish') }}">
                    </label>

                    <div class="flex items-center gap-3">
                        <button type="button" id="filter-toggle" aria-expanded="false" aria-controls="filter-panel"
                                class="inline-flex h-12 flex-1 items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 lg:flex-none dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 0 1-.659 1.591l-5.432 5.432a2.25 2.25 0 0 0-.659 1.591v2.927a2.25 2.25 0 0 1-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 0 0-.659-1.591L3.659 7.409A2.25 2.25 0 0 1 3 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0 1 12 3Z" />
                            </svg>
                            {{ __('Filters') }}
                            <span id="filter-count" class="hidden rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-semibold tabular-nums text-white">0</span>
                        </button>

                        {{-- How many dishes are on screen right now. --}}
                        <p id="menu-count" class="shrink-0 text-sm tabular-nums text-gray-500 dark:text-gray-400">{{ $dishes->count() }} {{ __('dishes') }}</p>
                    </div>
                </div>

                {{-- Price and calories: the other two ways a kiosk narrows a menu. --}}
                <div id="filter-panel" class="mt-4 hidden border-t border-gray-200 pt-4 dark:border-gray-700">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Price from') }}</span>
                            <input type="number" id="filter-min-price" min="0" step="0.01" inputmode="decimal" class="{{ $field }}"
                                   placeholder="{{ number_format($prices ? min($prices) : 0, 2) }}">
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Price to') }}</span>
                            <input type="number" id="filter-max-price" min="0" step="0.01" inputmode="decimal" class="{{ $field }}"
                                   placeholder="{{ number_format($prices ? max($prices) : 0, 2) }}">
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Calories from') }}</span>
                            <input type="number" id="filter-min-calories" min="0" step="1" inputmode="numeric" class="{{ $field }}"
                                   placeholder="{{ $calories ? min($calories) : 0 }}">
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Calories to') }}</span>
                            <input type="number" id="filter-max-calories" min="0" step="1" inputmode="numeric" class="{{ $field }}"
                                   placeholder="{{ $calories ? max($calories) : 0 }}">
                        </label>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Leave a box empty to ignore it.') }}</p>

                        <button type="button" id="filter-clear"
                                class="rounded-lg px-3 py-2 text-sm font-medium text-indigo-600 transition hover:bg-indigo-50 focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:focus:ring-indigo-900">
                            {{ __('Clear filters') }}
                        </button>
                    </div>
                </div>

                {{-- Categories: one tap narrows the menu to a dish type. --}}
                <div id="menu-types" class="mt-4 flex gap-2 overflow-x-auto pb-1"
                     data-chip-on="{{ $chipOn }}" data-chip-off="{{ $chipOff }}">
                    <button type="button" data-filter-type="" aria-pressed="true" class="{{ $chipOn }}">
                        {{ __('All') }} <span class="tabular-nums opacity-70">{{ $dishes->count() }}</span>
                    </button>

                    @foreach ($types as $typeName => $typeCount)
                        <button type="button" data-filter-type="{{ $typeName }}" aria-pressed="false" class="{{ $chipOff }}">
                            {{ $typeName }} <span class="tabular-nums opacity-70">{{ $typeCount }}</span>
                        </button>
                    @endforeach
                </div>
            </section>

            <div class="grid grid-cols-1 gap-4 pb-24 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($dishes as $dish)
                    @php
                        $stock = (int) $dish->available_stock;
                        $soldOut = $stock < 1;
                    @endphp

                    {{-- One card per dish. The picture and the details open the
                         dish modal; the + below puts one in the cart. --}}
                    <article class="flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition hover:border-indigo-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-500"
                             data-dish-card="{{ $dish->session_dish_id }}">
                        <div role="button" tabindex="0"
                             data-dish-view="{{ $dish->session_dish_id }}"
                             aria-label="{{ __('View details of :dish', ['dish' => $dish->dish_name]) }}"
                             class="group flex flex-1 cursor-pointer flex-col focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">
                            <div class="relative flex h-44 w-full items-center justify-center overflow-hidden bg-gray-100 dark:bg-gray-700">
                                <svg class="h-8 w-8 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                                </svg>
                                @if ($dish->image)
                                    <img src="{{ $dish->image }}" alt="" loading="lazy"
                                         class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-105" onerror="this.remove()">
                                @endif

                                {{-- Says the card opens something, without taking room when idle. --}}
                                <span class="absolute bottom-2 right-2 inline-flex items-center gap-1 rounded-full bg-gray-900/70 px-2 py-1 text-xs font-medium text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                    {{ __('View') }}
                                </span>
                            </div>

                            <div class="flex flex-1 flex-col gap-1 p-4">
                                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $dish->dish_name }}</h3>

                                @if (! empty($dish->dish_types))
                                    <p class="flex flex-wrap items-center gap-1">
                                        @foreach ($dish->dish_types as $type)
                                            <span class="{{ $typeChip }}">{{ $type['type_name'] }}</span>
                                        @endforeach
                                    </p>
                                @endif

                                <p class="mt-2 text-xl font-semibold tabular-nums text-indigo-600 dark:text-indigo-400">${{ number_format((float) $dish->price, 2) }}</p>

                                <p class="text-xs tabular-nums text-gray-500 dark:text-gray-400"
                                   data-stock-label="{{ $dish->session_dish_id }}">{{ $soldOut ? __('Sold out') : $stock . ' ' . __('left') }}</p>
                            </div>
                        </div>

                        <div class="flex items-center justify-between gap-2 border-t border-gray-100 px-3 py-3 dark:border-gray-700/60">
                            {{-- Shown while this dish is in the cart (menu.js). --}}
                            <span data-in-cart class="hidden items-center gap-1.5 text-sm font-medium text-indigo-600 dark:text-indigo-400">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="m5 13 4 4L19 7" />
                                </svg>
                                {{ __('Added to cart') }}
                            </span>

                            {{-- How many of this dish are in the cart: 0 until the first +. --}}
                            <div class="ml-auto flex shrink-0 items-center rounded-xl border border-gray-300 dark:border-gray-600">
                                <button type="button"
                                        data-card-step="-1"
                                        aria-label="{{ __('One less') }}"
                                        disabled
                                        class="{{ $stepButtonClass }} rounded-l-xl">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                        <path d="M5 12h14" />
                                    </svg>
                                </button>

                                <span data-card-qty
                                      class="w-7 text-center text-base font-semibold tabular-nums text-gray-900 dark:text-white">0</span>

                                <button type="button"
                                        data-card-step="1"
                                        aria-label="{{ __('One more') }}"
                                        @disabled($soldOut)
                                        class="{{ $stepButtonClass }} rounded-r-xl">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                        <path d="M12 5v14M5 12h14" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            {{-- What a narrowed-down menu looks like when nothing is left. --}}
            <div id="menu-empty" class="hidden rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('No dish matches') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Try another dish type, or widen the price and calorie boxes.') }}</p>
            </div>

            {{-- The cart: round, lower right, visible at all times (empty or not). --}}
            <div id="cart-bar" class="fixed bottom-6 right-6 z-40">
                <button type="button" data-open-cart id="cart-fab"
                        class="relative flex h-16 w-16 items-center justify-center rounded-full bg-indigo-600 text-white shadow-2xl transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                    </svg>

                    {{-- How many dishes are waiting in the cart. --}}
                    <span id="cart-bar-count"
                          class="absolute -right-1 -top-1 flex h-7 min-w-[1.75rem] items-center justify-center rounded-full bg-white px-1.5 text-sm font-bold tabular-nums text-indigo-700 ring-2 ring-indigo-600 transition-transform duration-200 dark:bg-gray-900 dark:text-white dark:ring-indigo-500">0</span>
                </button>
            </div>
        @endif
    </div>

    {{--
        Dish details: opened by clicking (or pressing Enter on) a dish card. It
        is filled by resources/js/pages/menu.js from the dish data already on the
        page, so opening it costs no request.
    --}}
    <x-ui.modal name="dish-modal" size="lg">
        <div class="flex max-h-[85vh] flex-col">
            <div class="relative flex h-40 w-full shrink-0 items-center justify-center overflow-hidden bg-gray-100 dark:bg-gray-700">
                <svg id="dish-modal-placeholder" class="h-10 w-10 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                </svg>

                <x-ui.zoomable-image id="dish-modal-image" src="" alt="" class="absolute inset-0 hidden h-full w-full object-cover" />

                <button type="button"
                        class="absolute right-3 top-3 flex h-9 w-9 items-center justify-center rounded-full bg-gray-900/60 text-white transition hover:bg-gray-900/80 focus:outline-none focus:ring-2 focus:ring-white/70"
                        x-on:click="show = false">
                    <span class="sr-only">{{ __('Close') }}</span>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 id="dish-modal-name" class="text-lg font-semibold text-gray-900 dark:text-white"></h3>
                        <p id="dish-modal-types" class="mt-1 flex flex-wrap items-center gap-1"></p>
                    </div>

                    <p id="dish-modal-price" class="text-xl font-semibold tabular-nums text-indigo-600 dark:text-indigo-400"></p>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm tabular-nums text-gray-500 dark:text-gray-400">
                    <span id="dish-modal-stock"></span>
                    <span id="dish-modal-calories"></span>
                </div>

                <dl class="mt-4 space-y-3.5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Description') }}</dt>
                        <dd id="dish-modal-description" class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300"></dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Main Ingredients') }}</dt>
                        <dd id="dish-modal-ingredients" class="mt-1 text-sm text-gray-700 dark:text-gray-300"></dd>
                    </div>
                </dl>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                <button type="button" id="dish-modal-close"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                    {{ __('Close') }}
                </button>

                <button type="button" id="dish-modal-add"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-indigo-800">
                    {{ __('Add to Cart') }}
                </button>
            </div>
        </div>
    </x-ui.modal>

    {{--
        Checkout: one modal, three steps. Review the order, choose how to pay,
        then confirm. The progress bar and the step dots are driven by
        resources/js/pages/menu.js; every panel is in the DOM, so moving between
        the steps never refetches anything.
    --}}
    <x-ui.modal name="checkout-modal" size="2xl">
        <div class="flex max-h-[85vh] flex-col">

            {{-- Header: what this is, and where we are --}}
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-6 py-3 dark:border-gray-700">
                <div>
                    <h3 class="flex flex-wrap items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                        {{ __('Checkout') }}
                        <span id="checkout-step-name" class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"></span>
                    </h3>
                    <p id="checkout-step-progress" class="mt-0.5 text-xs text-gray-500 dark:text-gray-400"></p>
                </div>
                <button type="button"
                        class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                        x-on:click="show = false">
                    <span class="sr-only">{{ __('Close') }}</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Progress: a bar, plus the steps by name --}}
            <div class="border-b border-gray-200 px-6 py-3 dark:border-gray-700">
                <div id="checkout-progress-bar"
                     class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                     role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <div id="checkout-progress" class="h-full w-0 rounded-full bg-indigo-600 transition-all duration-300 ease-out"></div>
                </div>

                <ol class="mt-3 grid grid-cols-3 gap-2">
                    @foreach ([
                        ['key' => 'review', 'name' => __('Review Order')],
                        ['key' => 'payment', 'name' => __('Payment')],
                        ['key' => 'confirm', 'name' => __('Confirm Order')],
                    ] as $index => $progressStep)
                        <li class="flex items-center gap-2">
                            <span data-step-dot="{{ $progressStep['key'] }}" data-step-name="{{ $progressStep['name'] }}" data-step-number="{{ $index + 1 }}"
                                  class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-xs font-semibold text-gray-400 dark:border-gray-600 dark:bg-gray-700">
                                {{ $index + 1 }}
                            </span>
                            <span data-step-caption="{{ $progressStep['key'] }}" class="truncate text-xs font-medium text-gray-400 dark:text-gray-500">{{ $progressStep['name'] }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Body: one panel per step --}}
            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                <div id="checkout-errors" role="alert" aria-live="polite" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

                {{-- Step 1: review the order --}}
                <div data-step-panel="review">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Your Order') }}</h4>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Change the amounts here, or take a dish out.') }}</p>

                    <div id="cart-lines" class="mt-3 space-y-3"></div>

                    <div id="cart-empty" class="hidden rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-600">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Your cart is empty. Pick a dish from the menu.') }}</p>
                    </div>
                </div>

                {{-- Step 2: how the customer pays --}}
                <div data-step-panel="payment" class="hidden">
                    {{-- One flat list of payment methods: cash on pickup first, then the
                         bank / e-wallet methods (filled in by menu.js into #bank-methods). --}}
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @if ($cash)
                            <button type="button" data-pay-choice="cash" class="{{ $payTile }} {{ $payTileIdle }}">
                                <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                                    </svg>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ $cash->method_name }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __('Pay at the counter when you pick your order up.') }}</span>
                                </span>
                                {{-- An arrow, not a radio dot: picking cash moves on to the confirmation. --}}
                                <svg class="h-4 w-4 flex-shrink-0 self-center text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6" /></svg>
                            </button>
                        @endif

                        {{-- `contents`: the method rows join the list above, and menu.js
                             opens the picked method's panel right under its row. --}}
                        <div id="bank-methods" class="contents"></div>
                    </div>

                    @if (! $cash && $methods->isEmpty())
                        <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{{ __('No payment option has been set up yet.') }}</p>
                    @endif

                    {{-- Cash on pickup: nothing else to fill in --}}
                    <div data-pay-panel="cash" class="mt-4 hidden rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                        <p class="text-sm text-emerald-900 dark:text-emerald-200">{{ __('Nothing to send now. The counter takes the payment when you collect your order.') }}</p>
                    </div>

                    {{-- A bank / e-wallet method is picked: where to send the money, then the reference and the proof --}}
                    <div data-pay-panel="bank" class="hidden space-y-4 sm:col-span-2 rounded-xl border border-indigo-200 bg-indigo-50/30 p-4 dark:border-indigo-900 dark:bg-indigo-900/10">
                        <div id="method-details" class="hidden rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-gray-700/30"></div>

                        <div id="payment-fields" class="hidden space-y-4">
                            <div>
                                <label for="checkout-reference" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('Reference Number') }}
                                    <span class="font-normal text-gray-500 dark:text-gray-400">({{ __('optional') }})</span>
                                </label>
                                <input type="text" id="checkout-reference" maxlength="255"
                                       placeholder="{{ __('e.g. the last digits of the transfer') }}"
                                       class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400">
                            </div>

                            <div>
                                <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('Proof of Payment') }}
                                    <span class="font-semibold text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                                    <span class="sr-only">({{ __('required') }})</span>
                                </span>

                                <!-- Drag & drop upload zone for the receipt screenshot -->
                                <div id="proof-drop-zone"
                                     class="relative mt-1 cursor-pointer rounded-xl border-2 border-dashed border-gray-400 bg-white p-3 text-center transition-colors duration-200 hover:border-indigo-400 hover:bg-indigo-50/60 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-200 dark:border-gray-500 dark:bg-gray-800/60 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20">
                                    <div id="proof-upload-content" class="flex flex-col items-center justify-center gap-2 px-4 py-5">
                                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M12 15.5V4.25m0 0L8 8.25m4-4 4 4" />
                                                <path d="M4.5 14.5v3A2.5 2.5 0 0 0 7 20h10a2.5 2.5 0 0 0 2.5-2.5v-3" />
                                            </svg>
                                        </span>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">
                                            {{ __('Drop the screenshot here, or') }}
                                            <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ __('click to browse') }}</span>
                                        </p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('PNG, JPG or WebP, up to 2 MB') }}</p>
                                    </div>

                                    <div id="proof-image-wrapper" class="hidden">
                                        <div class="relative">
                                            <img id="proof-image-preview" src="" alt=""
                                                 class="mx-auto max-h-44 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
                                            <button type="button" id="proof-remove-image"
                                                    class="absolute right-2 top-2 z-20 flex h-7 w-7 items-center justify-center rounded-full bg-gray-900/60 text-white transition hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-white/70"
                                                    aria-label="{{ __('Remove') }}">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                                    <path d="M6 6l12 12M18 6L6 18" />
                                                </svg>
                                                <span class="sr-only">{{ __('Remove') }}</span>
                                            </button>
                                        </div>
                                        <p id="proof-file-info" data-default="{{ __('Click the image to replace it') }}"
                                           class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Click the image to replace it') }}</p>
                                    </div>

                                    <!-- Hidden file input covering the zone so any click opens the picker -->
                                    <input type="file" name="proof_of_payment" id="proof-image" accept="image/*"
                                           class="absolute inset-0 h-full w-full cursor-pointer opacity-0 z-10">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Step 3: one last look before it is sent --}}
                <div data-step-panel="confirm" class="hidden">
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Confirm your order') }}</h4>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Check the dishes and how you are paying, then place the order.') }}</p>

                    <div id="confirm-summary" class="mt-3 space-y-3"></div>
                </div>

                {{-- Placed: the receipt --}}
                <div data-step-panel="placed" class="hidden">
                    <div id="placed-summary"></div>
                </div>
            </div>

            {{-- Footer: the running total, and where to go next --}}
            <div class="flex items-center justify-between gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Total') }}</p>
                    <p id="checkout-total" class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white">$0.00</p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" id="checkout-back"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        {{ __('Back') }}
                    </button>
                    <button type="button" id="checkout-next"
                            data-loading-label="{{ __('Placing...') }}"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-indigo-800">
                        {{ __('Proceed to Payment') }}
                    </button>
                </div>
            </div>
        </div>
    </x-ui.modal>

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/menu.js; see vite.config.js. --}}
        @vite('resources/js/pages/menu.js')
    @endpush
</x-app-layout>
