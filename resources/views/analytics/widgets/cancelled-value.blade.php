{{-- Analytics widget `cancelled-value`: what cancellations cost, who made them and why. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Cancellations')" class="lg:col-span-3">
    @if ($orders === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No cancelled orders in this range.') }}</p>
    @else
        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">₱{{ number_format($amount, 2) }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ trans_choice(':count cancelled order|:count cancelled orders', $orders) }} · {{ trans_choice(':count plate|:count plates', $plates) }}
        </p>

        <div class="mt-4 grid gap-6 md:grid-cols-2">
            <table class="analytics-table self-start">
                <thead><tr><th scope="col">{{ __('Cancelled') }}</th><th scope="col">{{ __('Orders') }}</th><th scope="col">{{ __('Value') }}</th></tr></thead>
                <tbody>
                    @foreach ($who as $row)
                        <tr><td>{{ $row['label'] }}</td><td>{{ $row['orders'] }}</td><td>₱{{ number_format($row['amount'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            @if ($reasons)
                <table class="analytics-table self-start">
                    <thead><tr><th scope="col">{{ __('Most common reasons staff typed') }}</th><th scope="col">{{ __('Orders') }}</th></tr></thead>
                    <tbody>
                        @foreach ($reasons as $row)
                            <tr><td class="break-words">{{ $row['reason'] }}</td><td>{{ $row['orders'] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('By the day the order was placed. Who cancelled is "Not recorded" for orders cancelled before the app kept it.') }}</p>
    @endif
</x-dashboard.card>
