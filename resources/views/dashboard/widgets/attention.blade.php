{{-- Dashboard widget `attention` (config/dashboard.php, `view dashboard-attention`): what is waiting
     for somebody. $lines holds only the lines this person may act on and that have something in them
     (CounterWidgets::attention()); a line has a url only for someone who may open that page. --}}
<x-dashboard.card :title="__('Needs attention')">
    @forelse ($lines as $line)
        @if ($line['url'])
            <a href="{{ $line['url'] }}" class="mt-3 flex items-center gap-3 rounded-xl border border-gray-100 p-3 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-700/40">
        @else
            <div class="mt-3 flex items-center gap-3 rounded-xl border border-gray-100 p-3 dark:border-gray-700">
        @endif
            <span class="min-w-[2.5rem] text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $line['count'] }}</span>
            <span class="min-w-0 text-sm text-gray-700 dark:text-gray-200">
                {{ $line['label'] }}
                @if ($line['detail'])
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $line['detail'] }}</span>
                @endif
            </span>
        @if ($line['url'])
            </a>
        @else
            </div>
        @endif
    @empty
        <p class="mt-3 text-lg font-bold text-gray-900 dark:text-white">{{ __('All caught up') }}</p>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing is waiting for you.') }}</p>
    @endforelse
</x-dashboard.card>
