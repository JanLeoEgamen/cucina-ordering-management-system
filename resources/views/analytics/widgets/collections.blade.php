{{-- Analytics widget `collections`: verified money per day and method, and who verified it. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Collections')" class="lg:col-span-3">
    @if ($payments === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No payments were verified in this range.') }}</p>
    @else
        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">₱{{ number_format($total, 2) }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ trans_choice(':count verified payment|:count verified payments', $payments) }} · {{ __('of it in cash: :amount', ['amount' => '₱' . number_format($cash, 2)]) }}
        </p>

        <x-analytics.chart :chart="$chart" :label="__('Money collected per day by payment method')" :open="true">
            <table class="analytics-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Day verified') }}</th>
                        @foreach ($methods as $method)
                            <th scope="col">{{ $method }}</th>
                        @endforeach
                        <th scope="col">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days as $day)
                        <tr>
                            <td class="whitespace-nowrap">{{ $day['label'] }}</td>
                            @foreach ($day['amounts'] as $amount)
                                <td>₱{{ number_format($amount, 2) }}</td>
                            @endforeach
                            <td class="font-semibold">₱{{ number_format($day['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-analytics.chart>

        <h5 class="mt-6 text-sm font-semibold text-gray-900 dark:text-white">{{ __('Who verified it') }}</h5>
        <div class="mt-1 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Person') }}</th><th scope="col">{{ __('Payments') }}</th><th scope="col">{{ __('Cash') }}</th><th scope="col">{{ __('All methods') }}</th></tr></thead>
                <tbody>
                    @foreach ($people as $person)
                        <tr><td>{{ $person['name'] }}</td><td>{{ $person['payments'] }}</td><td>₱{{ number_format($person['cash'], 2) }}</td><td>₱{{ number_format($person['total'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('A cash payment is verified by whoever completes its order, so their cash is what should be in the drawer. A verification that was undone is not counted.') }}</p>
    @endif
</x-dashboard.card>
