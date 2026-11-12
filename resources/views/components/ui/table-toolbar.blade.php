@props([
    // The view tabs on the left: any of 'table', 'card', 'calendar'. One (or none) hides them.
    'views' => ['table', 'card'],
    // The Sort dropdown: [[label, column index, 'asc'|'desc'], ...]. Empty hides it.
    'sorts' => [],
    // A heading before the tabs (for a table that sits inside a page, not a page of its own).
    'title' => null,
])

{{--
    The toolbar above every table: the view tabs on the left, and one rounded bar on
    the right with search, a Sort dropdown and a Filter dropdown (the same bar the
    menu has). The slot is the Filter dropdown's fields; leave it empty for a table
    with nothing to filter and the Filter button is not drawn.

        <x-ui.table-toolbar :views="['table', 'card']" :sorts="[[__('Name'), 1, 'asc']]">
            <x-ui.filter-field :label="__('Status')"> <select id="x-status">...</select> </x-ui.filter-field>
        </x-ui.table-toolbar>

    The bar drives the page's DataTable through resources/js/ui/table-toolbar.js
    (initTableToolbar(table)): the table's own search box is switched off, the field
    here searches instead. The controls in the slot keep their own ids and listeners:
    the page script reads them as before, this only moves them into the dropdown.
--}}
@php
    $icons = [
        'table' => ['label' => __('Table'), 'path' => 'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1ZM3 9.75h18M9.5 5v14'],
        'card' => ['label' => __('Card'), 'path' => 'M4 4h6.5v6.5H4V4Zm9.5 0H20v6.5h-6.5V4ZM4 13.5h6.5V20H4v-6.5Zm9.5 0H20V20h-6.5v-6.5Z'],
        'calendar' => ['label' => __('Calendar'), 'path' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z'],
    ];

    $hasFilters = ! $slot->isEmpty();
    $hasSorts = count($sorts) > 0;

    // Compact on a phone (the bar is one row), roomier from `sm`.
    $barButton = 'inline-flex h-10 items-center justify-center gap-1.5 rounded-full px-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 aria-expanded:bg-gray-100 sm:h-11 sm:gap-2 sm:px-4 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-gray-700 dark:aria-expanded:bg-gray-700';
    // A dropdown. On a phone it spans the WHOLE bar (the bar is its positioning parent), so it
    // can never run off the screen; from `sm` it hangs under its own button instead.
    $panel = 'absolute inset-x-0 top-full z-30 mt-2 hidden animate-pop rounded-2xl border border-gray-200 bg-white p-3 shadow-card-lift sm:inset-x-auto sm:right-0 sm:w-80 dark:border-gray-700 dark:bg-gray-800';
    // The cell around a bar button: a hairline before it, and (from `sm`) the anchor of its panel.
    $cell = 'border-l border-gray-200 pl-0.5 sm:relative sm:pl-1 dark:border-gray-700';
    $tab = 'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-gray-400 dark:hover:text-gray-200 dark:focus:ring-indigo-800 aria-pressed:bg-white aria-pressed:text-indigo-600 aria-pressed:shadow-sm dark:aria-pressed:bg-gray-900 dark:aria-pressed:text-indigo-400';
@endphp

<div data-table-toolbar class="relative z-20 flex flex-col gap-3 border-b border-gray-100 px-4 py-3 lg:flex-row lg:items-center lg:justify-between dark:border-gray-700">
    {{-- Left: the view tabs --}}
    <div class="flex flex-wrap items-center gap-3">
        @if ($title)
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
        @endif

        @if (count($views) > 1)
            <div class="inline-flex rounded-full border border-gray-200 bg-gray-50 p-1 dark:border-gray-600 dark:bg-gray-700" role="group" aria-label="{{ __('View mode') }}">
                @foreach ($views as $view)
                    <button type="button" data-view-mode="{{ $view }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" class="{{ $tab }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$view]['path'] }}" /></svg>
                        {{ $icons[$view]['label'] }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Right: one bar - search | Sort | Filter --}}
    <div class="relative flex min-w-0 items-center rounded-full border border-gray-200 bg-white p-1 shadow-sm sm:p-1.5 lg:w-[30rem] dark:border-gray-700 dark:bg-gray-800">
        <label class="relative flex min-w-0 flex-1 items-center">
            <span class="sr-only">{{ __('Search') }}</span>
            <svg class="pointer-events-none absolute left-3.5 h-5 w-5 text-gray-400 sm:left-4 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
            </svg>
            <input type="search" data-table-search autocomplete="off" placeholder="{{ __('Search') }}…"
                   class="h-10 w-full min-w-0 rounded-full border-0 bg-transparent pl-11 pr-2 text-base text-gray-900 placeholder:text-gray-400 focus:outline-none focus:ring-0 sm:h-11 sm:pl-12 sm:pr-4 dark:text-white dark:placeholder:text-gray-400">
        </label>

        @if ($hasSorts || $hasFilters)
            <div class="flex flex-shrink-0 items-center">
                @if ($hasSorts)
                    <div class="{{ $cell }}">
                        <button type="button" data-table-sort-toggle aria-haspopup="true" aria-expanded="false" aria-controls="table-sort-panel" class="{{ $barButton }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7.5 7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5" /></svg>
                            <span data-table-sort-label class="hidden max-w-[6.5rem] truncate min-[400px]:inline sm:max-w-none">{{ __('Sort') }}</span>
                        </button>

                        <div id="table-sort-panel" data-popover role="menu" aria-label="{{ __('Sort by') }}" class="{{ $panel }}">
                            <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Sort by') }}</p>

                            @foreach ($sorts as [$sortName, $sortColumn, $sortDirection])
                                <button type="button" role="menuitemradio" data-table-sort data-col="{{ $sortColumn }}" data-dir="{{ $sortDirection }}" aria-checked="false"
                                        class="group flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm text-gray-700 transition hover:bg-gray-50 aria-checked:bg-gray-100 aria-checked:font-semibold aria-checked:text-gray-900 dark:text-gray-200 dark:hover:bg-gray-700 dark:aria-checked:bg-gray-700 dark:aria-checked:text-white">
                                    {{ $sortName }}
                                    <svg class="hidden h-4 w-4 group-aria-checked:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 13 4 4L19 7" /></svg>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($hasFilters)
                    <div class="{{ $cell }}">
                        <button type="button" data-table-filter-toggle aria-haspopup="true" aria-expanded="false" aria-controls="table-filter-panel" class="{{ $barButton }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M6 12h12M10 18h4" /></svg>
                            {{ __('Filter') }}
                            <span data-table-filter-count class="hidden min-w-[1.25rem] rounded-full bg-indigo-600 px-1.5 py-0.5 text-center text-xs font-semibold tabular-nums text-white">0</span>
                        </button>

                        <div id="table-filter-panel" data-popover aria-label="{{ __('Filter') }}" class="{{ $panel }}">
                            <div class="space-y-3">
                                {{ $slot }}
                            </div>

                            <div class="mt-3 flex items-center justify-between gap-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Narrows the list instantly.') }}</p>
                                <button type="button" data-table-clear
                                        class="rounded-full border border-gray-200 px-3.5 py-1.5 text-sm font-medium text-indigo-600 transition hover:bg-indigo-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:text-indigo-400 dark:hover:bg-indigo-900/30">
                                    {{ __('Clear filters') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
