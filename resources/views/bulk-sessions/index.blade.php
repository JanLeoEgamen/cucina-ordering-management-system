<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Bulk Sessions') }}" action-label="{{ __('Add Bulk Session') }}" action-id="create-bulk-session" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm leading-5 text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600';
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $cancelButton = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $submitButton = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600';
        // pr-10 keeps the @tailwindcss/forms chevron from sitting on the value.
        $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
        $closeButton = 'rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white';
        $errorBox = 'mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort and filter (resources/js/ui/table-toolbar.js). A bulk session is scheduled
                 before its window opens, live inside it, and ended once its end time went past or its
                 creator closed it by hand. The column numbers below are the table's columns. --}}
            <x-ui.table-toolbar :export-title="__('Bulk Sessions')" :views="['table']" :sorts="[
                [__('Date: newest first'), 1, 'desc'],
                [__('Date: oldest first'), 1, 'asc'],
                [__('Most orders'), 6, 'desc'],
                [__('Most dishes'), 5, 'desc'],
                [__('Highest minimum'), 4, 'desc'],
                [__('Created by: A to Z'), 7, 'asc'],
            ]">
                <x-ui.filter-field :label="__('Status')">
                    <select id="session-status-filter" aria-label="{{ __('Status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="live">{{ __('Live') }}</option>
                        <option value="ended">{{ __('Ended') }}</option>
                    </select>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/bulk-sessions.js. --}}
            <table id="bulk-sessions-table"
                   data-endpoint="{{ route('bulk-sessions.index') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" data-no-export class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Date') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Time') }}</th>
                        <th scope="col" class="{{ $th }} hidden md:table-cell">{{ __('Title') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Min. Plates') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Dishes') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Orders') }}</th>
                        <th scope="col" class="{{ $th }} hidden md:table-cell">{{ __('Created By') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Status') }}</th>
                        <th scope="col" data-no-export class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-bulk-session-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Bulk Session') }}</h3>
            <button type="button" class="{{ $closeButton }}" x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="create-bulk-session-errors" class="{{ $errorBox }}"></div>

            {{-- The `data-*` attributes carry the page wiring to resources/js/pages/bulk-sessions.js:
                 which drawer the form lives in, the field used as the confirmation subject and the copy. --}}
            <form id="create-bulk-session-form" method="POST" action="{{ route('bulk-sessions.store') }}"
                  data-drawer="create-bulk-session-drawer"
                  data-subject-field="order_date"
                  data-default-start="{{ $sessionWindow['start_time'] }}"
                  data-default-end="{{ $sessionWindow['end_time'] }}"
                  data-default-min="10"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this bulk session?') }}"
                  data-confirm-label="{{ __('Create Bulk Session') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-title" class="{{ $label }}">{{ __('Title') }} <span class="font-normal text-gray-400">({{ __('optional') }})</span></label>
                    <div class="mt-1">
                        <input type="text" name="title" id="create-title" maxlength="120" placeholder="{{ __('e.g. Christmas party trays') }}" class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <label for="create-order-date" class="{{ $label }}">{{ __('Session Date') }}</label>
                    <div class="mt-1">
                        <input type="date" name="order_date" id="create-order-date" required class="{{ $input }}">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="create-start-time" class="{{ $label }}">{{ __('Start Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="start_time" id="create-start-time" required class="{{ $input }}">
                        </div>
                    </div>

                    <div>
                        <label for="create-end-time" class="{{ $label }}">{{ __('End Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="end_time" id="create-end-time" required class="{{ $input }}">
                        </div>
                    </div>
                </div>

                <div>
                    <label for="create-min-quantity" class="{{ $label }}">{{ __('Minimum plates per order') }}</label>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('All dishes together: an order smaller than this is refused.') }}</p>
                    <div class="mt-1">
                        <input type="number" name="min_quantity" id="create-min-quantity" min="1" step="1" required class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Dishes') }}</span>
                    <p class="mb-2 mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Tick the dishes this bulk session offers. There is no stock limit.') }}
                    </p>
                    <x-ui.dish-stock-list prefix="create" :dishes="$dishes" :stock="false" />
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-bulk-session-submit" class="{{ $submitButton }}">{{ __('Create Bulk Session') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-bulk-session-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Bulk Session') }}</h3>
            <button type="button" class="{{ $closeButton }}" x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="edit-bulk-session-errors" class="{{ $errorBox }}"></div>

            <form id="edit-bulk-session-form" method="POST" action=""
                  data-drawer="edit-bulk-session-drawer"
                  data-load-error="{{ __('Unable to load this bulk session.') }}"
                  data-subject-field="order_date"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this bulk session?') }}"
                  data-confirm-label="{{ __('Update Bulk Session') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-title" class="{{ $label }}">{{ __('Title') }} <span class="font-normal text-gray-400">({{ __('optional') }})</span></label>
                    <div class="mt-1">
                        <input type="text" name="title" id="edit-title" maxlength="120" class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <label for="edit-order-date" class="{{ $label }}">{{ __('Session Date') }}</label>
                    <div class="mt-1">
                        <input type="date" name="order_date" id="edit-order-date" required class="{{ $input }}">
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="edit-start-time" class="{{ $label }}">{{ __('Start Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="start_time" id="edit-start-time" required class="{{ $input }}">
                        </div>
                    </div>

                    <div>
                        <label for="edit-end-time" class="{{ $label }}">{{ __('End Time') }}</label>
                        <div class="mt-1">
                            <input type="time" name="end_time" id="edit-end-time" required class="{{ $input }}">
                        </div>
                    </div>
                </div>

                <div>
                    <label for="edit-min-quantity" class="{{ $label }}">{{ __('Minimum plates per order') }}</label>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Applies to orders placed from now on; orders already placed are kept.') }}</p>
                    <div class="mt-1">
                        <input type="number" name="min_quantity" id="edit-min-quantity" min="1" step="1" required class="{{ $input }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Dishes') }}</span>
                    <p class="mb-2 mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('A dish you untick leaves the bulk session. Orders already placed keep it.') }}
                    </p>
                    <x-ui.dish-stock-list prefix="edit" :dishes="$dishes" :stock="false" />
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-bulk-session-submit" class="{{ $submitButton }}">{{ __('Update Bulk Session') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this bulk session? This will soft-delete it and take its dishes off the bulk menu.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    {{-- One confirmation modal serves create, update and end: the page script
         sets its copy and the form to send before opening it. --}}
    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this bulk session?') }}"
        confirm-label="{{ __('Create Bulk Session') }}"
    />

    {{-- The end action has no form of its own: the page script points this one at
         the session before opening the confirmation modal. --}}
    <form id="end-session-form" method="POST" action=""
          data-confirm-title="{{ __('Confirm End') }}"
          data-confirm-message="{{ __('Are you sure you want to end this bulk session? Orders already placed are kept, and customers can no longer order from it.') }}"
          data-confirm-label="{{ __('End Session') }}"
          class="hidden">
        @csrf
    </form>

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/bulk-sessions.js; see vite.config.js. --}}
        @vite('resources/js/pages/bulk-sessions.js')
    @endpush
</x-app-layout>
