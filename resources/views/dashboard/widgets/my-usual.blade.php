{{-- Dashboard widget `my-usual`: the same dishes and quantities ordered more than once. --}}
<x-dashboard.card :title="__('Your usual order')">
    <ul class="mt-3 space-y-1">
        @foreach ($lines as $line)
            <li class="text-sm text-gray-800 dark:text-gray-100"><span class="font-semibold tabular-nums">{{ $line['quantity'] }} ×</span> {{ $line['dish_name'] }}</li>
        @endforeach
    </ul>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Ordered this way :count times.', ['count' => $times]) }}</p>

    @if ($menuOpen)
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
            {{ $short ? __('Not enough on the menu right now: :dishes.', ['dishes' => implode(', ', $short)]) : __('All of it is on the menu right now.') }}
        </p>
        <a href="{{ route('menu.index') }}" class="mt-3 inline-flex items-center rounded-full bg-accent px-4 py-2 text-sm font-semibold text-accent-on transition hover:bg-accent-hover">{{ __('Open the menu') }}</a>
    @endif
</x-dashboard.card>
