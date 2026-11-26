{{-- Analytics widget `sell-through`: prepared against ordered, over ended sessions. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Leftover plates')" class="lg:col-span-3">
    @if ($sessions === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No order session has ended in this range.') }}</p>
    @else
        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ trans_choice(':count plate left over|:count plates left over', $left, ['count' => number_format($left)]) }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __(':ordered of :prepared plates were ordered (:rate%), over :sessions ended sessions.', ['ordered' => number_format($ordered), 'prepared' => number_format($prepared), 'rate' => $rate, 'sessions' => $sessions]) }}
        </p>

        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Dish') }}</th><th scope="col">{{ __('Sessions') }}</th><th scope="col">{{ __('Prepared') }}</th><th scope="col">{{ __('Ordered') }}</th><th scope="col">{{ __('Left over') }}</th><th scope="col">{{ __('Left per session') }}</th><th scope="col">{{ __('Ordered %') }}</th></tr></thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr><td>{{ $dish['dish_name'] }}</td><td>{{ $dish['sessions'] }}</td><td>{{ $dish['prepared'] }}</td><td>{{ $dish['ordered'] }}</td><td class="font-semibold">{{ $dish['left'] }}</td><td>{{ $dish['leftPerSession'] }}</td><td>{{ $dish['rate'] }}%</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($more > 0)
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('And :count more dishes with fewer leftovers.', ['count' => $more]) }}</p>
        @endif
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Left over means not ordered, not measured food waste. Changing a session\'s stock afterwards changes these numbers.') }}</p>
    @endif
</x-dashboard.card>
