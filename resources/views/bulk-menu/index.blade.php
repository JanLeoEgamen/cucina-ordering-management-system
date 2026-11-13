@php
    // The window the running bulk session is open, for the header line.
    $window = $session ? substr($session->start_time, 0, 5) . ' – ' . substr($session->end_time, 0, 5) : '';
    $date = $session ? \Illuminate\Support\Carbon::parse($session->order_date)->format('M j, Y') : '';

    // The +/- buttons beside a card's quantity box.
    $stepButtonClass = 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-gray-700';
    $plusButtonClass = 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-900 bg-gray-900 text-white shadow-sm transition hover:bg-gray-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:border-mist dark:bg-mist dark:text-ink dark:hover:bg-white dark:focus:ring-gray-600';
    $qtyInput = 'h-10 w-20 rounded-xl border border-gray-200 bg-gray-50 px-2 text-center text-base font-semibold tabular-nums text-gray-900 focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';

    $secondaryLink = 'inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';

    // The ways to pay, as the checkout offers them. Cash on pickup is paid when the
    // order is collected; a bank or e-wallet pays is sent first and proved with a
    // reference number and a screenshot.
    $payTile = 'flex w-full items-center gap-3 rounded-2xl border px-4 py-3 text-left transition focus:outline-none focus:ring-4 focus:ring-indigo-200 dark:focus:ring-indigo-900';
    $payTileIdle = 'border-gray-200 bg-white hover:border-indigo-300 hover:bg-indigo-50/40 dark:border-gray-600 dark:bg-gray-700/40 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20';

    // What the page script needs about each dish: nothing else travels.
    $dishData = $dishes->map(fn ($dish) => [
        'id' => (int) $dish->bulk_session_dish_id,
        'name' => $dish->dish_name,
        'price' => (float) $dish->price,
    ])->values();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('Bulk Orders') }}</h2>

                @if ($session)
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                        @if ($session->title)
                            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $session->title }}</span> ·
                        @endif
                        {{ $date }} · <span class="tabular-nums">{{ $window }}</span>
                        · {{ __('Open until') }} <span class="tabular-nums">{{ substr($session->end_time, 0, 5) }}</span>
                    </p>
                @else
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ __('No bulk session is running right now.') }}</p>
                @endif
            </div>

            @can('view my bulk-orders')
                <a href="{{ route('bulk-my-orders.index') }}" class="{{ $secondaryLink }}">{{ __('My Bulk Orders') }}</a>
            @endcan
        </div>
    </x-slot>

    {{--
        The page wiring: resources/js/pages/bulk-menu.js reads these `data-*`
        attributes, so no URL, copy or choice is hard coded in the script.
        `data-methods` is the bank / e-wallet list and `data-cash` the pay-on-pickup
        method. There is no stock to poll: the server judges the order when it is sent.
    --}}
    <div id="bulk-menu-root"
         class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8"
         data-session-id="{{ $session->bulk_session_id ?? '' }}"
         data-min-quantity="{{ (int) ($session->min_quantity ?? 0) }}"
         data-order-url="{{ route('bulk-orders.store') }}"
         data-methods='@json($methods)'
         data-cash='@json($cash)'
         data-dishes='@json($dishData)'
         data-plates-one="{{ __('plate') }}"
         data-plates-many="{{ __('plates') }}"
         data-need-more="{{ __('Add :n more plates to reach the minimum.') }}"
         data-min-reached="{{ __('Minimum reached.') }}"
         data-below-minimum="{{ __('A bulk order needs at least :min plates (you have :n).') }}"
         data-choose-method="{{ __('Choose a payment method.') }}"
         data-proof-required="{{ __('Upload the proof of payment to continue.') }}"
         data-no-options="{{ __('No payment option has been set up yet.') }}"
         data-send-failed="{{ __('The order could not be sent. Please try again.') }}"
         data-method-label="{{ __('Payment Method') }}"
         data-account-name-label="{{ __('Account Name') }}"
         data-account-number-label="{{ __('Account Number') }}"
         data-reference-label="{{ __('Reference Number') }}"
         data-scan-label="{{ __('Scan the QR code, or send the payment to the account below.') }}"
         data-save-qr-label="{{ __('Save QR') }}"
         data-zoom-hint="{{ __('Click to enlarge') }}"
         data-order-label="{{ __('Order Number') }}"
         data-verifying-label="{{ __('Your payment is waiting to be verified. Keep your reference number.') }}"
         data-my-orders-url="{{ auth()->user()->can('view my bulk-orders') ? route('bulk-my-orders.index') : '' }}"
         data-my-orders-label="{{ __('See my bulk orders') }}"
         data-done-label="{{ __('Done') }}">

        @if (! $session)
            {{-- Nothing is running: say so instead of showing an empty menu. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <svg class="mx-auto h-10 w-10 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <h3 class="mt-3 text-base font-semibold text-gray-900 dark:text-white">{{ __('No bulk session is running') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Bulk orders open when a bulk session starts.') }}</p>
            </div>
        @elseif ($dishes->isEmpty())
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('Nothing on the bulk menu yet') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('This bulk session does not offer any dish.') }}</p>
            </div>
        @else
            {{-- The rule of the session, up front. --}}
            <div class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-card dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                </svg>
                <span>{{ __('Bulk orders need at least') }} <strong class="tabular-nums">{{ (int) $session->min_quantity }}</strong> {{ __('plates in total, in any mix of dishes.') }}</span>
            </div>

            <label class="relative mb-5 block">
                <span class="sr-only">{{ __('Search a dish') }}</span>
                <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>
                <input type="search" id="bulk-search" autocomplete="off" placeholder="{{ __('Search a dish') }}"
                       class="h-12 w-full rounded-full border border-gray-200 bg-white pl-12 pr-4 text-base text-gray-900 shadow-card placeholder:text-gray-400 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:placeholder:text-gray-400 dark:focus:border-gray-300 dark:focus:ring-gray-600">
            </label>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5 xl:grid-cols-3">
                @foreach ($dishes as $dish)
                    <article class="flex gap-4 rounded-2xl bg-white p-3 shadow-card transition dark:bg-gray-800 dark:ring-1 dark:ring-white/5 data-[in-cart=true]:ring-2 data-[in-cart=true]:ring-gray-900 dark:data-[in-cart=true]:ring-mist"
                             data-in-cart="false"
                             data-dish-card="{{ $dish->bulk_session_dish_id }}"
                             data-name="{{ \Illuminate\Support\Str::lower($dish->dish_name) }}">
                        <div class="relative h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-gradient-to-br from-gray-200 to-gray-300 dark:from-gray-700 dark:to-gray-800">
                            <svg class="absolute left-1/2 top-1/2 h-8 w-8 -translate-x-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                            </svg>

                            @if ($dish->image)
                                <img src="{{ $dish->image }}" alt="" loading="lazy" class="absolute inset-0 h-full w-full object-cover" onerror="this.remove()">
                            @endif
                        </div>

                        <div class="flex min-w-0 flex-1 flex-col">
                            <h3 class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ $dish->dish_name }}</h3>
                            <p class="text-sm tabular-nums text-gray-600 dark:text-gray-300">
                                <span data-price-label="{{ $dish->bulk_session_dish_id }}">₱{{ number_format((float) $dish->price, 2) }}</span>
                                <span class="text-gray-400">{{ __('each') }}</span>
                            </p>

                            <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-2">
                                <button type="button" data-step="-1" aria-label="{{ __('One less') }}" disabled class="{{ $stepButtonClass }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14" /></svg>
                                </button>

                                <input type="number" data-qty min="0" max="10000" step="1" value="0" inputmode="numeric"
                                       aria-label="{{ __('Plates of :dish', ['dish' => $dish->dish_name]) }}" class="{{ $qtyInput }}">

                                <button type="button" data-step="1" aria-label="{{ __('One more') }}" class="{{ $plusButtonClass }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                                </button>

                                <button type="button" data-step="10"
                                        class="h-10 rounded-full border border-gray-200 px-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-gray-700">+10</button>
                            </div>

                            {{-- The dish's share of the total, once it is in the order. --}}
                            <p data-line-total class="mt-1 hidden text-xs font-medium tabular-nums text-gray-500 dark:text-gray-400"></p>
                        </div>
                    </article>
                @endforeach
            </div>

            <div id="bulk-empty" class="mt-4 hidden rounded-2xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('No dish matches') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Try another name.') }}</p>
            </div>

            {{-- The running total and the minimum, always in reach at the bottom of the page. --}}
            <div id="bulk-summary" class="sticky bottom-4 z-30 mt-6 rounded-2xl border border-gray-200 bg-white/95 p-4 shadow-card-lift backdrop-blur dark:border-gray-700 dark:bg-gray-800/95">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                            <span class="text-lg font-bold tabular-nums text-gray-900 dark:text-white"><span id="bulk-plates">0</span> <span id="bulk-plates-label" class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('plates') }}</span></span>
                            <span id="bulk-total" class="text-lg font-bold tabular-nums text-gray-900 dark:text-white">₱0.00</span>
                        </p>

                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="{{ (int) $session->min_quantity }}" aria-valuenow="0" id="bulk-progress-bar">
                            <div id="bulk-progress" class="h-full w-0 rounded-full bg-gray-900 transition-all duration-300 ease-out dark:bg-mist"></div>
                        </div>
                        <p id="bulk-hint" class="mt-1 text-xs text-gray-500 dark:text-gray-400" aria-live="polite"></p>
                    </div>

                    <button type="button" id="bulk-review" disabled
                            class="w-full rounded-full bg-indigo-600 px-6 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto dark:focus:ring-indigo-800">
                        {{ __('Review & Pay') }}
                    </button>
                </div>
            </div>
        @endif
    </div>

    {{--
        Checkout: one modal, one page. The order, how to pay, and Place Order; the
        receipt replaces them once the order is placed.
        resources/js/pages/bulk-menu.js drives it.
    --}}
    <x-ui.modal name="bulk-checkout-modal" size="2xl">
        <div class="flex max-h-[85vh] flex-col">
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-6 py-3 dark:border-gray-700">
                <h3 class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{{ __('Checkout') }}</h3>
                <button type="button"
                        class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white dark:focus:ring-gray-700"
                        x-on:click="show = false">
                    <span class="sr-only">{{ __('Close') }}</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-6">
                {{-- Sticky: it stays in view however far the body is scrolled. --}}
                <div id="bulk-errors" role="alert" aria-live="assertive" class="sticky top-0 z-10 mb-4 hidden rounded-xl border border-red-200 bg-red-50 py-2.5 pl-3 pr-3 text-sm font-medium text-red-700 shadow-card dark:border-red-900 dark:bg-red-950 dark:text-red-300"></div>

                {{-- Before the order is placed --}}
                <div id="bulk-checkout-form" class="space-y-6">
                    <section>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Your Order') }}</h4>
                        <div class="mt-2 rounded-2xl bg-gray-50 dark:bg-gray-700/40">
                            <ul id="bulk-lines" class="divide-y divide-gray-100 dark:divide-gray-700/70"></ul>
                        </div>
                    </section>

                    <section>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Payment') }}</h4>

                        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @if ($cash)
                                <button type="button" data-cash-tile data-method-id="{{ $cash->method_id }}" aria-pressed="false" class="{{ $payTile }} {{ $payTileIdle }}">
                                    <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                                        </svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ $cash->method_name }}</span>
                                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __('Pay at the counter when you pick your order up.') }}</span>
                                    </span>
                                </button>
                            @endif

                            {{-- The bank / e-wallet rows, filled in by bulk-menu.js. --}}
                            <div id="bulk-bank-methods" class="contents"></div>
                        </div>

                        @if (! $cash && $methods->isEmpty())
                            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">{{ __('No payment option has been set up yet.') }}</p>
                        @endif

                        {{-- A bank / e-wallet method is picked: where to send the money, then the reference and the proof. --}}
                        <div id="bulk-bank-panel" class="mt-4 hidden space-y-4 rounded-xl border border-indigo-200 bg-indigo-50/30 p-4 dark:border-indigo-900 dark:bg-indigo-900/10">
                            <div id="bulk-method-details" class="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-gray-700/30"></div>

                            <div>
                                <label for="bulk-reference" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('Reference Number') }}
                                    <span class="font-normal text-gray-500 dark:text-gray-400">({{ __('optional') }})</span>
                                </label>
                                <input type="text" id="bulk-reference" maxlength="255"
                                       placeholder="{{ __('e.g. the last digits of the transfer') }}"
                                       class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400">
                            </div>

                            <div>
                                <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('Proof of Payment') }}
                                    <span class="font-semibold text-red-600 dark:text-red-400" aria-hidden="true">*</span>
                                    <span class="sr-only">({{ __('required') }})</span>
                                </span>

                                {{-- Drag & drop upload zone for the receipt screenshot --}}
                                <div id="bulk-proof-zone"
                                     class="relative mt-1 cursor-pointer rounded-xl border-2 border-dashed border-gray-400 bg-white p-3 text-center transition-colors duration-200 hover:border-indigo-400 hover:bg-indigo-50/60 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-200 dark:border-gray-500 dark:bg-gray-800/60 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20">
                                    <div id="bulk-proof-empty" class="flex flex-col items-center justify-center gap-2 px-4 py-5">
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

                                    <div id="bulk-proof-filled" class="hidden">
                                        <div class="relative">
                                            <img id="bulk-proof-preview" src="" alt=""
                                                 class="mx-auto max-h-44 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
                                            <button type="button" id="bulk-proof-remove"
                                                    class="absolute right-2 top-2 z-20 flex h-7 w-7 items-center justify-center rounded-full bg-gray-900/60 text-white transition hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-white/70"
                                                    aria-label="{{ __('Remove') }}">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                                    <path d="M6 6l12 12M18 6L6 18" />
                                                </svg>
                                            </button>
                                        </div>
                                        <p id="bulk-proof-info" class="mt-2 text-xs text-gray-500 dark:text-gray-400"></p>
                                    </div>

                                    {{-- Hidden file input covering the zone so any click opens the picker --}}
                                    <input type="file" id="bulk-proof-input" accept="image/*"
                                           class="absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0">
                                </div>
                            </div>
                        </div>

                        {{-- Cash on pickup: nothing else to fill in. --}}
                        <div id="bulk-cash-panel" class="mt-4 hidden rounded-xl border border-emerald-200 bg-emerald-50/60 p-4 dark:border-emerald-800 dark:bg-emerald-900/20">
                            <p class="text-sm text-emerald-900 dark:text-emerald-200">{{ __('Nothing to send now. The counter takes the payment when you collect your order.') }}</p>
                        </div>
                    </section>
                </div>

                {{-- Placed: the receipt --}}
                <div id="bulk-receipt" class="hidden"></div>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Total') }}</p>
                    <p id="bulk-checkout-total" class="text-2xl font-bold tabular-nums text-gray-900 dark:text-white">₱0.00</p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="button" id="bulk-back"
                            class="rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                        {{ __('Back to Menu') }}
                    </button>
                    <button type="button" id="bulk-place"
                            data-loading-label="{{ __('Placing...') }}"
                            class="rounded-full bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-50 dark:focus:ring-indigo-800">
                        {{ __('Place Order') }}
                    </button>
                </div>
            </div>
        </div>
    </x-ui.modal>

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/bulk-menu.js; see vite.config.js. --}}
        @vite('resources/js/pages/bulk-menu.js')
    @endpush
</x-app-layout>
