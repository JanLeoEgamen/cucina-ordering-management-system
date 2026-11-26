{{-- Analytics widget `repeat-rate`: do they come back? Recent months. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Do they come back?')">
    @if ($empty)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Not enough data yet: no month old enough to judge has orders.') }}</p>
    @else
        <table class="analytics-table mt-3">
            <thead><tr><th scope="col">{{ __('Month') }}</th><th scope="col">{{ __('Customers') }}</th><th scope="col">{{ __('Came back') }}</th></tr></thead>
            <tbody>
                @foreach ($months as $month)
                    <tr><td>{{ $month['label'] }}</td><td>{{ $month['customers'] }}</td><td class="font-semibold">{{ $month['rate'] === null ? '—' : $month['rate'] . '%' }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Came back = ordered again within :days days of their first order that month. A month appears once that time has passed. The date range does not change this.', ['days' => $days]) }}</p>
</x-dashboard.card>
