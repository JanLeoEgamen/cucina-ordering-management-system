{{-- Dashboard widget `insights`: sentences from plain rules (InsightWidgets). Each was built only
     for someone who may see its data, and has a link only to a page they may open. --}}
<x-dashboard.card :title="__('Worth a look')">
    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($insights as $insight)
            <li class="flex items-start gap-3 py-2.5 text-sm">
                <span data-status="{{ $insight['status'] }}" class="mt-0.5 flex-shrink-0" aria-hidden="true"></span>
                <span class="min-w-0 flex-1 text-gray-800 dark:text-gray-100">{{ $insight['text'] }}</span>
                @if ($insight['url'])
                    <a href="{{ $insight['url'] }}" class="flex-shrink-0 font-semibold text-gray-900 hover:underline dark:text-white">{{ __('Open') }}</a>
                @endif
            </li>
        @endforeach
    </ul>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Simple rules on your recent orders and sessions, to check against what you know.') }}</p>
</x-dashboard.card>
