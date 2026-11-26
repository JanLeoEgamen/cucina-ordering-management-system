{{-- Analytics widget `lapsed-customers`: used to order, stopped. Names. As of now. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Lapsed customers')" class="lg:col-span-3">
    @if ($total === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Nobody who ordered in the past :within days has been away for :after days.', ['within' => $withinDays, 'after' => $afterDays]) }}</p>
    @else
        <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">{{ trans_choice(':count customer has not ordered for :days days or more.|:count customers have not ordered for :days days or more.', $total, ['days' => $afterDays]) }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Customer') }}</th><th scope="col">{{ __('Orders ever') }}</th><th scope="col">{{ __('Last order') }}</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr><td>{{ $row['name'] }}</td><td>{{ $row['orders'] }}</td><td class="whitespace-nowrap">{{ $row['last'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Those who ordered most come first. People whose last order is more than :within days old are not listed. The date range does not change this.', ['within' => $withinDays]) }}</p>
</x-dashboard.card>
