{{-- Analytics widget `company-participation`: of those registered, how many order. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Company participation')" class="lg:col-span-3">
    @if (! $companies)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No account can place orders yet.') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Company') }}</th><th scope="col">{{ __('Accounts') }}</th><th scope="col">{{ __('Ordered in range') }}</th><th scope="col">{{ __('Share') }}</th></tr></thead>
                <tbody>
                    @foreach ($companies as $company)
                        <tr><td>{{ $company['name'] }}</td><td>{{ $company['registered'] }}</td><td>{{ $company['ordering'] }}</td><td class="font-semibold">{{ $company['rate'] }}%</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ $more > 0 ? __('And :count more.', ['count' => $more]) : '' }}
            {{ __('Of the people registered in this app who can order, not of all a company\'s employees.') }}
        </p>
    @endif
</x-dashboard.card>
