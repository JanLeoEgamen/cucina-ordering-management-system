{{--
    Page header with an optional primary action button.

    The action is wired by the page script through its id (see the `action-id`
    prop), e.g.:

        <x-page-header title="Dishes" action-label="Add Dish" action-id="create-dish" />

    A `permission` prop is deliberately not implemented yet.
--}}
@props([
    'title',
    'actionLabel' => null,
    'actionId' => null,
])

<div class="flex flex-col items-start gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>

    @if ($actionLabel && $actionId)
        <button type="button" id="{{ $actionId }}"
                class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 sm:w-auto dark:focus:ring-indigo-800">
            <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            {{ $actionLabel }}
        </button>
    @endif
</div>
