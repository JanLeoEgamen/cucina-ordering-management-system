<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Discounts') }}" action-label="{{ __('Add Discount') }}" action-id="create-discount" />
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
            {{-- The filter narrows the feed in the browser (no request); resources/js/pages/discounts.js reads it. --}}
            <x-ui.table-toolbar :export-title="__('Discounts')" :views="[]" :sorts="[
                [__('Name: A to Z'), 1, 'asc'],
                [__('Name: Z to A'), 1, 'desc'],
                [__('Highest percent'), 2, 'desc'],
                [__('Most used'), 5, 'desc'],
            ]">
                <x-ui.filter-field :label="__('Status')">
                    <select id="discount-status-filter" aria-label="{{ __('Status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="scheduled">{{ __('Scheduled') }}</option>
                        <option value="expired">{{ __('Expired') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            <table id="discounts-table"
                   data-endpoint="{{ route('discounts.index') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
                    <tr>
                        <th scope="col" data-no-export class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }}">{{ __('Discount Name') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Percent') }}</th>
                        <th scope="col" class="{{ $th }} hidden md:table-cell">{{ __('Proof') }}</th>
                        <th scope="col" class="{{ $th }} hidden lg:table-cell">{{ __('Valid') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Used') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Status') }}</th>
                        <th scope="col" data-no-export class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-discount-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Discount') }}</h3>
            <button type="button" class="{{ $closeButton }}" x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="create-discount-errors" class="{{ $errorBox }}"></div>

            <form id="create-discount-form" method="POST" action="{{ route('discounts.store') }}"
                  data-drawer="create-discount-drawer"
                  data-subject-field="discount_name"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this discount?') }}"
                  data-confirm-label="{{ __('Create Discount') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-discount-name" class="{{ $label }}">{{ __('Discount Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="discount_name" id="create-discount-name" required autocomplete="off"
                               class="{{ $input }}" placeholder="{{ __('e.g. Senior Citizen') }}">
                    </div>
                </div>

                <div>
                    <label for="create-discount-percent" class="{{ $label }}">{{ __('Percent off') }}</label>
                    <div class="mt-1">
                        <input type="number" name="percent" id="create-discount-percent" required min="0.01" max="100" step="0.01" inputmode="decimal"
                               class="{{ $input }}" placeholder="20">
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Taken off the order subtotal (the dishes, before any discount). 100 makes the order free.') }}</p>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Proof of eligibility') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle name="requires_proof" id="create-discount-requires_proof" :checked="true" :on-label="__('Required')" :off-label="__('Not needed')" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('When required, the customer uploads an ID (PWD, Senior Citizen...) and staff approve or reject it. When not needed, the discount is applied at once.') }}</p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="create-discount-from" class="{{ $label }}">{{ __('Valid from') }}</label>
                        <div class="mt-1">
                            <input type="date" name="valid_from" id="create-discount-from" class="{{ $input }}">
                        </div>
                    </div>
                    <div>
                        <label for="create-discount-until" class="{{ $label }}">{{ __('Valid until') }}</label>
                        <div class="mt-1">
                            <input type="date" name="valid_until" id="create-discount-until" class="{{ $input }}">
                        </div>
                    </div>
                </div>
                <p class="-mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Both days are included. Leave a date empty for no limit on that side.') }}</p>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="create-discount-is_active" :checked="true" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Only active discounts inside their dates are offered at checkout.') }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-discount-submit" class="{{ $submitButton }}">{{ __('Create Discount') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-discount-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Discount') }}</h3>
            <button type="button" class="{{ $closeButton }}" x-on:click="show = false">
                <span class="sr-only">{{ __('Close') }}</span>
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            <div id="edit-discount-errors" class="{{ $errorBox }}"></div>

            <form id="edit-discount-form" method="POST" action=""
                  data-drawer="edit-discount-drawer"
                  data-load-error="{{ __('Unable to load this discount.') }}"
                  data-subject-field="discount_name"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this discount? Orders already placed keep the percent they were given.') }}"
                  data-confirm-label="{{ __('Update Discount') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-discount-name" class="{{ $label }}">{{ __('Discount Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="discount_name" id="edit-discount-name" required autocomplete="off"
                               class="{{ $input }}" placeholder="{{ __('e.g. Senior Citizen') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-discount-percent" class="{{ $label }}">{{ __('Percent off') }}</label>
                    <div class="mt-1">
                        <input type="number" name="percent" id="edit-discount-percent" required min="0.01" max="100" step="0.01" inputmode="decimal"
                               class="{{ $input }}" placeholder="20">
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Orders already placed keep the percent they were given.') }}</p>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Proof of eligibility') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle name="requires_proof" id="edit-discount-requires_proof" :checked="true" :on-label="__('Required')" :off-label="__('Not needed')" />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="edit-discount-from" class="{{ $label }}">{{ __('Valid from') }}</label>
                        <div class="mt-1">
                            <input type="date" name="valid_from" id="edit-discount-from" class="{{ $input }}">
                        </div>
                    </div>
                    <div>
                        <label for="edit-discount-until" class="{{ $label }}">{{ __('Valid until') }}</label>
                        <div class="mt-1">
                            <input type="date" name="valid_until" id="edit-discount-until" class="{{ $input }}">
                        </div>
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="edit-discount-is_active" :checked="true" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Switching a discount off keeps the orders that used it, it only stops offering it.') }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-discount-submit" class="{{ $submitButton }}">{{ __('Update Discount') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- View drawer: read only, filled by resources/js/pages/discounts.js from the edit endpoint. --}}
    <x-ui.drawer name="view-discount-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('Discount') }}
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
            <div id="view-discount-errors" class="{{ $errorBox }}"></div>

            <dl class="space-y-3.5">
                <div>
                    <dt class="{{ $label }}">{{ __('Discount Name') }}</dt>
                    <dd id="view-discount-name" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Percent off') }}</dt>
                    <dd id="view-discount-percent" class="mt-1 text-sm tabular-nums text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Proof of eligibility') }}</dt>
                    <dd id="view-discount-proof" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Valid') }}</dt>
                    <dd id="view-discount-window" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Used by') }}</dt>
                    <dd id="view-discount-used" class="mt-1 text-sm tabular-nums text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Status') }}</dt>
                    <dd id="view-discount-status" class="mt-1 text-sm"></dd>
                </div>
            </dl>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this discount? Orders that already used it keep their discount; customers just stop being offered it.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this discount?') }}"
        confirm-label="{{ __('Create Discount') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/discounts.js; started by resources/js/ui/soft-nav.js. --}}
        <x-page-module name="discounts" />
    @endpush
</x-app-layout>
