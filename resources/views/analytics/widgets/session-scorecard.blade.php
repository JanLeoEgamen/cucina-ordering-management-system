{{-- Analytics widget `session-scorecard`: one line per order session. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Session scorecard')" class="lg:col-span-3">
    @if (! $sessions)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('No order session has started in this range.') }}</p>
    @else
        <div class="mt-3 overflow-x-auto">
            <table class="analytics-table">
                <thead><tr><th scope="col">{{ __('Session') }}</th><th scope="col">{{ __('Orders') }}</th><th scope="col">{{ __('Sales') }}</th><th scope="col">{{ __('Plates ordered') }}</th><th scope="col">{{ __('Ordered %') }}</th><th scope="col">{{ __('Dishes sold out') }}</th><th scope="col">{{ __('Left over') }}</th></tr></thead>
                <tbody>
                    @foreach ($sessions as $row)
                        <tr>
                            <td class="whitespace-nowrap">
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" class="font-semibold hover:underline">{{ $row['date'] }}</a>
                                @else
                                    <span class="font-semibold">{{ $row['date'] }}</span>
                                @endif
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['window'] }}{{ $row['ended'] ? '' : ' · ' . __('still running') }}</span>
                            </td>
                            <td>{{ $row['orders'] }}</td>
                            <td>₱{{ number_format($row['sales'], 2) }}</td>
                            <td class="whitespace-nowrap">{{ __(':ordered of :prepared', ['ordered' => $row['ordered'], 'prepared' => $row['prepared']]) }}</td>
                            <td>{{ $row['rate'] }}%</td>
                            <td>{{ $row['soldOut'] }}</td>
                            <td>{{ $row['left'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($more > 0)
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The newest are listed; :count older ones are not.', ['count' => $more]) }}</p>
        @endif
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Sessions are picked by their own date. Sales are the completed orders of the session.') }}</p>
    @endif
</x-dashboard.card>
