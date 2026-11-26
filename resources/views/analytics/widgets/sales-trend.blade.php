{{-- Analytics widget `sales-trend`: completed sales per day, week or month. --}}
@php
    $title = ['day' => __('Sales per day'), 'week' => __('Sales per week (from the date shown)'), 'month' => __('Sales per month')][$unit];
@endphp

<x-dashboard.card :csv="$csv ?? null" :title="$title" class="lg:col-span-3">
    @if ($empty)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No orders in this range.') }}</p>
    @else
        <x-analytics.chart :chart="$chart" :label="$title">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('Orders') }}</th><th scope="col">{{ __('Sales') }}</th><th scope="col">{{ __('Still pending') }}</th></tr></thead>
                <tbody>
                    @foreach (array_reverse($rows) as $row)
                        <tr><td>{{ $row['label'] }}</td><td>{{ $row['orders'] }}</td><td>₱{{ number_format($row['sales'], 2) }}</td><td>₱{{ number_format($row['pending'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-analytics.chart>
    @endif
</x-dashboard.card>
