{{--
    Save (create / update) confirmation modal.

    The page script sets the title, message, record name and button label before
    showing it, then performs the AJAX save when the button is pressed.

    <x-edit-confirmation-modal
        title="Confirm Create"
        message="Are you sure you want to create this dish?"
        confirm-label="Create Dish"
    />
--}}
@props([
    'title' => __('Confirm Save'),
    'message' => __('Are you sure you want to save these changes?'),
    'confirmLabel' => __('Save'),
])

<x-ui.modal name="edit-confirmation-modal" size="md">
    <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
        <h3 id="save-modal-title" class="text-lg font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
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
        <p id="save-modal-message" class="text-gray-600 dark:text-gray-300">{{ $message }}</p>
        <p id="save-modal-subject" class="mt-2 hidden text-sm font-medium text-gray-900 dark:text-white"></p>
    </div>

    <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
        <button type="button"
                class="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-normal shadow-sm text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700"
                x-on:click="show = false">
            {{ __('Cancel') }}
        </button>
        <button type="button" id="save-modal-button"
                data-loading-label="{{ __('Saving...') }}"
                class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-normal text-white shadow-md transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800">
            {{ $confirmLabel }}
        </button>
    </div>
</x-ui.modal>
