@php
    // Every order the customer placed, as a card: what was ordered, when, and
    // how it is being paid for. Server rendered: nothing here needs a script.
    $itemRow = 'flex items-center justify-between gap-4 py-1.5 text-sm';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('My Orders') }}</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Every order you placed, newest first.') }}
                </p>
            </div>

            <a href="{{ route('menu.index') }}"
               class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                </svg>
                {{ __('Back to Menu') }}
            </a>
        </div>
    </x-slot>

    <div id="my-orders-root" class="container mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8"
         data-failed="{{ __('Your request could not be sent. Please try again.') }}"
         data-pick-proof="{{ __('Choose the image of your new proof of payment.') }}">
        @forelse ($orders as $order)
            {{-- id: a notification about this order opens My Orders at this card. scroll-mt clears the phone's top bar. --}}
            <article id="order-{{ $order->order_id }}" class="mb-4 scroll-mt-20 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm target:ring-2 target:ring-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:target:ring-gray-100">
                {{-- Order number, when it was placed, and where it stands --}}
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order->order_number }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ \Illuminate\Support\Carbon::parse($order->order_date)->format('M j, Y · g:i A') }}
                            @if ($order->session_date)
                                · {{ \Illuminate\Support\Carbon::parse($order->session_date)->format('M j, Y') }}
                                <span class="tabular-nums">({{ substr($order->start_time, 0, 5) }} – {{ substr($order->end_time, 0, 5) }})</span>
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="{{ $order->payment_status === 'verified' ? 'inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300' : ($order->payment_status === 'rejected' ? 'inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300' : 'inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300') }}">
                            {{ __('Payment') }}: {{ __(ucfirst($order->payment_status)) }}
                        </span>
                        <span class="{{ $order->order_status === 'completed' ? 'inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300' : ($order->order_status === 'cancelled' ? 'inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300' : 'inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300') }}">
                            {{ __('Order') }}: {{ __(ucfirst($order->order_status)) }}
                        </span>
                    </div>
                </div>

                {{-- What was ordered --}}
                <ul class="divide-y divide-gray-100 px-5 dark:divide-gray-700/70">
                    @foreach ($order->items as $item)
                        <li class="{{ $itemRow }}">
                            <span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200">
                                <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $item['quantity'] }}×</span>
                                {{ $item['dish_name'] }}
                            </span>
                            <span class="tabular-nums text-gray-900 dark:text-white">₱{{ number_format((float) $item['sub_total'], 2) }}</span>
                        </li>
                    @endforeach
                </ul>

                {{-- How it is being paid, and the total --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 px-5 py-3 dark:border-gray-700">
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $order->method_name ?? '—' }}</span>

                        @if ($order->reference_number)
                            · {{ __('Reference') }} <span class="tabular-nums">{{ $order->reference_number }}</span>
                        @endif

                        @if ($order->proof_url)
                            <x-ui.proof-link :href="$order->proof_url" class="ml-1" />
                        @endif

                        {{-- Why the payment was rejected, so the customer knows what to fix. --}}
                        @if ($order->payment_state === 'rejected')
                            <p class="mt-2 rounded-lg bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200">
                                <span class="font-semibold">{{ __('Payment rejected') }}</span>@if ($order->rejection_reason): {{ $order->rejection_reason }}@endif
                            </p>
                        @endif
                    </div>

                    <p class="text-base font-semibold tabular-nums text-gray-900 dark:text-white">₱{{ number_format((float) $order->total_amount, 2) }}</p>
                </div>

                {{-- A rejected payment on an order that is still waiting: its plates are
                     still reserved, and the customer can send a new proof or pay with cash
                     at pickup. my-orders.js sends both; the URLs are only set while they apply. --}}
                @if ($order->reupload_url || $order->cash_url)
                    <div class="border-t border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-700 dark:bg-gray-700/30" data-fix-payment>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Fix your payment') }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Your order is still held for you. Send a new proof of payment, or pay with cash when you pick it up.') }}
                        </p>

                        <div data-fix-errors role="alert" class="mt-2 hidden rounded-lg bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200"></div>

                        @if ($order->reupload_url)
                            <form data-reupload-form action="{{ $order->reupload_url }}" method="POST" enctype="multipart/form-data" class="mt-3 space-y-2">
                                <div>
                                    <label for="proof-{{ $order->order_id }}" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('New proof of payment') }}</label>
                                    <input type="file" id="proof-{{ $order->order_id }}" name="proof_of_payment" accept="image/*" required
                                           class="mt-1 block w-full rounded-lg border border-gray-300 bg-white text-sm text-gray-700 file:mr-3 file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:file:bg-gray-600">
                                </div>
                                <div>
                                    <label for="reference-{{ $order->order_id }}" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('Reference Number') }}</label>
                                    <input type="text" id="reference-{{ $order->order_id }}" name="reference_number" value="{{ $order->reference_number }}" maxlength="255"
                                           class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                                </div>
                                <button type="submit" data-loading-label="{{ __('Sending…') }}"
                                        class="inline-flex w-full items-center justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto dark:focus:ring-indigo-800">
                                    {{ __('Send new proof') }}
                                </button>
                            </form>
                        @endif

                        @if ($order->cash_url)
                            <div data-cash-switch class="{{ $order->reupload_url ? 'mt-4 border-t border-gray-200 pt-3 dark:border-gray-600' : 'mt-3' }}">
                                <button type="button" data-cash-ask
                                        class="inline-flex w-full items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                                    {{ __('Pay with cash on pickup instead') }}
                                </button>

                                <div data-cash-confirm class="hidden">
                                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ __('Pay :total in cash when you pick up this order?', ['total' => '₱' . number_format((float) $order->total_amount, 2)]) }}</p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <button type="button" data-cash-yes data-url="{{ $order->cash_url }}" data-loading-label="{{ __('Sending…') }}"
                                                class="inline-flex items-center justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-indigo-800">
                                            {{ __('Yes, pay with cash') }}
                                        </button>
                                        <button type="button" data-cash-no
                                                class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                                            {{ __('Back') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </article>
        @empty
            {{-- Nothing ordered yet: point at the menu. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <svg class="mx-auto h-10 w-10 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" />
                </svg>
                <h3 class="mt-3 text-base font-semibold text-gray-900 dark:text-white">{{ __('No orders yet') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Orders you place from the menu show up here.') }}</p>
                <a href="{{ route('menu.index') }}"
                   class="mt-4 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">
                    {{ __('Go to Menu') }}
                </a>
            </div>
        @endforelse
    </div>

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/my-orders.js; see vite.config.js. --}}
        @vite('resources/js/pages/my-orders.js')
    @endpush
</x-app-layout>
