@props([
    'title' => null,
    'description' => null,
])

{{-- A group of fields inside a panel's card. Without a title it is just a block of fields. --}}
<section {{ $attributes->class(['px-5 py-5 sm:px-6']) }}>
    @if ($title)
        <div class="mb-1">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h4>
            @if ($description)
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
            @endif
        </div>
    @endif

    <div class="divide-y divide-gray-100 dark:divide-gray-700/70">
        {{ $slot }}
    </div>
</section>
