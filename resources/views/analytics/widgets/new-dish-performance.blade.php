{{-- Analytics widget `new-dish-performance`: how new dishes did at the start. As of now. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('New dishes')" class="lg:col-span-3">
    @if (! $dishes)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No dish added in the past :days days has finished a session yet.', ['days' => $days]) }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Dish') }}</th><th scope="col">{{ __('Added') }}</th><th scope="col">{{ __('Sessions') }}</th><th scope="col">{{ __('Plates each') }}</th><th scope="col">{{ __('Ordered %') }}</th><th scope="col">{{ __('So far') }}</th></tr></thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr>
                            <td>{{ $dish['dish_name'] }}</td>
                            <td>{{ $dish['added'] }}</td>
                            <td>{{ $dish['sessions'] }}</td>
                            <td>{{ $dish['average'] }}</td>
                            <td>{{ $dish['rate'] }}%</td>
                            <td class="whitespace-nowrap">
                                {{ ['early' => __('Too early to say'), 'above' => __('Above average'), 'below' => __('Below average'), 'average' => __('About average')][$dish['verdict']] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        {{ $more > 0 ? __('And :count more.', ['count' => $more]) : '' }}
        {{ __('Each dish\'s first :sample ended sessions, beside the average dish of the past :days days (:usual plates per session). The date range does not change this.', ['sample' => $sample, 'days' => $days, 'usual' => $usual]) }}
    </p>
</x-dashboard.card>
