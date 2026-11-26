{{-- Analytics widget `prep-suggestions`: how many plates to prepare next time. As of now. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Preparation suggestions')" class="lg:col-span-3">
    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Estimates from each dish\'s latest ended sessions in the past :days days. The date range does not change this.', ['days' => $lookbackDays]) }}</p>

    @if (! $dishes)
        <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Not enough data yet: a dish needs at least 3 ended sessions.') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Dish') }}</th><th scope="col">{{ __('Sessions') }}</th><th scope="col">{{ __('Ordered (avg)') }}</th><th scope="col">{{ __('Prepared (avg)') }}</th><th scope="col">{{ __('Sold out') }}</th><th scope="col">{{ __('Suggested') }}</th><th scope="col">{{ __('Change') }}</th></tr></thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr>
                            <td>
                                {{ $dish['dish_name'] }}
                                @if ($dish['demandHigher'])
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('Often sold out, at about :count plates: real demand is probably higher.', ['count' => $dish['ranOutAt']]) }}</span>
                                @endif
                            </td>
                            <td>{{ $dish['sessions'] }}</td>
                            <td>{{ $dish['ordered'] }}</td>
                            <td>{{ $dish['prepared'] }}</td>
                            <td>{{ $dish['soldOut'] }}</td>
                            <td class="text-base font-bold text-gray-900 dark:text-white">{{ $dish['suggested'] }}</td>
                            <td class="whitespace-nowrap">{{ $dish['change'] > 0 ? '▲ ' . $dish['change'] : ($dish['change'] < 0 ? '▼ ' . abs($dish['change']) : '=') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        {{ $more > 0 ? __('And :count more dishes with a smaller change.', ['count' => $more]) : '' }}
        {{ $tooFew > 0 ? trans_choice(':count dish has too few sessions for a suggestion.|:count dishes have too few sessions for a suggestion.', $tooFew) : '' }}
        {{ __('Suggested = average ordered plus 10%. When it sold out half the time or more: plus 20%, and never less than 20% above the plates it ran out at. "Change" is against the average prepared. It knows nothing of holidays, events or the day of the week: check it against what you know.') }}
    </p>
</x-dashboard.card>
