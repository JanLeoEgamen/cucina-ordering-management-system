{{-- A status chip: the dot's colour comes from data-status (app.css). --}}
@props(['status'])

<span data-status="{{ $status }}" {{ $attributes->class('inline-flex flex-shrink-0 items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200') }}>{{ $slot }}</span>
