{{-- Dashboard widget `my-favorites`: the dishes the person orders most, by plates. --}}
<x-dashboard.card :title="__('Your favourites')">
    <ol class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($dishes as $dish)
            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                <span class="min-w-0">
                    <span class="block truncate text-gray-800 dark:text-gray-100">{{ $dish['dish_name'] }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count plate|:count plates', $dish['plates']) }}</span>
                </span>
                @if ($dish['left'] !== null)
                    <x-dashboard.pill :status="$dish['left'] > 0 ? 'live' : 'rejected'">{{ $dish['left'] > 0 ? __(':left left', ['left' => $dish['left']]) : __('Sold out') }}</x-dashboard.pill>
                @endif
            </li>
        @endforeach
    </ol>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('From your orders of the last 12 months.') }}</p>

    @if ($menuOpen)
        <a href="{{ route('menu.index') }}" class="mt-2 inline-block text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ __('Open the menu') }}</a>
    @endif
</x-dashboard.card>
