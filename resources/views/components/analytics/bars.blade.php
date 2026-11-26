{{-- A ranked list with one bar each: the value is printed, the bar only shows how they compare.
     $rows: [{label, value (already formatted), width 0-100, note?}] --}}
@props(['rows'])

<ul class="mt-3 space-y-3">
    @foreach ($rows as $row)
        <li>
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate text-gray-800 dark:text-gray-100">{{ $row['label'] }}</span>
                <span class="flex-shrink-0 font-semibold tabular-nums text-gray-900 dark:text-white">{{ $row['value'] }}</span>
            </div>
            <div class="mt-1 h-2 overflow-hidden rounded-r bg-gray-100 dark:bg-gray-700" aria-hidden="true">
                <div class="h-full rounded-r bg-accent" style="width: {{ (int) $row['width'] }}%"></div>
            </div>
            @if (! empty($row['note']))
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</p>
            @endif
        </li>
    @endforeach
</ul>
