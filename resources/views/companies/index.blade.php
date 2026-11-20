<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Companies') }}" action-label="{{ __('Add Company') }}" action-id="create-company" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm leading-5 text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600';
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $cancelButton = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $submitButton = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600';
        $closeButton = 'rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white';
        $errorBox = 'mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300';
        // pr-10 keeps the @tailwindcss/forms chevron from sitting on the value.
        $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort and the filter (resources/js/ui/table-toolbar.js). The filter narrows the feed
                 in the browser (no request); it is read by resources/js/pages/companies.js. --}}
            <x-ui.table-toolbar :export-title="__('Companies')" :views="[]" :sorts="[
                [__('Name: A to Z'), 1, 'asc'],
                [__('Name: Z to A'), 1, 'desc'],
                [__('Most users'), 2, 'desc'],
            ]">
                <x-ui.filter-field :label="__('Status')">
                    <select id="company-status-filter" aria-label="{{ __('Status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/companies.js. --}}
            <table id="companies-table"
                   data-endpoint="{{ route('companies.index') }}"
                   data-protected-label="{{ __('Protected') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" data-no-export class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Company Name') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Users') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Status') }}</th>
                        <th scope="col" data-no-export class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-company-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Company') }}</h3>
            <button type="button" class="{{ $closeButton }}" x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="create-company-errors" class="{{ $errorBox }}"></div>

            {{-- The `data-*` attributes carry the page wiring to resources/js/pages/companies.js;
                 the copy stays here so __() applies. --}}
            <form id="create-company-form" method="POST" action="{{ route('companies.store') }}"
                  data-drawer="create-company-drawer"
                  data-subject-field="company_name"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this company?') }}"
                  data-confirm-label="{{ __('Create Company') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-company-name" class="{{ $label }}">{{ __('Company Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="company_name" id="create-company-name" required autocomplete="off"
                               class="{{ $input }}" placeholder="{{ __('e.g. Acme Foods') }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="create-company-is_active" :checked="true" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Only active companies are offered when someone registers.') }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-company-submit" class="{{ $submitButton }}">{{ __('Create Company') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-company-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Company') }}</h3>
            <button type="button" class="{{ $closeButton }}" x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="edit-company-errors" class="{{ $errorBox }}"></div>

            <form id="edit-company-form" method="POST" action=""
                  data-drawer="edit-company-drawer"
                  data-load-error="{{ __('Unable to load this company.') }}"
                  data-subject-field="company_name"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this company?') }}"
                  data-confirm-label="{{ __('Update Company') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-company-name" class="{{ $label }}">{{ __('Company Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="company_name" id="edit-company-name" required autocomplete="off"
                               class="{{ $input }}" placeholder="{{ __('e.g. Acme Foods') }}">
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="edit-company-is_active" :checked="true" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Switching a company off keeps its users, it only stops offering it to new ones.') }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-company-submit" class="{{ $submitButton }}">{{ __('Update Company') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- View drawer: read only. resources/js/pages/companies.js fills every value from the same
         endpoint the edit drawer loads. --}}
    <x-ui.drawer name="view-company-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('Company') }}
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('View only') }}</span>
            </h3>
            <button type="button" class="{{ $closeButton }}" x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="view-company-errors" class="{{ $errorBox }}"></div>

            <dl class="space-y-3.5">
                <div>
                    <dt class="{{ $label }}">{{ __('Company Name') }}</dt>
                    <dd class="mt-1 flex items-center gap-2 text-sm text-gray-900 dark:text-white">
                        <span id="view-company-name"></span>
                        {{-- Shown by resources/js/pages/companies.js for a protected company. --}}
                        <span id="view-company-protected"
                              class="hidden rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ __('Protected') }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Users') }}</dt>
                    <dd id="view-company-users" class="mt-1 text-sm tabular-nums text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Availability') }}</dt>
                    <dd id="view-company-status" class="mt-1 text-sm"></dd>
                </div>
            </dl>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this company? A company that still has users cannot be deleted: switch it off instead.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this company?') }}"
        confirm-label="{{ __('Create Company') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/companies.js; see vite.config.js. --}}
        @vite('resources/js/pages/companies.js')
    @endpush
</x-app-layout>
