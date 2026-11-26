{{-- Analytics widget `weekday-demand`: which dish sells on which day. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Demand by weekday')" class="lg:col-span-3">
    @if (! $dishes)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No order session has ended in this range.') }}</p>
    @else
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Average plates ordered when the dish was offered. A dish\'s best day is in bold; a dash means it was not offered then.') }}</p>
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Dish') }}</th>
                        @foreach ($days as $day)
                            <th scope="col">{{ $day }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr>
                            <td>{{ $dish['dish_name'] }}</td>
                            @foreach ($dish['averages'] as $index => $average)
                                <td class="{{ $dish['best'] === $index ? 'font-bold text-gray-900 dark:text-white' : '' }}">{{ $average ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ $more > 0 ? __('And :count more.', ['count' => $more]) : '' }}
            {{ __('With only a few weeks, one unusual day moves these a lot: pick a longer range.') }}
        </p>
    @endif
</x-dashboard.card>
