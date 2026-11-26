{{-- Dashboard widget `my-new-dishes`: on the live menu, not ordered by this person lately. --}}
<x-dashboard.card :title="__('New to you')">
    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($dishes as $dish)
            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                <span class="min-w-0 truncate text-gray-800 dark:text-gray-100">{{ $dish['dish_name'] }}</span>
                <x-dashboard.pill status="live">{{ __(':left left', ['left' => $dish['left']]) }}</x-dashboard.pill>
            </li>
        @endforeach
    </ul>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        {{ __('On the menu now, and not in your orders of the last 12 months.') }}
        {{ $more > 0 ? __('And :count more.', ['count' => $more]) : '' }}
    </p>
    <a href="{{ route('menu.index') }}" class="mt-2 inline-block text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ __('Open the menu') }}</a>
</x-dashboard.card>
