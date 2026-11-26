{{-- Analytics widget `sales-summary`: the range beside the one before it. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Summary')" class="lg:col-span-3">
    <dl class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($tiles as $tile)
            <div class="min-w-0">
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $tile['label'] }}</dt>
                <dd class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $tile['money'] ? '₱' . number_format($tile['value'], 2) : number_format($tile['value']) }}</dd>
                <dd class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    @if ($tile['change'] === null)
                        {{ __('Nothing to compare with') }}
                    @else
                        <span class="font-semibold {{ $tile['change'] > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($tile['change'] < 0 ? 'text-red-700 dark:text-red-400' : '') }}">{{ $tile['change'] > 0 ? '▲' : ($tile['change'] < 0 ? '▼' : '•') }} {{ abs($tile['change']) }}%</span>
                        {{ __('from :value', ['value' => $tile['money'] ? '₱' . number_format($tile['before'], 2) : number_format($tile['before'])]) }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>

    <p class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ __('Still pending, not counted as sales yet: :amount', ['amount' => '₱' . number_format($pending, 2)]) }}</p>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
        {{ __('Compared with :range.', ['range' => $comparedWith]) }}
        {{ $partial ? __('Its last day stops at the time it is now. Today\'s open orders are not sales yet, so sales can read low.') : '' }}
        {{ __('Sales are completed orders by the day they were placed; Collected is payments by the day they were verified.') }}
    </p>
</x-dashboard.card>
