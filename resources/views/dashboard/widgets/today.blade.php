{{-- Dashboard widget `today`: today so far beside the same weekday last week up to the same time. --}}
<x-dashboard.card :title="__('Today so far')" class="lg:col-span-3">
    <dl class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($tiles as $tile)
            <div class="min-w-0">
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $tile['money'] ? '₱' . number_format($tile['value'], 2) : $tile['value'] }}</dd>
                <dd class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    @if ($tile['change'] === null)
                        {{ __('Nothing to compare with') }}
                    @else
                        <span class="font-semibold {{ $tile['change'] > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($tile['change'] < 0 ? 'text-red-700 dark:text-red-400' : '') }}">{{ $tile['change'] > 0 ? '▲' : ($tile['change'] < 0 ? '▼' : '•') }} {{ abs($tile['change']) }}%</span>
                        {{ __('from :value', ['value' => $tile['money'] ? '₱' . number_format($tile['before'], 2) : $tile['before']]) }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>

    <p class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
        {{ __('Completed sales: :sales', ['sales' => '₱' . number_format($sales, 2)]) }}
        · {{ __('Still pending: :amount', ['amount' => '₱' . number_format($expected, 2)]) }}
        · {{ trans_choice(':count cancelled order|:count cancelled orders', $cancelled) }}
    </p>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Compared with :day, up to the same time of day.', ['day' => $comparedWith]) }}</p>
</x-dashboard.card>
