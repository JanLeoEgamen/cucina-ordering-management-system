@php
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $cancelButton = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
@endphp

{{--
    The payment popup: one payment's details, with Verify, Reject (asks for a
    reason) and Undo inside. Include it ONCE on a page, then open it from the
    page script with initPaymentPopup() (resources/js/ui/payment-popup.js).

    It brings its own confirmation (`payment-decision-modal`) so it never
    collides with another page's confirmation modal. The three modals are
    rendered in this order, so the reason and the confirmation open on top of
    the popup.
--}}

{{-- View popup: read only, and where a pending payment is verified or rejected.
     resources/js/pages/payments.js fills it from the row the table already
     holds, so opening it costs no request. --}}
<x-ui.modal name="view-payment-modal" size="2xl">
    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
            {{ __('Payment') }}
            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('View only') }}</span>
        </h3>
        <button type="button"
                class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                x-on:click="show = false">
            <span class="sr-only">{{ __('Close') }}</span>
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="max-h-[70vh] overflow-y-auto p-6">
        <div id="view-payment-proof-wrapper" class="mb-4 hidden">
            <x-ui.zoomable-image id="view-payment-proof" src="" alt=""
                 class="mx-auto max-h-64 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700" />
        </div>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-3.5 sm:grid-cols-2">
            <div>
                <dt class="{{ $label }}">{{ __('Order #') }}</dt>
                <dd id="view-payment-order" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Status') }}</dt>
                <dd id="view-payment-status" class="mt-1 text-sm"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Customer') }}</dt>
                <dd id="view-payment-customer" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Amount') }}</dt>
                <dd id="view-payment-amount" class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Payment Method') }}</dt>
                <dd id="view-payment-method" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Reference Number') }}</dt>
                <dd id="view-payment-reference" class="mt-1 text-sm tabular-nums text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Paid On') }}</dt>
                <dd id="view-payment-date" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Order Status') }}</dt>
                <dd id="view-payment-order-status" class="mt-1 text-sm"></dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="{{ $label }}">{{ __('Reviewed By') }}</dt>
                <dd id="view-payment-verified" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div id="view-payment-reason-wrapper" class="hidden sm:col-span-2">
                <dt class="{{ $label }}">{{ __('Rejection Reason') }}</dt>
                <dd id="view-payment-reason" class="mt-1 whitespace-pre-line rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"></dd>
            </div>
        </dl>

        <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Items') }}</h4>
        <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/40">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Dish') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Qty') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Subtotal') }}</th>
                    </tr>
                </thead>
                <tbody id="view-payment-items" class="divide-y divide-gray-100 bg-white dark:divide-gray-700/70 dark:bg-gray-800"></tbody>
            </table>
        </div>
    </div>

    <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
        {{-- Only a pending payment is waiting for a decision; menu.js-style, the
             page script shows each button when the row carries its URL (the
             server sets it only for whoever may take that decision). --}}
        {{-- A decided payment can be put back to pending. --}}
        @can('undo payments')
            <button type="button" id="view-payment-undo" data-decision="undo"
                    data-title="{{ __('Confirm Undo') }}"
                    data-message="{{ __('Are you sure you want to undo this decision? The payment goes back to pending, and a rejection reason is cleared.') }}"
                    data-label="{{ __('Undo Decision') }}"
                    class="hidden rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-amber-700 transition hover:bg-amber-50 focus:outline-none focus:ring-4 focus:ring-amber-200 dark:border-amber-800 dark:bg-gray-800 dark:text-amber-400 dark:hover:bg-amber-900/20 dark:focus:ring-amber-900">
                {{ __('Undo') }}
            </button>
        @endcan
        @can('reject payments')
            {{-- Reject opens the reason popup below instead of the shared confirmation. --}}
            <button type="button" id="view-payment-reject" data-decision="reject"
                    class="hidden rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-200 dark:border-red-800 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-900/20 dark:focus:ring-red-900">
                {{ __('Reject') }}
            </button>
        @endcan
        @can('verify payments')
            <button type="button" id="view-payment-verify" data-decision="verify"
                    data-title="{{ __('Confirm Verify') }}"
                    data-message="{{ __('Are you sure you want to verify this payment? Check the amount and the proof first.') }}"
                    data-label="{{ __('Verify Payment') }}"
                    class="hidden rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-300 dark:focus:ring-emerald-800">
                {{ __('Verify') }}
            </button>
        @endcan
        <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
    </div>
</x-ui.modal>

{{-- Reject asks for the reason, which the customer sees on their orders. --}}
@can('reject payments')
    <x-ui.modal name="reject-payment-modal" size="md">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Reject Payment') }}</h3>
            <button type="button"
                    class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="p-6">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Tell the customer why this payment is rejected, so they know what to fix.') }}</p>
            <p id="reject-subject" class="mt-1 text-sm font-medium text-gray-900 dark:text-white"></p>

            <div id="reject-errors" class="mt-3 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <label for="reject-reason" class="mt-4 block text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('Reason') }} <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
            </label>
            <textarea id="reject-reason" rows="3" maxlength="500" required
                      placeholder="{{ __('e.g. The amount does not match the order, or the screenshot is unreadable.') }}"
                      class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"></textarea>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
            <button type="button" id="reject-confirm" data-loading-label="{{ __('Rejecting...') }}"
                    class="rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 dark:focus:ring-red-900">
                {{ __('Reject Payment') }}
            </button>
        </div>
    </x-ui.modal>
@endcan

{{-- One confirmation serves Verify and Undo: the script sets its copy and the URL. --}}
@canany(['verify payments', 'undo payments'])
    <x-ui.modal name="payment-decision-modal" size="md">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 id="pay-confirm-title" class="text-lg font-semibold text-gray-900 dark:text-white"></h3>
            <button type="button"
                    class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="p-6">
            <p id="pay-confirm-message" class="text-gray-600 dark:text-gray-300"></p>
            <p id="pay-confirm-subject" class="mt-2 hidden text-sm font-medium text-gray-900 dark:text-white"></p>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
            <button type="button" id="pay-confirm-button" data-loading-label="{{ __('Saving...') }}"
                    class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800"></button>
        </div>
    </x-ui.modal>
@endcanany
