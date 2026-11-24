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
         data-pick-proof="{{ __('Choose the image of your new proof of payment.') }}"
         data-pick-id="{{ __('Choose the picture of your new ID.') }}">
        {{-- Search and filters, collapsible: a plain GET form, so the pager keeps them (withQueryString) and a
             filtered list can be bookmarked. Shown while there is anything to filter.
             Collapsed it is one round search icon (its badge counts what is applied); a click opens the slim
             bar (search + a filter icon + Search + close), and the four filters live in a popover that
             opens from the filter icon. The bar starts open while a search / filter is applied. --}}
        @if ($orders->total() > 0 || $filtered)
            @php
                $activeFilters = collect(['status', 'payment', 'from', 'to'])->filter(fn ($key) => ($filters[$key] ?? '') !== '')->count();
                $field = 'mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-base text-gray-900 transition focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
                $label = 'block text-xs font-medium text-gray-600 dark:text-gray-300';
                $appliedCount = $activeFilters + ($filters['q'] !== '' ? 1 : 0);
            @endphp
            <div x-data="{ bar: {{ $filtered ? 'true' : 'false' }}, open: false, clip: false }" @keydown.escape="open = false" class="mb-4">
                {{-- The two states trade places by growing / shrinking their grid row (0fr <-> 1fr), so the
                     list below glides up or down instead of jumping; the bar also fades and slides in.
                     Overflow is clipped only while that runs (clip), so the filters popover and the badge
                     are never cut off once it is still. Reduced motion zeroes every duration (app.css). --}}
                <div class="grid transition-[grid-template-rows] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] {{ $filtered ? 'grid-rows-[0fr]' : 'grid-rows-[1fr]' }}"
                     :class="{ 'grid-rows-[0fr]': bar, 'grid-rows-[1fr]': !bar }">
                    <div class="min-h-0 {{ $filtered ? 'overflow-hidden' : '' }}" :class="{ 'overflow-hidden': bar || clip }">
                        <div class="flex justify-end transition-opacity duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] {{ $filtered ? 'opacity-0' : '' }}"
                             :class="{ 'opacity-0': bar, 'opacity-100': ! bar }" :inert="bar">
                            <button type="button" @click="bar = true; clip = true; setTimeout(() => clip = false, 520); $nextTick(() => $refs.q.focus())" :aria-expanded="bar.toString()" aria-controls="orders-search"
                                    aria-label="{{ __('Search and filter my orders') }}" title="{{ __('Search and filter my orders') }}"
                                    class="relative inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                                </svg>
                                @if ($appliedCount > 0)
                                    <span class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-indigo-600 px-1 text-xs font-semibold text-white">{{ $appliedCount }}</span>
                                @endif
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid transition-[grid-template-rows] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] {{ $filtered ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]' }}"
                     :class="{ 'grid-rows-[1fr]': bar, 'grid-rows-[0fr]': !bar }">
                    <div class="min-h-0 {{ $filtered ? '' : 'overflow-hidden' }}" :class="{ 'overflow-hidden': !bar || clip }">
                        <form id="orders-search" method="GET" action="{{ route('my-orders.index') }}" role="search" aria-label="{{ __('Search and filter my orders') }}"
                              @click.outside="open = false"
                              class="relative rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition-[clip-path,opacity,transform] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)] dark:border-gray-700 dark:bg-gray-800 {{ $filtered ? '' : 'translate-x-8 [clip-path:inset(-600px_-24px_-600px_100%)] opacity-0' }}"
                              :class="{ 'translate-x-0 [clip-path:inset(-600px_-24px_-600px_-4px)] opacity-100': bar, 'translate-x-8 [clip-path:inset(-600px_-24px_-600px_100%)] opacity-0': ! bar }" :inert="! bar">
                            <div class="flex flex-wrap items-center gap-2 sm:flex-nowrap">
                                <div class="relative min-w-0 flex-1 basis-full sm:basis-auto">
                                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" />
                                    </svg>
                                    <label for="orders-q" class="sr-only">{{ __('Search my orders') }}</label>
                                    <input type="search" id="orders-q" name="q" x-ref="q" value="{{ $filters['q'] }}" maxlength="100"
                                           placeholder="{{ __('Search order number, dish, payment method or reference') }}"
                                           class="block w-full truncate rounded-full border border-gray-300 bg-white py-2 pl-9 pr-3 text-base text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:ring-gray-600">
                                </div>

                                {{-- Icon only: the badge says how many filters are on. --}}
                                <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-controls="orders-filters"
                                        aria-label="{{ __('Filters') }}" title="{{ __('Filters') }}"
                                        class="relative inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M6 12h12M10 18h4" />
                                    </svg>
                                    @if ($activeFilters > 0)
                                        <span class="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-indigo-600 px-1 text-xs font-semibold text-white">{{ $activeFilters }}</span>
                                    @endif
                                </button>

                                <button type="submit"
                                        class="inline-flex h-10 flex-1 items-center justify-center rounded-full bg-indigo-600 px-5 text-sm sm:flex-none font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">
                                    {{ __('Search') }}
                                </button>

                                <button type="button" @click="bar = false; open = false; clip = true; setTimeout(() => clip = false, 520)" aria-label="{{ __('Close search') }}" title="{{ __('Close search') }}"
                                        class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6 6 18" />
                                    </svg>
                                </button>
                            </div>

                            {{-- The filters pop open under the bar (over the orders, nothing is pushed down). --}}
                            <div id="orders-filters" x-show="open" x-cloak
                                     x-transition:enter="transition-[clip-path,opacity] duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]" x-transition:enter-start="[clip-path:inset(-24px_-24px_-24px_100%)] opacity-0" x-transition:enter-end="[clip-path:inset(-24px)] opacity-100"
                                     x-transition:leave="transition-[clip-path,opacity] duration-300 ease-[cubic-bezier(0.22,1,0.36,1)]" x-transition:leave-start="[clip-path:inset(-24px)] opacity-100" x-transition:leave-end="[clip-path:inset(-24px_-24px_-24px_100%)] opacity-0"
                                 class="absolute inset-x-0 top-full z-20 mt-2 rounded-xl border border-gray-200 bg-white p-4 shadow-lg sm:left-auto sm:w-96 dark:border-gray-700 dark:bg-gray-800">
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label for="orders-status" class="{{ $label }}">{{ __('Order status') }}</label>
                                        <select id="orders-status" name="status" class="{{ $field }}">
                                            <option value="">{{ __('All') }}</option>
                                            @foreach (\App\Models\Order::USER_ORDER_STATUSES as $value)
                                                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ __(ucfirst($value)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="orders-payment" class="{{ $label }}">{{ __('Payment status') }}</label>
                                        <select id="orders-payment" name="payment" class="{{ $field }}">
                                            <option value="">{{ __('All') }}</option>
                                            @foreach (\App\Models\Order::USER_PAYMENT_STATUSES as $value)
                                                <option value="{{ $value }}" @selected($filters['payment'] === $value)>{{ __(ucfirst($value)) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div>
                                        <label for="orders-from" class="{{ $label }}">{{ __('Placed from') }}</label>
                                        <input type="date" id="orders-from" name="from" value="{{ $filters['from'] }}" class="{{ $field }}">
                                    </div>

                                    <div>
                                        <label for="orders-to" class="{{ $label }}">{{ __('Placed until') }}</label>
                                        <input type="date" id="orders-to" name="to" value="{{ $filters['to'] }}" class="{{ $field }}">
                                    </div>
                                </div>

                                <button type="submit"
                                        class="mt-4 inline-flex w-full items-center justify-center rounded-full bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">
                                    {{ __('Apply filters') }}
                                </button>
                            </div>

                            @if ($filtered)
                                <div class="mt-2 text-right">
                                    <a href="{{ route('my-orders.index') }}"
                                       class="text-xs font-medium text-gray-500 underline-offset-2 transition hover:text-gray-900 hover:underline focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:text-white">
                                        {{ __('Clear filters') }}
                                    </a>
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            </div>
        @endif

        @forelse ($orders as $order)
            {{-- id: a notification about this order opens My Orders at this card. scroll-mt clears the phone's top bar. --}}
            {{-- data-order: what the order popup shows (resources/js/ui/order-cards.js). A click on the
                 card outside its links, forms and the Fix your payment panel opens it; the Details
                 button is the same thing for the keyboard. --}}
            <article id="order-{{ $order->order_id }}" data-order="{{ json_encode($order->popup) }}" class="mb-4 scroll-mt-20 cursor-pointer overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition hover:shadow-md target:ring-2 target:ring-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:target:ring-gray-100">
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
                        @php($isReady = $order->order_status === 'pending' && $order->ready_at !== null)
                        <span class="{{ $order->order_status === 'completed' || $isReady ? 'inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-300' : ($order->order_status === 'cancelled' ? 'inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300' : 'inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300') }}">
                            @if ($isReady)
                                {{ __('Ready for pick up') }}
                            @else
                                {{ __('Order') }}: {{ __(ucfirst($order->order_status)) }}
                            @endif
                        </span>
                        <button type="button" data-order-open aria-haspopup="dialog"
                                aria-label="{{ __('Details of order :number', ['number' => $order->order_number]) }}"
                                class="rounded-full border border-gray-200 px-3 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:border-gray-600 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200">
                            {{ __('Details') }}
                        </button>
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

                    {{-- The discount: what it took off (while it waits for staff it is "expected"), or why it was not given. --}}
                    @if (($order->discount_status ?? 'none') !== 'none')
                        <li class="{{ $itemRow }}">
                            <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-200">
                                {{ $order->discount_name }} ({{ (float) $order->discount_percent }}%)
                                {!! $order->popup['discount_badge'] !!}
                            </span>
                            <span class="tabular-nums text-gray-900 dark:text-white">
                                @if ($order->discount_status === 'rejected')
                                    —
                                @else
                                    −₱{{ number_format((float) $order->discount_amount, 2) }}
                                @endif
                            </span>
                        </li>
                    @endif
                </ul>

                @if (($order->discount_status ?? 'none') === 'pending')
                    <p class="mx-5 mb-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ __('Staff will check your ID before the discount is final. Until then the payment cannot be verified.') }}</p>
                @elseif (($order->discount_status ?? 'none') === 'rejected')
                    <p class="mx-5 mb-3 rounded-lg bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200">
                        <span class="font-semibold">{{ __('Discount not approved') }}</span>@if ($order->discount_reject_reason): {{ $order->discount_reject_reason }}@endif
                        {{ __('The order costs its full price.') }}
                    </p>
                @endif

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

                {{-- Cancel your own pending order: only set while System Configuration allows it and the
                     payment is not verified. Asks first, inline; my-orders.js sends it. --}}
                @if ($order->cancel_url)
                    <div class="border-t border-gray-200 px-5 py-3 dark:border-gray-700" data-cancel-order>
                        <div data-fix-errors role="alert" class="mb-2 hidden rounded-lg bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200"></div>

                        <button type="button" data-cancel-ask
                                class="inline-flex w-full items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                            {{ __('Cancel order') }}
                        </button>

                        <div data-cancel-confirm class="hidden">
                            <p class="text-sm text-gray-800 dark:text-gray-200">{{ __('Cancel order :number? Its plates go back on the menu.', ['number' => $order->order_number]) }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" data-cancel-yes data-url="{{ $order->cancel_url }}" data-loading-label="{{ __('Cancelling…') }}"
                                        class="inline-flex items-center justify-center rounded-full bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-indigo-800">
                                    {{ __('Yes, cancel my order') }}
                                </button>
                                <button type="button" data-cancel-no
                                        class="inline-flex items-center justify-center rounded-full border border-gray-300 bg-white px-5 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                                    {{ __('Keep it') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- A rejected discount on an order that is still waiting: the customer may send a new ID and the
                     discount waits for staff again. my-orders.js sends it (same script as the new payment proof);
                     the URL is only set while it applies. --}}
                @if ($order->discount_reupload_url)
                    <div class="border-t border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-700 dark:bg-gray-700/30" data-fix-discount>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Try the discount again') }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('Send a clearer picture of your ID, or a different one. The discount goes back to staff to check, and the total goes back to the discounted price meanwhile.') }}
                        </p>

                        <div data-fix-errors role="alert" class="mt-2 hidden rounded-lg bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200"></div>

                        <form data-reupload-form action="{{ $order->discount_reupload_url }}" method="POST" enctype="multipart/form-data" class="mt-3 space-y-2">
                            <div>
                                <label for="discount-id-{{ $order->order_id }}" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('New ID for the discount') }}</label>
                                <input type="file" id="discount-id-{{ $order->order_id }}" name="discount_proof" accept="image/png,image/jpeg,image/webp" required
                                       class="mt-1 block w-full rounded-lg border border-gray-300 bg-white text-sm text-gray-700 file:mr-3 file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:file:bg-gray-600">
                            </div>
                            <button type="submit" data-loading-label="{{ __('Sending…') }}"
                                    class="inline-flex w-full items-center justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto dark:focus:ring-indigo-800">
                                {{ __('Send new ID') }}
                            </button>
                        </form>
                    </div>
                @endif

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
            @if ($filtered)
                {{-- Orders exist, none matches: say so, and the way back is the Clear filters button. --}}
                <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('No orders match') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Try a different search or clear the filters.') }}</p>
                </div>
            @else
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
            @endif
        @endforelse

        {{-- Ten orders a page; nothing when they all fit on one. --}}
        {{ $orders->links('pagination.numbered') }}
    </div>

    {{-- The order popup: filled from the card's data-order, read only here (no staff actions). --}}
    <x-ui.order-popup />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/my-orders.js; started by resources/js/ui/soft-nav.js. --}}
        <x-page-module name="my-orders" />
    @endpush
</x-app-layout>
