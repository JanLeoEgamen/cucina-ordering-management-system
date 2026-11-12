{{--
    Delete confirmation modal.

    Inner element ids are fixed ("delete-modal-*") because a page only ever has
    one of these; the wording and HTTP method come from the props below and the
    page script only fills in the record specific action before showing it.
    Deletes are soft deletes (the record stays in the table, hidden).

    <x-delete-confirmation-modal
        title="Confirm Delete"
        message="Are you sure you want to delete this dish?"
        confirm-label="Delete"
    />
--}}
@props([
    'title' => __('Confirm Delete'),
    'message' => __('Are you sure you want to delete this record?'),
    'confirmLabel' => __('Delete'),
    'method' => 'DELETE',
])

<x-ui.modal name="delete-confirmation-modal" size="md">
    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <h3 id="delete-modal-title" class="text-lg font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
        <button type="button"
                class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                x-on:click="show = false">
            <span class="sr-only">{{ __('Close') }}</span>
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div class="p-6">
        <p id="delete-modal-message" class="text-gray-600 dark:text-gray-300">{{ $message }}</p>
        <div id="delete-modal-errors" class="mt-3 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>
    </div>

    <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
        <button type="button"
                class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-normal shadow-sm text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700"
                x-on:click="show = false">
            {{ __('Cancel') }}
        </button>
        <form id="delete-modal-form" method="POST" action="">
            @csrf
            <input type="hidden" name="_method" value="{{ $method }}" id="delete-modal-method">
            <button type="submit" id="delete-modal-button"
                    data-loading-label="{{ __('Deleting...') }}"
                    class="rounded-xl bg-red-500 px-5 py-2.5 text-sm font-normal text-white shadow-md transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-300 dark:focus:ring-red-800">
                {{ $confirmLabel }}
            </button>
        </form>
    </div>
</x-ui.modal>
