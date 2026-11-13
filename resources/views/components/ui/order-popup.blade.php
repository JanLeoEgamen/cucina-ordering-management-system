{{--
    Order popup: one order's details with its actions (Complete / Cancel / Verify inline, Reject with
    its own reason popup). Shared by the order session page and the Orders page; wired by
    resources/js/ui/order-popup.js (`initOrderPopup({ onChange }).open(row)`). The copy the script
    needs sits in #order-popup-copy, so the page does not have to pass it.
--}}
@php
    $label = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400';
@endphp

<div id="order-popup-copy" hidden
     data-proof-label="{{ __('View proof') }}"
     data-proof-caption="{{ __('Proof of payment') }}"
     data-hint-cash="{{ __('Cash on Pick up: the payment is verified when you complete this order.') }}"
     data-hint-bank="{{ __('Verify the payment first, then you can complete this order.') }}"
     data-hint-cancel="{{ __('To cancel this order, first undo the payment verification on the Payments page.') }}"></div>

{{-- Order details: filled by resources/js/ui/order-popup.js from the row the page's table already holds
     (the feed carries the lines and the payment), so opening it costs no request. --}}
<x-ui.modal name="order-details-modal" size="2xl">
    <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <h3 class="flex flex-wrap items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
            <span id="order-details-number"></span>
            <span id="order-details-status"></span>
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
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3.5 sm:grid-cols-2">
            <div>
                <dt class="{{ $label }}">{{ __('Customer') }}</dt>
                <dd id="order-details-customer" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Placed') }}</dt>
                <dd id="order-details-placed" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="{{ $label }}">{{ __('Order Session') }}</dt>
                <dd class="mt-1 text-sm">
                    <a id="order-details-session" href="#" class="font-medium text-indigo-600 underline-offset-2 hover:underline dark:text-indigo-400"></a>
                </dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Payment Method') }}</dt>
                <dd id="order-details-method" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Payment Status') }}</dt>
                <dd id="order-details-payment" class="mt-1 text-sm"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Reference Number') }}</dt>
                <dd id="order-details-reference" class="mt-1 text-sm tabular-nums text-gray-900 dark:text-white"></dd>
            </div>
            <div>
                <dt class="{{ $label }}">{{ __('Proof of Payment') }}</dt>
                <dd id="order-details-proof" class="mt-1 text-sm"></dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="{{ $label }}">{{ __('Verified By') }}</dt>
                <dd id="order-details-verified" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
            </div>
        </dl>

        <h4 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Items') }}</h4>
        <div class="mt-2 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/40">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Dish') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Qty') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Price') }}</th>
                        <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">{{ __('Subtotal') }}</th>
                    </tr>
                </thead>
                <tbody id="order-details-items" class="divide-y divide-gray-100 bg-white dark:divide-gray-700/70 dark:bg-gray-800"></tbody>
            </table>
        </div>

        {{-- What happens to the payment when this order is completed; filled by
             resources/js/ui/order-popup.js while the order is pending. --}}
        <p id="order-details-hint" class="mt-4 hidden rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-900/30 dark:text-blue-200"></p>
    </div>

    <div id="order-details-bar" class="flex items-center justify-between gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Total') }}</p>
            <p id="order-details-total" class="text-lg font-semibold tabular-nums text-gray-900 dark:text-white"></p>
        </div>
        <div class="flex flex-wrap justify-end gap-3">
            {{-- Calling the order off: its dishes go back on the menu. --}}
            @can('cancel orders')
                <button type="button" id="order-details-cancel"
                        data-title="{{ __('Confirm Cancel') }}"
                        data-message="{{ __('Are you sure you want to cancel this order? Its dishes go back on the menu, and a payment that is still pending is rejected.') }}"
                        data-label="{{ __('Cancel Order') }}"
                        class="hidden rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-200 dark:border-red-800 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-900/20 dark:focus:ring-red-900">
                    {{ __('Cancel Order') }}
                </button>
            @endcan

            {{-- Hand-over: cash is verified by completing, the others must be verified first. --}}
            @can('complete orders')
                <button type="button" id="order-details-complete"
                        data-title="{{ __('Confirm Complete') }}"
                        data-message="{{ __('Are you sure you want to complete this order?') }}"
                        data-message-cash="{{ __('Are you sure you want to complete this order? The cash payment is verified as received.') }}"
                        data-label="{{ __('Complete Order') }}"
                        class="hidden rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">
                    {{ __('Complete Order') }}
                </button>
            @endcan

            {{-- The payment decision, inside the order: shown by resources/js/ui/order-popup.js
                 while the order's payment is pending (the row carries each URL only for
                 someone who may take that decision). --}}
            @can('reject payments')
                <button type="button" id="order-details-reject"
                        class="hidden rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-200 dark:border-red-800 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-900/20 dark:focus:ring-red-900">
                    {{ __('Reject Payment') }}
                </button>
            @endcan
            @can('verify payments')
                <button type="button" id="order-details-verify"
                        data-title="{{ __('Confirm Verify') }}"
                        data-message="{{ __('Are you sure you want to verify this payment? Check the amount and the proof first.') }}"
                        data-label="{{ __('Verify Payment') }}"
                        class="hidden rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-300 dark:focus:ring-emerald-800">
                    {{ __('Verify Payment') }}
                </button>
            @endcan
            <button type="button"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                    x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </div>

    {{-- Inline confirmation for Complete / Cancel Order / Verify Payment: the footer above is
         swapped for this bar (resources/js/ui/order-popup.js), so no second modal stacks on the
         details. Its text and the colour of the go button come from the clicked button. --}}
    <div id="order-details-confirm" role="alertdialog" aria-labelledby="order-details-confirm-text"
         class="hidden flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/40">
        <p id="order-details-confirm-text" class="min-w-0 flex-1 basis-64 text-sm font-medium text-gray-900 dark:text-white"></p>
        <div class="flex flex-shrink-0 gap-3">
            <button type="button" id="order-details-confirm-back"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ __('Back') }}</button>
            <button type="button" id="order-details-confirm-go" data-loading-label="{{ __('Working...') }}"
                    class="rounded-lg px-4 py-2 text-sm font-medium text-white transition focus:outline-none focus:ring-4"></button>
        </div>
    </div>
</x-ui.modal>

{{-- Reject asks for the reason, which the customer sees on their orders. --}}
@can('reject payments')
    <x-ui.modal name="reject-order-payment-modal" size="md">
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
            <p id="order-reject-subject" class="mt-1 text-sm font-medium text-gray-900 dark:text-white"></p>

            <div id="order-reject-errors" class="mt-3 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <label for="order-reject-reason" class="mt-4 block text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('Reason') }} <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
            </label>
            <textarea id="order-reject-reason" rows="3" maxlength="500" required
                      data-required="{{ __('Give a reason for rejecting this payment.') }}"
                      placeholder="{{ __('e.g. The amount does not match the order, or the screenshot is unreadable.') }}"
                      class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400"></textarea>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            <button type="button" x-on:click="show = false"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700">{{ __('Cancel') }}</button>
            <button type="button" id="order-reject-confirm" data-loading-label="{{ __('Rejecting...') }}"
                    class="rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 dark:focus:ring-red-900">
                {{ __('Reject Payment') }}
            </button>
        </div>
    </x-ui.modal>
@endcan
