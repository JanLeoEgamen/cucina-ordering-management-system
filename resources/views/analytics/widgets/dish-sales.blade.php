{{-- Analytics widget `dish-sales`: best sellers, slow sellers and dish types. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Dish sales')" class="lg:col-span-3">
    @if ($plates === 0 && ! $slow)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No plates were ordered in this range.') }}</p>
    @else
        <div class="mt-3 grid gap-6 lg:grid-cols-3">
            <div class="min-w-0">
                <h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Best sellers (plates)') }}</h5>
                <x-analytics.bars :rows="array_map(fn ($dish) => [
                    'label' => $dish['dish_name'],
                    'value' => $dish['plates'],
                    'width' => $dish['width'],
                    'note' => '₱' . number_format($dish['amount'], 2),
                ], $top)" />
            </div>

            <div class="min-w-0">
                <h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Slowest when offered') }}</h5>
                @if ($slow)
                    <table class="analytics-table mt-2">
                        <thead><tr><th scope="col">{{ __('Dish') }}</th><th scope="col">{{ __('Sessions') }}</th><th scope="col">{{ __('Plates each') }}</th></tr></thead>
                        <tbody>
                            @foreach ($slow as $dish)
                                <tr><td>{{ $dish['dish_name'] }}</td><td>{{ $dish['sessions'] }}</td><td>{{ $dish['average'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Too few dishes were offered to compare.') }}</p>
                @endif
            </div>

            <div class="min-w-0">
                <h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('By dish type (plates)') }}</h5>
                <x-analytics.bars :rows="array_map(fn ($type) => ['label' => $type['type'], 'value' => $type['plates'], 'width' => $type['width']], $types)" />
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Cancelled orders are left out; amounts are before order discounts. A dish with several types counts in each of them. A session still running counts too.') }}</p>
    @endif
</x-dashboard.card>
