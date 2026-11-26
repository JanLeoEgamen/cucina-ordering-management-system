{{-- Analytics widget `order-times`: when people order, to plan who is at the counter. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('When people order')" class="lg:col-span-3">
    @if ($empty)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No orders in this range.') }}</p>
    @else
        <p class="mt-3 text-sm text-gray-700 dark:text-gray-200">{{ __('The busiest hour starts at :hour: :orders orders, :share% of them all.', ['hour' => $busiest['label'], 'orders' => $busiest['orders'], 'share' => $busiest['share']]) }}</p>

        <x-analytics.chart :chart="$chart" :label="__('Orders by hour of the day')">
            <table class="analytics-table">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Hour') }}</th>
                        @foreach ($days as $day)
                            <th scope="col">{{ $day }}</th>
                        @endforeach
                        <th scope="col">{{ __('All days') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($hours as $hour)
                        <tr>
                            <td>{{ $hour['label'] }}</td>
                            @foreach ($hour['byDay'] as $count)
                                <td>{{ $count }}</td>
                            @endforeach
                            <td class="font-semibold">{{ $hour['orders'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-analytics.chart>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('By the time the order was placed; cancelled orders are left out. The table splits each hour by weekday.') }}</p>
    @endif
</x-dashboard.card>
