{{-- Dashboard widget `my-discounts`: what can be picked at checkout today. --}}
<x-dashboard.card :title="__('Discounts you can pick')">
    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($discounts as $discount)
            <li class="py-2 text-sm">
                <span class="flex items-center justify-between gap-3">
                    <span class="min-w-0 truncate text-gray-800 dark:text-gray-100">{{ $discount['name'] }}</span>
                    <span class="flex-shrink-0 font-semibold tabular-nums text-gray-900 dark:text-white">{{ rtrim(rtrim(number_format($discount['percent'], 2), '0'), '.') }}%</span>
                </span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">
                    {{ $discount['needsId'] ? __('An ID is checked by the staff.') : __('No ID needed.') }}
                    @if ($discount['until'])
                        {{ $discount['endsSoon'] ? __('Ends soon: :date.', ['date' => $discount['until']]) : __('Until :date.', ['date' => $discount['until']]) }}
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('One per order, picked at checkout. For :kinds.', ['kinds' => $kinds]) }}</p>
</x-dashboard.card>
