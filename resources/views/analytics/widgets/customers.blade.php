{{-- Analytics widget `customers`: new and returning, and how often. Totals only. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Who ordered')" class="lg:col-span-2">
    @if ($customers === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No orders in this range.') }}</p>
    @else
        <dl class="mt-3 grid grid-cols-3 gap-3">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Customers') }}</dt>
                <dd class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($customers) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('New') }}</dt>
                <dd class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($new) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('Returning') }}</dt>
                <dd class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($returning) }}</dd>
            </div>
        </dl>
        <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">{{ __('Each ordered :times times in this range, on average.', ['times' => number_format($perCustomer, 1)]) }}</p>
        <x-analytics.bars :rows="array_map(fn ($bucket) => [
            'label' => $bucket['label'],
            'value' => $bucket['customers'] . ' · ' . $bucket['share'] . '%',
            'width' => $bucket['width'],
        ], $buckets)" />
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('New = their first order ever is in this range. Cancelled orders are left out.') }}</p>
    @endif
</x-dashboard.card>
