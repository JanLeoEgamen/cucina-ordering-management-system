{{-- Dashboard widget `cash-expected`: cash still to be taken at the counter from open orders. --}}
<x-dashboard.card :title="__('Cash to collect')">
    <p class="mt-3 text-3xl font-bold tabular-nums text-gray-900 dark:text-white">₱{{ number_format($total, 2) }}</p>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ trans_choice('from :count open cash order|from :count open cash orders', $orders) }}</p>

    @if ($readyOrders > 0)
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __(':amount of it is already ready for pick up.', ['amount' => '₱' . number_format($readyTotal, 2)]) }}</p>
    @endif
    @if ($undecided > 0)
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count discount is still undecided, so the amount can change.|:count discounts are still undecided, so the amount can change.', $undecided) }}</p>
    @endif
</x-dashboard.card>
