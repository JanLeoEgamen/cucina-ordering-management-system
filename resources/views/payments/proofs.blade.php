<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Proof of Payment Gallery') }}">
            <span data-soft-nav>
                <a href="{{ route('payments.index') }}"
                   class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m0 0 6 6m-6-6 6-6" /></svg>
                    {{ __('Back to Payments') }}
                </a>
            </span>
        </x-page-header>
    </x-slot>

    @php
        $label = 'mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
        $dateInput = 'block w-full rounded-xl border border-gray-300 bg-white px-3.5 py-2.5 text-sm leading-5 text-gray-900 shadow-sm focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600 dark:[color-scheme:dark]';
        $button = 'rounded-full border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        {{-- The pictures and the filter choices come from this route's AJAX response; the
             endpoint and the copy are read by resources/js/pages/payment-proofs.js. --}}
        <div id="proof-gallery"
             data-endpoint="{{ route('payments.proofs') }}"
             data-proof-label="{{ __('Proof of payment') }}"
             data-reference-label="{{ __('Reference Number') }}"
             data-broken-label="{{ __('Picture not available') }}"
             data-summary="{{ __('Showing :from to :to of :total proofs') }}"
             data-empty="{{ __('No proof of payment has been uploaded yet.') }}"
             data-empty-filtered="{{ __('No proof of payment matches these filters.') }}"
             data-date-range-error="{{ __('Date from cannot be later than date to.') }}"
             class="rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">

            <div class="grid grid-cols-1 gap-3 border-b border-gray-100 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,16rem)_minmax(0,12rem)_minmax(0,12rem)_auto] lg:items-end dark:border-gray-700">
                <div class="sm:col-span-2 lg:col-span-1">
                    <label for="proof-method-filter" class="{{ $label }}">{{ __('Payment Method') }}</label>
                    <select id="proof-method-filter" aria-label="{{ __('Payment Method') }}" class="{{ $dateInput }}">
                        <option value="">{{ __('All payment methods') }}</option>
                    </select>
                </div>
                {{-- The day the payment was made, as on the Payments page. --}}
                <div>
                    <label for="proof-date-from" class="{{ $label }}">{{ __('From') }}</label>
                    <input type="date" id="proof-date-from" class="{{ $dateInput }}">
                </div>
                <div>
                    <label for="proof-date-to" class="{{ $label }}">{{ __('To') }}</label>
                    <input type="date" id="proof-date-to" class="{{ $dateInput }}">
                </div>
                <div class="sm:col-span-2 lg:col-span-1 lg:justify-self-end">
                    <button type="button" id="proof-clear" class="{{ $button }} w-full lg:w-auto" disabled>{{ __('Clear filters') }}</button>
                </div>
            </div>

            <div id="proof-error" class="m-4 hidden items-center justify-between gap-3 rounded-xl bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300" role="alert">
                <span>{{ __('The proofs could not be loaded.') }}</span>
                <button type="button" id="proof-retry" class="{{ $button }}">{{ __('Try again') }}</button>
            </div>

            <p id="proof-loading" class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('Loading...') }}</p>
            <p id="proof-empty" class="hidden px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400"></p>

            <ul id="proof-grid" aria-live="polite"
                class="hidden grid-cols-2 gap-3 p-4 transition-opacity sm:grid-cols-3 sm:gap-4 lg:grid-cols-4 xl:grid-cols-6 motion-reduce:transition-none"></ul>

            <div id="proof-footer" class="hidden flex-col items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 sm:flex-row dark:border-gray-700">
                <p id="proof-summary" class="text-sm text-gray-500 dark:text-gray-400"></p>
                <div class="flex items-center gap-2">
                    <button type="button" id="proof-previous" class="{{ $button }}">{{ __('Previous') }}</button>
                    <button type="button" id="proof-next" class="{{ $button }}">{{ __('Next') }}</button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/payment-proofs.js; started by resources/js/ui/soft-nav.js. --}}
        <x-page-module name="payment-proofs" />
    @endpush
</x-app-layout>
