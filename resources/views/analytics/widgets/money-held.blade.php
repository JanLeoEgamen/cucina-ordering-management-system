{{-- Analytics widget `money-held`: paid and verified, the order still open. As of now. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Paid, not completed (right now)')" class="lg:col-span-3">
    @if ($orders === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('Every verified payment belongs to a finished order.') }}</p>
    @else
        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">₱{{ number_format($amount, 2) }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ trans_choice(':count paid order is still open|:count paid orders are still open', $orders) }}</p>

        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Order') }}</th><th scope="col" class="is-text">{{ __('Customer') }}</th><th scope="col" class="is-text">{{ __('Session') }}</th><th scope="col" class="is-text">{{ __('Method') }}</th><th scope="col">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="whitespace-nowrap"><a href="{{ $row['url'] }}" class="font-semibold hover:underline">{{ $row['number'] }}</a>{{ $row['ready'] ? ' · ' . __('ready') : '' }}</td>
                            <td class="is-text">{{ $row['customer'] }}</td>
                            <td class="is-text">{{ $row['session'] ?? '—' }}</td>
                            <td class="is-text">{{ $row['method'] }}</td>
                            <td>₱{{ number_format($row['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($orders > count($rows))
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The oldest :count are listed.', ['count' => count($rows)]) }}</p>
        @endif
    @endif
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The date range does not change this.') }}</p>
</x-dashboard.card>
