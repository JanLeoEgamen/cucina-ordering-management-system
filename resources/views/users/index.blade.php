<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Users') }}" action-label="{{ __('Add User') }}" action-id="create-user" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm leading-5 text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600';
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
        $cancelButton = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $submitButton = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600';
        // pr-10 keeps the @tailwindcss/forms chevron from sitting on the value.
        $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort and the filter (resources/js/ui/table-toolbar.js). The filter narrows the feed
                 in the browser (no request); it is read by resources/js/pages/users.js. --}}
            <x-ui.table-toolbar :export-title="__('Users')" :views="[]" :sorts="[
                [__('Name: A to Z'), 1, 'asc'],
                [__('Name: Z to A'), 1, 'desc'],
                [__('Email: A to Z'), 2, 'asc'],
            ]">
                <x-ui.filter-field :label="__('Role')">
                    <select id="user-role-filter" aria-label="{{ __('Role') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All roles') }}</option>
                        @foreach ($roleOptions as $roleOption)
                            <option value="{{ $roleOption['name'] }}">{{ $roleOption['name'] }}</option>
                        @endforeach
                    </select>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/users.js. --}}
            <table id="users-table"
                   data-endpoint="{{ route('users.index') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" data-no-export class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Name') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Email') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Company') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Roles') }}</th>
                        <th scope="col" data-no-export class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-user-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create User') }}</h3>
            <button type="button"
                    class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="create-user-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            {{-- The `data-*` attributes carry the page wiring to
                 resources/js/pages/users.js; the copy stays here so __() applies. --}}
            <form id="create-user-form" method="POST" action="{{ route('users.store') }}"
                  data-drawer="create-user-drawer"
                  data-subject-field="first_name last_name"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this user?') }}"
                  data-confirm-label="{{ __('Create User') }}"
                  class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="create-user-first-name" class="{{ $label }}">{{ __('First Name') }}</label>
                        <div class="mt-1">
                            <input type="text" name="first_name" id="create-user-first-name" required autocomplete="off"
                                   class="{{ $input }}" placeholder="{{ __('Enter first name') }}">
                        </div>
                    </div>

                    <div>
                        <label for="create-user-last-name" class="{{ $label }}">{{ __('Last Name') }}</label>
                        <div class="mt-1">
                            <input type="text" name="last_name" id="create-user-last-name" required autocomplete="off"
                                   class="{{ $input }}" placeholder="{{ __('Enter last name') }}">
                        </div>
                    </div>
                </div>

                <div>
                    <label for="create-user-company" class="{{ $label }}">{{ __('Company') }}</label>
                    <div class="mt-1">
                        <input type="text" name="company" id="create-user-company" autocomplete="off"
                               class="{{ $input }}" placeholder="{{ __('Optional') }}">
                    </div>
                </div>

                <div>
                    <label for="create-user-email" class="{{ $label }}">{{ __('Email') }}</label>
                    <div class="mt-1">
                        <input type="email" name="email" id="create-user-email" required
                               class="{{ $input }}" placeholder="{{ __('name@example.com') }}">
                    </div>
                </div>

                <div>
                    <label for="create-user-password" class="{{ $label }}">{{ __('Password') }}</label>
                    <div class="mt-1">
                        <input type="password" name="password" id="create-user-password" required autocomplete="new-password"
                               class="{{ $input }}" placeholder="{{ __('At least 8 characters') }}">
                    </div>
                </div>

                <div>
                    <label for="create-user-password-confirmation" class="{{ $label }}">{{ __('Confirm Password') }}</label>
                    <div class="mt-1">
                        <input type="password" name="password_confirmation" id="create-user-password-confirmation" required autocomplete="new-password"
                               class="{{ $input }}" placeholder="{{ __('Repeat the password') }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Roles') }}</span>
                    <div class="mt-1">
                        <x-ui.checkbox-picker id="create-user-roles" name="roles[]" :items="$roleOptions"
                                              placeholder="{{ __('Select roles') }}"
                                              search-placeholder="{{ __('Search roles') }}"
                                              empty-text="{{ __('No roles match that search.') }}" />
                        <p class="{{ $hint }}">{{ __('A user without a role cannot open any gated page.') }}</p>
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-user-submit" class="{{ $submitButton }}">{{ __('Create User') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-user-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit User') }}</h3>
            <button type="button"
                    class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="edit-user-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <form id="edit-user-form" method="POST" action=""
                  data-drawer="edit-user-drawer"
                  data-load-error="{{ __('Unable to load this user.') }}"
                  data-subject-field="first_name last_name"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this user?') }}"
                  data-confirm-label="{{ __('Update User') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="edit-user-first-name" class="{{ $label }}">{{ __('First Name') }}</label>
                        <div class="mt-1">
                            <input type="text" name="first_name" id="edit-user-first-name" required autocomplete="off"
                                   class="{{ $input }}" placeholder="{{ __('Enter first name') }}">
                        </div>
                    </div>

                    <div>
                        <label for="edit-user-last-name" class="{{ $label }}">{{ __('Last Name') }}</label>
                        <div class="mt-1">
                            <input type="text" name="last_name" id="edit-user-last-name" required autocomplete="off"
                                   class="{{ $input }}" placeholder="{{ __('Enter last name') }}">
                        </div>
                    </div>
                </div>

                <div>
                    <label for="edit-user-company" class="{{ $label }}">{{ __('Company') }}</label>
                    <div class="mt-1">
                        <input type="text" name="company" id="edit-user-company" autocomplete="off"
                               class="{{ $input }}" placeholder="{{ __('Optional') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-user-email" class="{{ $label }}">{{ __('Email') }}</label>
                    <div class="mt-1">
                        <input type="email" name="email" id="edit-user-email" required
                               class="{{ $input }}" placeholder="{{ __('name@example.com') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-user-password" class="{{ $label }}">{{ __('Password') }}</label>
                    <div class="mt-1">
                        <input type="password" name="password" id="edit-user-password" autocomplete="new-password"
                               class="{{ $input }}" placeholder="{{ __('Leave blank to keep the current password') }}">
                        <p class="{{ $hint }}">{{ __('Leave both password fields blank to keep the current password.') }}</p>
                    </div>
                </div>

                <div>
                    <label for="edit-user-password-confirmation" class="{{ $label }}">{{ __('Confirm Password') }}</label>
                    <div class="mt-1">
                        <input type="password" name="password_confirmation" id="edit-user-password-confirmation" autocomplete="new-password"
                               class="{{ $input }}" placeholder="{{ __('Repeat the new password') }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Roles') }}</span>
                    <div class="mt-1">
                        <x-ui.checkbox-picker id="edit-user-roles" name="roles[]" :items="$roleOptions"
                                              placeholder="{{ __('Select roles') }}"
                                              search-placeholder="{{ __('Search roles') }}"
                                              empty-text="{{ __('No roles match that search.') }}" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-user-submit" class="{{ $submitButton }}">{{ __('Update User') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- View drawer: read only. resources/js/pages/users.js fills it from the same
         endpoint the edit drawer loads. --}}
    <x-ui.drawer name="view-user-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('User Details') }}
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('View only') }}</span>
            </h3>
            <button type="button"
                    class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                    x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="view-user-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <dl class="space-y-3.5">
                <div>
                    <dt class="{{ $label }}">{{ __('Name') }}</dt>
                    <dd id="view-user-name" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Email') }}</dt>
                    <dd id="view-user-email" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Company') }}</dt>
                    <dd id="view-user-company" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Roles') }}</dt>
                    <dd id="view-user-roles" class="mt-1 text-sm text-gray-700 dark:text-gray-300"></dd>
                </div>
            </dl>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this user? This will soft-delete the account.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this user?') }}"
        confirm-label="{{ __('Create User') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/users.js; see vite.config.js. --}}
        @vite('resources/js/pages/users.js')
    @endpush
</x-app-layout>
