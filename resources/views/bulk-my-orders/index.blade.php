@php
    // Every bulk order the customer placed, as a card: what was ordered, when, and
    // how it is being paid for. Server rendered: nothing here needs a script.
    $itemRow = 'flex items-center justify-between gap-4 py-1.5 text-sm';
    $secondaryLink = 'inline-flex w-full items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ __('My Bulk Orders') }}</h2>
                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Every bulk order you placed, newest first.') }}
                </p>
            </div>

            @can('view bulk-menu')
                <a href="{{ route('bulk-menu.index') }}" class="{{ $secondaryLink }}">{{ __('Back to Bulk Orders') }}</a>
            @endcan
        </div>
    </x-slot>

    <div id="bulk-my-orders-root" class="container mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        @forelse ($orders as $order)
            {{-- id: a notification about this order opens this page at this card. scroll-mt clears the phone's top bar. --}}
            <article id="bulk-order-{{ $order->bulk_order_id }}" class="mb-4 scroll-mt-20 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm target:ring-2 target:ring-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:target:ring-gray-100">
                {{-- Order number, when it was placed, and where it stands --}}
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                    <div>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $order->order_number }}</p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ \Illuminate\Support\Carbon::parse($order->order_date)->format('M j, Y · g:i A') }}
                            · {{ $order->session_title ?: __('Bulk session') }}
                            {{ \Illuminate\Support\Carbon::parse($order->session_date)->format('M j, Y') }}
                            <span class="tabular-nums">({{ substr($order->start_time, 0, 5) }} – {{ substr($order->end_time, 0, 5) }})</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Payment') }} {!! $order->payment_badge !!}</span>
                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Order') }} {!! $order->status_badge !!}</span>
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
                        <span class="tabular-nums">{{ $order->plates }} {{ trans_choice('plate|plates', $order->plates) }}</span>
                        · <span class="font-medium text-gray-700 dark:text-gray-300">{{ $order->method_name ?? '—' }}</span>

                        @if ($order->reference_number)
                            · {{ __('Reference') }} <span class="tabular-nums">{{ $order->reference_number }}</span>
                        @endif

                        @if ($order->proof_url)
                            <x-ui.proof-link :href="$order->proof_url" class="ml-1" />
                        @endif

                        {{-- Why the payment was rejected, so the customer knows what to fix. --}}
                        @if ($order->payment_status === 'rejected')
                            <p class="mt-2 rounded-lg bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200">
                                <span class="font-semibold">{{ __('Payment rejected') }}</span>@if ($order->rejection_reason): {{ $order->rejection_reason }}@endif
                            </p>
                        @endif
                    </div>

                    <p class="text-base font-semibold tabular-nums text-gray-900 dark:text-white">₱{{ number_format((float) $order->total_amount, 2) }}</p>
                </div>
            </article>
        @empty
            {{-- Nothing ordered yet: point at the bulk menu. --}}
            <div class="rounded-xl border border-gray-200 bg-white p-10 text-center dark:border-gray-700 dark:bg-gray-800">
                <svg class="mx-auto h-10 w-10 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2" />
                </svg>
                <h3 class="mt-3 text-base font-semibold text-gray-900 dark:text-white">{{ __('No bulk orders yet') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Bulk orders you place show up here.') }}</p>

                @can('view bulk-menu')
                    <a href="{{ route('bulk-menu.index') }}"
                       class="mt-4 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">
                        {{ __('Go to Bulk Orders') }}
                    </a>
                @endcan
            </div>
        @endforelse
    </div>
</x-app-layout>
