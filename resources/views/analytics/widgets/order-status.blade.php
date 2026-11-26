{{-- Analytics widget `order-status`: how the range's orders ended up. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Order status')">
    @if ($placed === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No orders in this range.') }}</p>
    @else
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ trans_choice(':count order placed|:count orders placed', $placed) }}</p>
        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($statuses as $status)
                <li class="flex items-center justify-between gap-3 py-2.5">
                    <x-dashboard.pill :status="$status['status']">{{ $status['label'] }}</x-dashboard.pill>
                    <span class="text-right text-sm">
                        <span class="block font-semibold tabular-nums text-gray-900 dark:text-white">{{ $status['orders'] }} · {{ $status['share'] }}%</span>
                        <span class="block text-xs tabular-nums text-gray-500 dark:text-gray-400">₱{{ number_format($status['amount'], 2) }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</x-dashboard.card>
