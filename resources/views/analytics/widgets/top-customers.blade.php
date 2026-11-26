{{-- Analytics widget `top-customers`: names and spending. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Top customers')" class="lg:col-span-3">
    @if (! $rows)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No completed orders in this range.') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Customer') }}</th><th scope="col" class="is-text">{{ __('Company') }}</th><th scope="col">{{ __('Completed orders') }}</th><th scope="col">{{ __('Sales') }}</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr><td>{{ $row['name'] }}</td><td class="is-text">{{ $row['company'] ?? '—' }}</td><td>{{ $row['orders'] }}</td><td class="font-semibold">₱{{ number_format($row['sales'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-dashboard.card>
