{{-- Analytics widget `pairings`: dishes ordered together. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Ordered together')" class="lg:col-span-3">
    @if (! $pairs)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No two dishes shared :min or more orders in this range.', ['min' => $minOrders]) }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Dishes') }}</th><th scope="col">{{ __('Orders with both') }}</th><th scope="col" class="is-text">{{ __('How strong') }}</th></tr></thead>
                <tbody>
                    @foreach ($pairs as $pair)
                        <tr><td>{{ $pair['dishes'] }}</td><td class="font-semibold">{{ $pair['orders'] }}</td><td class="is-text">{{ __(':share% of the orders with :of also had :with.', ['share' => $pair['share'], 'of' => $pair['of'], 'with' => $pair['with']]) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('A starting point for combo ideas. Dishes offered on the same days meet more often, whatever people prefer.') }}</p>
    @endif
</x-dashboard.card>
