{{--
    Page header with an optional primary action ("Add ...").

    The action is wired by the page script through its id (see the `action-id`
    prop), e.g.:

        <x-page-header title="Dishes" action-label="Add Dish" action-id="create-dish" />

    The action is a floating button in the LOWER RIGHT corner (like the menu's
    cart), so it is in reach wherever the page is scrolled. It is a round plus on a
    phone and a pill with its label from `sm` up. `data-fab` is what app.css reads
    to leave room under the page, so the button never sits on the last row or the
    pager.

    A `permission` prop is deliberately not implemented yet.
--}}
@props([
    'title',
    'actionLabel' => null,
    'actionId' => null,
])

<div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
    <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $title }}</h2>

    {{-- Optional secondary actions (e.g. the masterlist's "Manage Types"); they are
         not the page's primary action, so they must not carry `data-fab`. --}}
    @if (! $slot->isEmpty())
        <div class="flex items-center gap-2 sm:ml-auto sm:mr-3">{{ $slot }}</div>
    @endif

    @if ($actionLabel && $actionId)
        <button type="button" id="{{ $actionId }}" data-fab
                aria-label="{{ $actionLabel }}" title="{{ $actionLabel }}"
                class="fixed bottom-[calc(1.25rem+env(safe-area-inset-bottom))] right-5 z-40 inline-flex h-14 w-14 animate-pop items-center justify-center gap-2 rounded-full sm:rounded-xl border border-gray-900 bg-indigo-600 text-sm font-normal text-white shadow-card-lift sm:shadow-md transition duration-200 hover:-translate-y-0.5 hover:bg-indigo-700 sm:animate-none focus:outline-none focus:ring-4 focus:ring-gray-300 sm:static sm:z-auto sm:h-auto sm:w-auto sm:px-6 sm:py-3.5 dark:border-mist dark:focus:ring-gray-600">
            <svg class="h-6 w-6 sm:h-5 sm:w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M12 4v16m8-8H4" />
            </svg>
            <span class="hidden sm:inline">{{ $actionLabel }}</span>
        </button>
    @endif
</div>
