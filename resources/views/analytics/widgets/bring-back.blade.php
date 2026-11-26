{{-- Analytics widget `bring-back`: good sellers missing from the menu. As of now. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Worth bringing back')" class="lg:col-span-3">
    @if (! $dishes)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No good seller has been away for more than :days days.', ['days' => $awayDays]) }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Dish') }}</th><th scope="col">{{ __('Sessions') }}</th><th scope="col">{{ __('Plates each') }}</th><th scope="col">{{ __('Last offered') }}</th></tr></thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr><td>{{ $dish['dish_name'] }}</td><td>{{ $dish['sessions'] }}</td><td>{{ $dish['average'] }}</td><td class="whitespace-nowrap">{{ $dish['last'] }} · {{ trans_choice(':count day ago|:count days ago', $dish['daysAgo']) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Past :lookback days: at least 2 sessions, as many plates each as the typical dish (:typical), not offered for :away days and not on a running or planned session. The date range does not change this.', ['lookback' => $lookbackDays, 'typical' => $typical, 'away' => $awayDays]) }}</p>
</x-dashboard.card>
