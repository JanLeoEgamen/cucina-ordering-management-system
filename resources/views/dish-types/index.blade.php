<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Dish Types') }}" action-label="{{ __('Add Dish Type') }}" action-id="create-dish-type" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400';
        $th = 'border-b border-gray-200 bg-gray-100 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:bg-gray-700/50 dark:text-gray-300';
        $cancelButton = 'rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $submitButton = 'rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            {{-- View switch: swaps the table for the card grid; wiring and card
                 markup live in resources/js/pages/dish-types.js. --}}
            <div class="flex items-center justify-end border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                <div class="inline-flex rounded-lg border border-gray-200 bg-gray-50 p-1 dark:border-gray-600 dark:bg-gray-700"
                     role="group" aria-label="{{ __('View mode') }}">
                    <button type="button"
                            data-view-mode="table"
                            aria-pressed="true"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-gray-400 dark:hover:text-gray-200 dark:focus:ring-indigo-800 aria-pressed:bg-white aria-pressed:text-indigo-600 aria-pressed:shadow-sm dark:aria-pressed:bg-gray-900 dark:aria-pressed:text-indigo-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z" />
                            <path stroke-linecap="round" d="M3 9.75h18M9.5 5v14" />
                        </svg>
                        {{ __('Table') }}
                    </button>
                    <button type="button"
                            data-view-mode="card"
                            aria-pressed="false"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-gray-500 transition hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-gray-400 dark:hover:text-gray-200 dark:focus:ring-indigo-800 aria-pressed:bg-white aria-pressed:text-indigo-600 aria-pressed:shadow-sm dark:aria-pressed:bg-gray-900 dark:aria-pressed:text-indigo-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4h6.5v6.5H4V4Zm9.5 0H20v6.5h-6.5V4ZM4 13.5h6.5V20H4v-6.5Zm9.5 0H20V20h-6.5v-6.5Z" />
                        </svg>
                        {{ __('Card') }}
                    </button>
                </div>
            </div>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/dish-types.js. --}}
            <table id="dish-types-table"
                   data-endpoint="{{ route('dish-types.index') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th scope="col" class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Type Name') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Description') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Status') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by resources/js/pages/dish-types.js --}}
            <div id="dish-types-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No data available yet') }}"></div>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-dish-type-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Dish Type') }}</h3>
            <button type="button"
                    class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="create-dish-type-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            {{--
                The `data-*` attributes carry the page wiring to
                resources/js/pages/dish-types.js: which drawer the form lives in,
                the field used as the confirmation subject and the copy.
            --}}
            <form id="create-dish-type-form" method="POST" action="{{ route('dish-types.store') }}"
                  data-drawer="create-dish-type-drawer"
                  data-subject-field="type_name"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this dish type?') }}"
                  data-confirm-label="{{ __('Create Dish Type') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-type-name" class="{{ $label }}">{{ __('Type Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="type_name" id="create-type-name" required
                               class="{{ $input }}" placeholder="{{ __('Enter dish type name') }}">
                    </div>
                </div>

                <div>
                    <label for="create-type-description" class="{{ $label }}">{{ __('Description') }}</label>
                    <div class="mt-1">
                        <textarea name="description" id="create-type-description" rows="3" class="{{ $input }}"
                                  placeholder="{{ __('Enter description') }}"></textarea>
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="create-type-is_active" :checked="true" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-dish-type-submit" class="{{ $submitButton }}">{{ __('Create Dish Type') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-dish-type-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Dish Type') }}</h3>
            <button type="button"
                    class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="edit-dish-type-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <form id="edit-dish-type-form" method="POST" action=""
                  data-drawer="edit-dish-type-drawer"
                  data-load-error="{{ __('Unable to load this dish type.') }}"
                  data-subject-field="type_name"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this dish type?') }}"
                  data-confirm-label="{{ __('Update Dish Type') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-type-name" class="{{ $label }}">{{ __('Type Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="type_name" id="edit-type-name" required
                               class="{{ $input }}" placeholder="{{ __('Enter dish type name') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-type-description" class="{{ $label }}">{{ __('Description') }}</label>
                    <div class="mt-1">
                        <textarea name="description" id="edit-type-description" rows="3" class="{{ $input }}"
                                  placeholder="{{ __('Enter description') }}"></textarea>
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="edit-type-is_active" :checked="true" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-dish-type-submit" class="{{ $submitButton }}">{{ __('Update Dish Type') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- View drawer: read only. resources/js/pages/dish-types.js fills it from the
         same endpoint the edit drawer loads. --}}
    <x-ui.drawer name="view-type-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('Dish Type Details') }}
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('View only') }}</span>
            </h3>
            <button type="button"
                    class="rounded-lg p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="view-type-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <dl class="space-y-3.5">
                <div>
                    <dt class="{{ $label }}">{{ __('Type Name') }}</dt>
                    <dd id="view-type-name" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Availability') }}</dt>
                    <dd id="view-type-status" class="mt-1 text-sm"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Description') }}</dt>
                    <dd id="view-type-description" class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300"></dd>
                </div>
            </dl>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this dish type?') }}"
        confirm-label="{{ __('Delete') }}"
    />

    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this dish type?') }}"
        confirm-label="{{ __('Create Dish Type') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/dish-types.js; see vite.config.js. --}}
        @vite('resources/js/pages/dish-types.js')
    @endpush
</x-app-layout>
