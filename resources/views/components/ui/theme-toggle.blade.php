@props(['labeled' => false])

{{--
    The dark / light switch (resources/js/ui/theme.js). One button, two looks:

        <x-ui.theme-toggle />           round icon (phone top bar, login page)
        <x-ui.theme-toggle labeled />   a sidebar row with its label

    The icon shows where a click goes: the moon in light mode, the sun in dark
    mode. Both are in the markup and `dark:` picks one, so it is right before
    any script runs.
--}}
@php
    $moon = 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z';
    $sun = 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z';
@endphp

@if ($labeled)
    <button type="button" data-theme-toggle aria-pressed="false" title="{{ __('Switch between dark and light mode') }}"
            {{ $attributes->class(['sidebar-link flex w-full items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-left text-sm font-medium text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white']) }}>
        <svg class="h-5 w-5 flex-shrink-0 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $moon }}" /></svg>
        <svg class="hidden h-5 w-5 flex-shrink-0 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $sun }}" /></svg>
        <span class="sidebar-label">
            <span class="dark:hidden">{{ __('Dark mode') }}</span>
            <span class="hidden dark:inline">{{ __('Light mode') }}</span>
        </span>
    </button>
@else
    <button type="button" data-theme-toggle aria-pressed="false" title="{{ __('Switch between dark and light mode') }}"
            {{ $attributes->class(['inline-flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white dark:focus:ring-gray-700']) }}>
        <span class="sr-only">{{ __('Switch between dark and light mode') }}</span>
        <svg class="h-5 w-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $moon }}" /></svg>
        <svg class="hidden h-5 w-5 dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $sun }}" /></svg>
    </button>
@endif
