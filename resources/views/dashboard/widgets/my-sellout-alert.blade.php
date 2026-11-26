{{-- Dashboard widget `my-sellout-alert`: favourites that usually run out. --}}
<x-dashboard.card :title="__('Order these early')">
    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($dishes as $dish)
            <li class="py-2 text-sm">
                <span class="flex items-center justify-between gap-3">
                    <span class="min-w-0 truncate font-semibold text-gray-900 dark:text-white">{{ $dish['dish_name'] }}</span>
                    @if ($dish['left'] !== null)
                        <x-dashboard.pill :status="$dish['left'] > 0 ? 'live' : 'rejected'">{{ $dish['left'] > 0 ? __(':left left', ['left' => $dish['left']]) : __('Sold out') }}</x-dashboard.pill>
                    @endif
                </span>
                <span class="block text-gray-600 dark:text-gray-300">{{ __('Sold out :sold of the last :offered times, usually about :after after opening.', ['sold' => $dish['soldOut'], 'offered' => $dish['offered'], 'after' => $dish['after']]) }}</span>
            </li>
        @endforeach
    </ul>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Your favourites, over the last :days days. The time is an estimate.', ['days' => $days]) }}</p>

    @if ($menuOpen)
        <a href="{{ route('menu.index') }}" class="mt-2 inline-block text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ __('Open the menu') }}</a>
    @endif
</x-dashboard.card>
