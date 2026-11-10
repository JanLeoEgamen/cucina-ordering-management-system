<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Roles') }}" action-label="{{ __('Add Role') }}" action-id="create-role" />
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
            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/roles.js. --}}
            <table id="roles-table"
                   data-endpoint="{{ route('roles.index') }}"
                   data-protected-label="{{ __('Protected') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th scope="col" class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Role') }}</th>
                        <th scope="col" class="{{ $th }} text-center">{{ __('Permissions') }}</th>
                        <th scope="col" class="{{ $th }} text-center">{{ __('Users') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-role-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Role') }}</h3>
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
            <div id="create-role-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            {{-- The `data-*` attributes carry the page wiring to
                 resources/js/pages/roles.js; the copy stays here so __() applies. --}}
            <form id="create-role-form" method="POST" action="{{ route('roles.store') }}"
                  data-drawer="create-role-drawer"
                  data-subject-field="name"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this role?') }}"
                  data-confirm-label="{{ __('Create Role') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-role-name" class="{{ $label }}">{{ __('Role Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="name" id="create-role-name" required
                               class="{{ $input }}" placeholder="{{ __('Enter role name') }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Permissions') }}</span>
                    <div class="mt-1">
                        <x-ui.checkbox-picker id="create-role-permissions" name="permissions[]" :items="$permissionOptions"
                                              placeholder="{{ __('Select permissions') }}"
                                              search-placeholder="{{ __('Search permissions') }}"
                                              empty-text="{{ __('No permissions match that search.') }}" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-role-submit" class="{{ $submitButton }}">{{ __('Create Role') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-role-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Role') }}</h3>
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
            <div id="edit-role-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <form id="edit-role-form" method="POST" action=""
                  data-drawer="edit-role-drawer"
                  data-load-error="{{ __('Unable to load this role.') }}"
                  data-subject-field="name"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this role?') }}"
                  data-confirm-label="{{ __('Update Role') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-role-name" class="{{ $label }}">{{ __('Role Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="name" id="edit-role-name" required
                               class="{{ $input }}" placeholder="{{ __('Enter role name') }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Permissions') }}</span>
                    <div class="mt-1">
                        <x-ui.checkbox-picker id="edit-role-permissions" name="permissions[]" :items="$permissionOptions"
                                              placeholder="{{ __('Select permissions') }}"
                                              search-placeholder="{{ __('Search permissions') }}"
                                              empty-text="{{ __('No permissions match that search.') }}" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-role-submit" class="{{ $submitButton }}">{{ __('Update Role') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- View drawer: read only. resources/js/pages/roles.js fills it from the same
         endpoint the edit drawer loads. --}}
    <x-ui.drawer name="view-role-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('Role Details') }}
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
            <div id="view-role-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <dl class="space-y-3.5">
                <div>
                    <dt class="{{ $label }}">{{ __('Role Name') }}</dt>
                    <dd class="mt-1 flex items-center gap-2 text-sm text-gray-900 dark:text-white">
                        <span id="view-role-name"></span>
                        {{-- Shown by resources/js/pages/roles.js for a protected role. --}}
                        <span id="view-role-protected"
                              class="hidden rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ __('Protected') }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Users') }}</dt>
                    <dd id="view-role-users" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Permissions') }}</dt>
                    <dd id="view-role-permissions" class="mt-1 text-sm text-gray-700 dark:text-gray-300"></dd>
                </div>
            </dl>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this role?') }}"
        confirm-label="{{ __('Delete') }}"
    />

    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this role?') }}"
        confirm-label="{{ __('Create Role') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/roles.js; see vite.config.js. --}}
        @vite('resources/js/pages/roles.js')
    @endpush
</x-app-layout>
