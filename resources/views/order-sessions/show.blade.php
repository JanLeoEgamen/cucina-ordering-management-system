@php
    $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
    $label = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
    $pill = 'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-gray-400 dark:hover:text-gray-200 dark:focus:ring-indigo-800 aria-pressed:bg-white aria-pressed:text-indigo-600 aria-pressed:shadow-sm dark:aria-pressed:bg-gray-900 dark:aria-pressed:text-indigo-400';

    $date = \Illuminate\Support\Carbon::parse($session->order_date)->format('M j, Y');
    $window = substr($session->start_time, 0, 5) . ' – ' . substr($session->end_time, 0, 5);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('order-sessions.index') }}"
                   class="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 6-6 6 6 6" /></svg>
                    {{ __('Order Sessions') }}
                </a>

                <h2 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold text-gray-900 dark:text-white">
                    {{ __('Order Session') }} · {{ $date }}
                    {!! $statusBadge !!}
                </h2>
            </div>

            {{-- Ending is the creator's call, so the button is only rendered for
                 them, and only while the session is still open. --}}
            @if ($canEnd)
                <button type="button" id="end-session"
                        data-end-url="{{ route('order-sessions.end', $session->session_id) }}"
                        data-subject="{{ $date }}"
                        class="inline-flex w-full items-center justify-center rounded-lg bg-amber-500 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-300 sm:w-auto dark:focus:ring-amber-800">
                    {{ __('End Session') }}
                </button>
            @endif
        </div>
    </x-slot>

    <div class="container mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        {{-- Facts --}}
        <section class="grid grid-cols-2 gap-4 rounded-xl border border-gray-200 bg-white p-4 sm:grid-cols-3 lg:grid-cols-6 dark:border-gray-700 dark:bg-gray-800">
            <div>
                <p class="{{ $label }}">{{ __('Session Date') }}</p>
                <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $date }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Time') }}</p>
                <p class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $window }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Created By') }}</p>
                <p class="mt-1 truncate text-sm font-medium text-gray-900 dark:text-white">{{ $session->created_by_name ?? '—' }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Orders') }}</p>
                <p class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $ordersCount }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Items Sold') }}</p>
                <p class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white">{{ $itemsSold }}</p>
            </div>
            <div>
                <p class="{{ $label }}">{{ __('Sales') }}</p>
                <p class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white">₱{{ number_format($salesTotal, 2) }}</p>
            </div>
        </section>

        {{-- The stock of the session: collapsed until opened (Alpine, no script file). --}}
        <section x-data="{ open: false }" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            <h3>
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="session-dishes-panel"
                        class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 dark:hover:bg-gray-700/40">
                    <span class="flex flex-wrap items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Dishes and Stock') }}
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium tabular-nums text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ count($session->dishes) }}</span>
                    </span>
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                </button>
            </h3>

            <div id="session-dishes-panel" x-show="open" x-cloak class="overflow-x-auto border-t border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr>
                            <th scope="col" class="{{ $th }}">{{ __('Dish') }}</th>
                            <th scope="col" class="{{ $th }} text-right">{{ __('Price') }}</th>
                            <th scope="col" class="{{ $th }} text-right">{{ __('Stock Left') }}</th>
                            <th scope="col" class="{{ $th }} text-right">{{ __('Sold') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800">
                        @forelse ($session->dishes as $dish)
                            <tr>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{{ $dish['dish_name'] }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">₱{{ number_format($dish['price'], 2) }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ $dish['available_stock'] }}</td>
                                <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ $dish['reserved_stock'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('This session offers no dishes.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- The orders placed in this session: a table, or cards, like the other lists --}}
        <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort, filters and the view tabs (resources/js/ui/table-toolbar.js). --}}
            <x-ui.table-toolbar :export-title="__('Orders') . ' ' . $date" :title="__('Orders')" :views="['table', 'card']" :sorts="[
                [__('Newest first'), 7, 'desc'],
                [__('Oldest first'), 7, 'asc'],
                [__('Total: high to low'), 4, 'desc'],
                [__('Total: low to high'), 4, 'asc'],
                [__('Customer: A to Z'), 2, 'asc'],
                [__('Order #'), 1, 'desc'],
            ]">
                <x-ui.filter-field :label="__('Order status')">
                    <select id="order-status-filter" aria-label="{{ __('Order status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All order statuses') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </select>
                </x-ui.filter-field>
            
                <x-ui.filter-field :label="__('Payment status')">
                    <select id="payment-status-filter" aria-label="{{ __('Payment status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All payment statuses') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="verified">{{ __('Verified') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                    </select>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this page's own AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/order-session-show.js. --}}
            <table id="session-orders-table"
                   data-endpoint="{{ route('order-sessions.show', $session->session_id) }}"
                   data-proof-label="{{ __('View proof') }}"
                   data-proof-caption="{{ __('Proof of payment') }}"
                   data-no-method="{{ __('Cash on Pick up') }}"
                   data-items-label="{{ __('Items') }}"
                   data-customer-label="{{ __('Customer') }}"
                   data-payment-label="{{ __('Payment') }}"
                   data-placed-label="{{ __('Placed') }}"
                   data-view-label="{{ __('View') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" data-no-export class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Order #') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Customer') }}</th>
                        <th scope="col" class="{{ $th }} hidden md:table-cell">{{ __('Items') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Total') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Payment') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Status') }}</th>
                        <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Placed') }}</th>
                        <th scope="col" data-no-export class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by order-session-show.js --}}
            <div id="session-orders-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No orders in this session yet.') }}"></div>
        </section>
    </div>

    {{-- Order details and the actions on an order (shared with the Orders page). --}}
    <x-ui.order-popup />

    <x-edit-confirmation-modal
        title="{{ __('Confirm End') }}"
        message="{{ __('Are you sure you want to end this order session? Orders already placed are kept, and its dishes come off the menu.') }}"
        confirm-label="{{ __('End Session') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/order-session-show.js; see vite.config.js. --}}
        @vite('resources/js/pages/order-session-show.js')
    @endpush
</x-app-layout>
