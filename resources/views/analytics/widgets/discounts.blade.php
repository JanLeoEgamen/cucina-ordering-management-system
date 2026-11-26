{{-- Analytics widget `discounts`: totals per discount. Never who used one. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Discounts')" class="lg:col-span-3">
    @if ($orders === 0)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No order in this range used a discount.') }}</p>
    @else
        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">₱{{ number_format($given, 2) }}</p>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('given on completed orders') }} · {{ trans_choice(':count order with a discount|:count orders with a discount', $orders) }} · {{ __(':count rejected', ['count' => $rejected]) }}
        </p>
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Discount') }}</th><th scope="col">{{ __('Orders') }}</th><th scope="col">{{ __('Approved') }}</th><th scope="col">{{ __('Rejected') }}</th><th scope="col">{{ __('Waiting') }}</th><th scope="col">{{ __('Given') }}</th></tr></thead>
                <tbody>
                    @foreach ($discounts as $discount)
                        <tr><td>{{ $discount['name'] }}</td><td>{{ $discount['orders'] }}</td><td>{{ $discount['approved'] }}</td><td>{{ $discount['rejected'] }}</td><td>{{ $discount['pending'] }}</td><td class="font-semibold">₱{{ number_format($discount['given'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Regular orders only, by the day placed; cancelled orders are left out. A discount rejected and sent again shows its latest state.') }}</p>
    @endif
</x-dashboard.card>
