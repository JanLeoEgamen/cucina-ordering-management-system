{{-- Dashboard widget `live-session`: the running session's stock, dish by dish, as of page load. --}}
<x-dashboard.card :title="__('Live session stock')" class="lg:col-span-2">
    <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __(':ordered of :prepared plates ordered', ['ordered' => $ordered, 'prepared' => $prepared]) }}</p>
        @if ($url)
            <a href="{{ $url }}" class="text-sm font-semibold text-gray-900 underline-offset-2 hover:underline dark:text-white">{{ __('Session details') }}</a>
        @endif
    </div>

    <ul class="mt-3 max-h-80 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
        @foreach ($dishes as $dish)
            <li class="py-2">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="min-w-0 truncate text-gray-800 dark:text-gray-100">{{ $dish['dish_name'] }}</span>
                    <span class="flex flex-shrink-0 items-center gap-2">
                        @if ($dish['state'] === 'sold_out')
                            <x-dashboard.pill status="rejected">{{ __('Sold out') }}</x-dashboard.pill>
                        @elseif ($dish['state'] === 'low')
                            <x-dashboard.pill status="pending">{{ __('Low') }}</x-dashboard.pill>
                        @elseif ($dish['state'] === 'off')
                            <x-dashboard.pill status="ended">{{ __('Turned off') }}</x-dashboard.pill>
                        @endif
                        <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ __(':left left', ['left' => $dish['left']]) }}</span>
                    </span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="img" aria-label="{{ __(':ordered of :prepared plates ordered', ['ordered' => $dish['ordered'], 'prepared' => $dish['prepared']]) }}">
                    <div class="h-full rounded-full bg-accent" style="width: {{ $dish['prepared'] > 0 ? round($dish['ordered'] / $dish['prepared'] * 100) : 0 }}%"></div>
                </div>
            </li>
        @endforeach
    </ul>
</x-dashboard.card>
