{{-- Analytics widget `payment-methods`: how customers pay. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Sales by payment method')">
    @if (! $methods)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No orders in this range.') }}</p>
    @else
        <x-analytics.bars :rows="array_map(fn ($method) => [
            'label' => $method['name'],
            'value' => '₱' . number_format($method['sales'], 2),
            'width' => $method['width'],
            'note' => trans_choice(':count order|:count orders', $method['orders']) . ' · ' . __(':share% of orders', ['share' => $method['share']]),
        ], $methods)" />
    @endif
</x-dashboard.card>
