{{-- Analytics widget `menu-fatigue`: often offered, selling less than before. As of now. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Losing interest')" class="lg:col-span-3">
    @if (! $dishes)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No often-offered dish is selling clearly less than before.') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Dish') }}</th><th scope="col">{{ __('Sessions') }}</th><th scope="col">{{ __('Plates each, earlier') }}</th><th scope="col">{{ __('Plates each, lately') }}</th><th scope="col">{{ __('Drop') }}</th></tr></thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr><td>{{ $dish['dish_name'] }}</td><td>{{ $dish['sessions'] }}</td><td>{{ $dish['before'] }}</td><td>{{ $dish['now'] }}</td><td class="font-semibold">▼ {{ $dish['drop'] }}%</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Dishes with at least :min ended sessions in the past :days days, newer half against older half. Less stock or quieter days can cause a drop too. The date range does not change this.', ['min' => $minSessions, 'days' => $lookbackDays]) }}</p>
</x-dashboard.card>
