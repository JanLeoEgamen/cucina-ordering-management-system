{{-- Analytics widget `sellout-speed`: which dishes run out, and how soon. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Sell-out speed')" class="lg:col-span-2">
    @if (! $dishes)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No dish sold out in this range.') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Dish') }}</th><th scope="col">{{ __('Sold out') }}</th><th scope="col">{{ __('How often') }}</th><th scope="col">{{ __('Usually after') }}</th></tr></thead>
                <tbody>
                    @foreach ($dishes as $dish)
                        <tr><td>{{ $dish['dish_name'] }}</td><td>{{ __(':sold of :offered', ['sold' => $dish['soldOut'], 'offered' => $dish['offered']]) }}</td><td>{{ $dish['rate'] }}%</td><td class="whitespace-nowrap">{{ $dish['after'] ?? '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($more > 0)
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('And :count more.', ['count' => $more]) }}</p>
        @endif
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('A dish that often sells out early could use more plates. The time is an estimate: the last order of a dish with nothing left, counted from the session\'s start (the middle value when it sold out more than once).') }}</p>
    @endif
</x-dashboard.card>
