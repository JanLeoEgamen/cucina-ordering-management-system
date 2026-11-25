@props([
    'title',
    'description' => null,
])

{{--
    One card of a busy settings tab (the panel is declared with `cards`): a quiet heading that
    names ONE topic, then the white card with the <x-settings.section>s (or, with no section title,
    one plain block of fields) that belong to it.
--}}
<div {{ $attributes }}>
    <div class="mb-2 px-1">
        <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ $title }}</h4>
        @if ($description)
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>

    <div class="divide-y divide-gray-100 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:divide-gray-700 dark:border-gray-700 dark:bg-gray-800">
        {{ $slot }}
    </div>
</div>
