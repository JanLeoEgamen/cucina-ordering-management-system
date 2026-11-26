{{-- Analytics widget `review-time`: how long people wait for a decision. No names. --}}
<x-dashboard.card :csv="$csv ?? null" :title="__('Review times')" class="lg:col-span-3">
    <div class="mt-3 grid gap-6 md:grid-cols-2">
        @foreach ($groups as $group)
            <div class="min-w-0">
                <h5 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['title'] }}</h5>
                @if ($group['decisions'] === 0)
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Nothing was decided in this range.') }}</p>
                @else
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ trans_choice(':count decision|:count decisions', $group['decisions']) }} · {{ __('average :minutes min, longest :longest min', ['minutes' => $group['average'], 'longest' => $group['longest']]) }}
                    </p>
                    <x-analytics.bars :rows="array_map(fn ($band) => [
                        'label' => $band['label'],
                        'value' => $band['decisions'] . ' · ' . $band['share'] . '%',
                        'width' => $band['width'],
                    ], $group['bands'])" />
                @endif
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('From the order to the decision, by the day decided; cancelled orders are left out. A proof sent again is timed from the first one, so it reads long, and a payment verification that was undone is not counted.') }}</p>
</x-dashboard.card>
