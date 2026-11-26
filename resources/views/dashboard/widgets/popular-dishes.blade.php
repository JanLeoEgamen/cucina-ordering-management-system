{{-- Dashboard widget `popular-dishes`: dish names only (no counts, nobody's name). --}}
<x-dashboard.card :title="__('Popular this week')">
    <ol class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($dishes as $dish)
            <li class="flex items-center justify-between gap-3 py-2 text-sm">
                <span class="min-w-0 truncate text-gray-800 dark:text-gray-100"><span class="mr-2 tabular-nums text-gray-400">{{ $loop->iteration }}</span>{{ $dish['dish_name'] }}</span>
                @if ($dish['left'] !== null)
                    <x-dashboard.pill :status="$dish['left'] > 0 ? 'live' : 'rejected'">{{ $dish['left'] > 0 ? __('On the menu') : __('Sold out') }}</x-dashboard.pill>
                @endif
            </li>
        @endforeach
    </ol>

    @if ($menuOpen)
        <a href="{{ route('menu.index') }}" class="mt-2 inline-block text-sm font-semibold text-gray-900 hover:underline dark:text-white">{{ __('Open the menu') }}</a>
    @endif
</x-dashboard.card>
