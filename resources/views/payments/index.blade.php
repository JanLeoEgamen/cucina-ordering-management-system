<x-app-layout>
    <x-slot name="header">
        {{-- No create button: a payment is made by the customer when the order is placed. --}}
        <x-page-header title="{{ __('Payments') }}" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $cancelButton = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
        $dateInput = 'block rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600 dark:[color-scheme:dark]';
        $pill = 'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-gray-400 dark:hover:text-gray-200 dark:focus:ring-indigo-800 aria-pressed:bg-white aria-pressed:text-indigo-600 aria-pressed:shadow-sm dark:aria-pressed:bg-gray-900 dark:aria-pressed:text-indigo-400';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort, filters and the view tabs (resources/js/ui/table-toolbar.js). The filters
                 narrow the feed in the browser (no request); resources/js/pages/payments.js reads them. --}}
            <x-ui.table-toolbar :views="['table', 'card']" :sorts="[
                [__('Newest first'), 8, 'desc'],
                [__('Oldest first'), 8, 'asc'],
                [__('Amount: high to low'), 5, 'desc'],
                [__('Amount: low to high'), 5, 'asc'],
                [__('Customer: A to Z'), 2, 'asc'],
                [__('Order #'), 1, 'desc'],
            ]">
                <x-ui.filter-field :label="__('Status')">
                    <select id="payment-status-filter" aria-label="{{ __('Status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="verified">{{ __('Verified') }}</option>
                        <option value="rejected">{{ __('Rejected') }}</option>
                    </select>
                </x-ui.filter-field>
            
                {{-- The next two lists are filled by payments.js from the payments the page holds,
                     so they only offer what there is to find. --}}
                <x-ui.filter-field :label="__('Payment Method')">
                    <select id="payment-method-filter" aria-label="{{ __('Payment Method') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All payment methods') }}</option>
                    </select>
                </x-ui.filter-field>
            
                <x-ui.filter-field :label="__('Order Session')">
                    <select id="payment-session-filter" aria-label="{{ __('Order Session') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All order sessions') }}</option>
                    </select>
                </x-ui.filter-field>
            
                {{-- The day the payment was made. From can never be later than To (payments.js). --}}
                <x-ui.filter-field :label="__('Date')">
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" id="payment-date-from" aria-label="{{ __('From') }}" class="{{ $dateInput }} w-full">
                        <input type="date" id="payment-date-to" aria-label="{{ __('To') }}" class="{{ $dateInput }} w-full">
                    </div>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` and the copy are read by resources/js/pages/payments.js. --}}
            <table id="payments-table"
                   data-endpoint="{{ route('payments.index') }}"
                   data-proof-caption="{{ __('Proof of payment') }}"
                   data-customer-label="{{ __('Customer') }}"
                   data-method-label="{{ __('Payment Method') }}"
                   data-reference-label="{{ __('Reference Number') }}"
                   data-amount-label="{{ __('Amount') }}"
                   data-date-range-error="{{ __('Date from cannot be later than date to.') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Order #') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Customer') }}</th>
                        <th scope="col" class="{{ $th }} hidden md:table-cell">{{ __('Payment Method') }}</th>
                        <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Reference Number') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Amount') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Proof') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Status') }}</th>
                        <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Date') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by resources/js/pages/payments.js --}}
            <div id="payments-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No data available yet') }}"></div>
        </div>
    </div>

    {{-- View popup (with Verify / Reject / Undo): shared with the order session page. --}}
    <x-ui.payment-popup />

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this payment? A verified payment cannot be deleted.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/payments.js; see vite.config.js. --}}
        @vite('resources/js/pages/payments.js')
    @endpush
</x-app-layout>
