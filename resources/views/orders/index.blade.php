<x-app-layout>
    <x-slot name="header">
        {{-- No create button: an order is placed by a customer from the menu. --}}
        <x-page-header title="{{ __('Orders') }}" />
    </x-slot>

    @php
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
        $dateInput = 'block rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600 dark:[color-scheme:dark]';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort, filters and the view tabs (resources/js/ui/table-toolbar.js). The filters
                 narrow the feed in the browser (no request); resources/js/pages/orders.js reads them. --}}
            <x-ui.table-toolbar :views="['table', 'card']" :sorts="[
                [__('Newest first'), 8, 'desc'],
                [__('Oldest first'), 8, 'asc'],
                [__('Total: high to low'), 5, 'desc'],
                [__('Total: low to high'), 5, 'asc'],
                [__('Customer: A to Z'), 2, 'asc'],
                [__('Order #'), 1, 'desc'],
            ]">
                <x-ui.filter-field :label="__('Order status')">
                    <select id="orders-status-filter" aria-label="{{ __('Order status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="completed">{{ __('Completed') }}</option>
                        <option value="cancelled">{{ __('Cancelled') }}</option>
                    </select>
                </x-ui.filter-field>

                <x-ui.filter-field :label="__('Payment status')">
                    <select id="orders-payment-filter" aria-label="{{ __('Payment status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All payment statuses') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="verified">{{ __('Verified') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                    </select>
                </x-ui.filter-field>

                {{-- The next two lists are filled by orders.js from the orders the page holds,
                     so they only offer what there is to find. --}}
                <x-ui.filter-field :label="__('Payment Method')">
                    <select id="orders-method-filter" aria-label="{{ __('Payment Method') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All payment methods') }}</option>
                    </select>
                </x-ui.filter-field>

                <x-ui.filter-field :label="__('Order Session')">
                    <select id="orders-session-filter" aria-label="{{ __('Order Session') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All order sessions') }}</option>
                    </select>
                </x-ui.filter-field>

                {{-- The day the order was placed. From can never be later than To (orders.js). --}}
                <x-ui.filter-field :label="__('Date')">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" id="orders-date-from" aria-label="{{ __('From') }}" class="{{ $dateInput }} w-full">
                        <input type="date" id="orders-date-to" aria-label="{{ __('To') }}" class="{{ $dateInput }} w-full">
                    </div>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` and the copy are read by resources/js/pages/orders.js. --}}
            <table id="orders-table"
                   data-endpoint="{{ route('orders.index') }}"
                   data-proof-label="{{ __('View proof') }}"
                   data-proof-caption="{{ __('Proof of payment') }}"
                   data-items-label="{{ __('Items') }}"
                   data-customer-label="{{ __('Customer') }}"
                   data-session-label="{{ __('Order Session') }}"
                   data-payment-label="{{ __('Payment') }}"
                   data-view-label="{{ __('View') }}"
                   data-date-range-error="{{ __('Date from cannot be later than date to.') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Order #') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Customer') }}</th>
                        <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Order Session') }}</th>
                        <th scope="col" class="{{ $th }} hidden md:table-cell">{{ __('Items') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Total') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Payment') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Status') }}</th>
                        <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Placed') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by resources/js/pages/orders.js --}}
            <div id="orders-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No orders yet.') }}"></div>
        </div>
    </div>

    {{-- Order details and the actions on an order (shared with the order session page). --}}
    <x-ui.order-popup />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/orders.js; see vite.config.js. --}}
        @vite('resources/js/pages/orders.js')
    @endpush
</x-app-layout>
