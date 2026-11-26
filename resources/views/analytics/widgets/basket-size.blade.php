{{-- Analytics widget `basket-size`: how big an order usually is. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Basket size')">
    @if ($orders === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No orders in this range.') }}</p>
    @else
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
            {{ __('An order holds :plates plates and costs :amount on average.', ['plates' => number_format($averagePlates, 1), 'amount' => '₱' . number_format($averageAmount, 2)]) }}
        </p>
        <x-analytics.bars :rows="array_map(fn ($size) => [
            'label' => $size['label'],
            'value' => $size['orders'] . ' · ' . $size['share'] . '%',
            'width' => $size['width'],
        ], $sizes)" />
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Cancelled orders are left out.') }}</p>
    @endif
</x-dashboard.card>
