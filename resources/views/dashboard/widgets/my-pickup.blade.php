{{-- Dashboard widget `my-pickup`: what to have in hand at the counter. --}}
<x-dashboard.card :title="__('Pickup checklist')">
    @if ($cashDue > 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Bring in cash') }}</p>
        <p class="text-3xl font-bold tabular-nums text-gray-900 dark:text-white">₱{{ number_format($cashDue, 2) }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('for :count order|for :count orders', $cashOrders) }}</p>
        @if ($priceMayChange)
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Your discount is still being checked, so this can change.') }}</p>
        @endif
    @else
        <p class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ __('No cash to bring.') }}</p>
    @endif

    @if ($ready)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Ready now. Show these numbers:') }}</p>
        <ul class="mt-1 space-y-1">
            @foreach ($ready as $order)
                <li><a href="{{ $order['url'] }}" class="text-sm font-semibold tabular-nums text-gray-900 hover:underline dark:text-white">{{ $order['number'] }}</a></li>
            @endforeach
        </ul>
    @endif

    @if ($notReady > 0)
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ trans_choice(':count order is not ready yet.|:count orders are not ready yet.', $notReady) }}</p>
    @endif
</x-dashboard.card>
