{{-- Dashboard widget `my-spending`: completed orders, month by month. Plain bars: no chart library. --}}
<x-dashboard.card :title="__('Your spending')">
    <p class="mt-3 text-3xl font-bold tabular-nums text-gray-900 dark:text-white">₱{{ number_format($thisMonth, 2) }}</p>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This month so far. Last month: :amount', ['amount' => '₱' . number_format($lastMonth, 2)]) }}</p>

    <ul class="mt-4 flex h-28 items-end gap-2">
        @foreach ($months as $month)
            <li class="flex h-full min-w-0 flex-1 flex-col items-center gap-1" title="{{ $month['label'] }}: ₱{{ number_format($month['total'], 2) }}">
                <span class="sr-only">{{ $month['label'] }}: ₱{{ number_format($month['total'], 2) }}</span>
                <span class="flex w-full flex-1 items-end" aria-hidden="true">
                    <span class="block w-full rounded-t-md bg-accent" style="height: {{ $month['total'] > 0 ? max(4, $month['percent']) : 0 }}%"></span>
                </span>
                <span class="text-xs text-gray-500 dark:text-gray-400" aria-hidden="true">{{ $month['label'] }}</span>
            </li>
        @endforeach
    </ul>

    @if ($saved > 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Discounts saved you :amount in these months.', ['amount' => '₱' . number_format($saved, 2)]) }}</p>
    @endif
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Completed orders only, by the day they were placed.') }}</p>
</x-dashboard.card>
