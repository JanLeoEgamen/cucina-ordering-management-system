{{-- A bar chart, drawn by resources/js/pages/analytics.js from data-chart:
     {labels, stacked, datasets: [{label, color: 'accent' | 1..5 | 'other', data}]} (amounts in pesos).
     The slot is the table with the same numbers: always give one, it is what is there without
     colour, without a pointer and without JavaScript. --}}
@props(['chart', 'label', 'open' => false])

<div class="relative mt-4 h-64 w-full" data-chart="{{ json_encode($chart) }}">
    <canvas role="img" aria-label="{{ $label }}"></canvas>
</div>

<details class="group mt-3" @if ($open) open @endif>
    <summary class="cursor-pointer text-sm font-semibold text-gray-700 hover:underline dark:text-gray-200">{{ __('Show as table') }}</summary>
    <div class="mt-2 max-h-80 overflow-auto">
        {{ $slot }}
    </div>
</details>
