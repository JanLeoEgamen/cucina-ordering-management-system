{{-- Analytics widget `companies`: which companies order the most. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Orders by company')" class="lg:col-span-3">
    @if (! $companies)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No orders in this range.') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Company') }}</th><th scope="col">{{ __('Orders') }}</th><th scope="col">{{ __('Customers') }}</th><th scope="col">{{ __('Sales') }}</th></tr></thead>
                <tbody>
                    @foreach ($companies as $company)
                        <tr><td>{{ $company['name'] }}</td><td>{{ $company['orders'] }}</td><td>{{ $company['customers'] }}</td><td class="font-semibold">₱{{ number_format($company['sales'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ $more > 0 ? __('And :count more.', ['count' => $more]) : '' }}
            {{ __('By the company each person has now: someone who moved takes their older orders along.') }}
        </p>
    @endif
</x-dashboard.card>
