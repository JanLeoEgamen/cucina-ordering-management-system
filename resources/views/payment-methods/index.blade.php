<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Payment Methods') }}" action-label="{{ __('Add Method') }}" action-id="create-payment-method" />
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400';
        $th = 'border-b border-gray-200 bg-gray-100 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:bg-gray-700/50 dark:text-gray-300';
        $cancelButton = 'rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $submitButton = 'rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-indigo-300 dark:focus:ring-indigo-800';
        // pr-10 keeps the @tailwindcss/forms chevron from sitting on the value.
        $filter = 'block w-full rounded-lg border border-gray-300 bg-white py-2 pl-3 pr-10 text-sm leading-5 text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            {{-- The filter narrows the feed in the browser (no request); the
                 table's own search box keeps working next to it. Both are read
                 by resources/js/pages/payment-methods.js. --}}
            <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <select id="method-status-filter" aria-label="{{ __('Status') }}" class="{{ $filter }}">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </div>

                {{-- View switch: swaps the table for the card grid; wiring and card
                     markup live in resources/js/pages/payment-methods.js. --}}
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
                 `data-endpoint` is read by resources/js/pages/payment-methods.js. --}}
            <table id="payment-methods-table"
                   data-endpoint="{{ route('payment-methods.index') }}"
                   data-protected-label="{{ __('Protected') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th scope="col" class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }} w-20">{{ __('QR') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Method Name') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Account') }}</th>
                        <th scope="col" class="{{ $th }} hidden sm:table-cell">{{ __('Status') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by resources/js/pages/payment-methods.js --}}
            <div id="payment-methods-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No data available yet') }}"></div>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-payment-method-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Payment Method') }}</h3>
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
            <div id="create-payment-method-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            {{--
                The `data-*` attributes carry the page wiring to
                resources/js/pages/payment-methods.js: which drawer the form lives
                in, the field used as the confirmation subject and the copy.
                Keeping the copy here means it stays translated by __().
            --}}
            <form id="create-payment-method-form" method="POST" action="{{ route('payment-methods.store') }}" enctype="multipart/form-data"
                  data-drawer="create-payment-method-drawer"
                  data-subject-field="method_name"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this payment method?') }}"
                  data-confirm-label="{{ __('Create Method') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-method-name" class="{{ $label }}">{{ __('Method Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="method_name" id="create-method-name" required
                               class="{{ $input }}" placeholder="{{ __('e.g. Cash, GCash, Bank Transfer') }}">
                    </div>
                </div>

                <div>
                    <label for="create-account-name" class="{{ $label }}">{{ __('Account Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="account_name" id="create-account-name"
                               class="{{ $input }}" placeholder="{{ __('e.g. Cucina Kitchen') }}">
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Shown at checkout. Leave it empty for cash.') }}</p>
                </div>

                <div>
                    <label for="create-account-number" class="{{ $label }}">{{ __('Account Number') }}</label>
                    <div class="mt-1">
                        <input type="text" name="account_number" id="create-account-number"
                               class="{{ $input }}" placeholder="{{ __('e.g. 0917-000-0000') }}">
                    </div>
                </div>

                <div>
                    <label for="create-qr-image" class="{{ $label }}">{{ __('QR Code') }}</label>
                    <div class="mt-1">
                        <!-- Drag & drop upload zone -->
                        <div id="create-drop-zone"
                             class="relative cursor-pointer rounded-xl border-2 border-dashed border-gray-500 bg-gray-50/70 p-3 text-center transition-colors duration-200 hover:border-indigo-400 hover:bg-indigo-50/70 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-200 dark:border-gray-500 dark:bg-gray-800/40 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20 dark:focus-within:ring-indigo-900/60">
                            <div id="create-upload-content" class="flex flex-col items-center justify-center gap-2 px-6 py-6">
                                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 15.5V4.25m0 0L8 8.25m4-4 4 4" />
                                        <path d="M4.5 14.5v3A2.5 2.5 0 0 0 7 20h10a2.5 2.5 0 0 0 2.5-2.5v-3" />
                                    </svg>
                                </span>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ __('Drag and drop the QR image here, or') }}
                                    <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ __('click to browse') }}</span>
                                </p>
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('PNG, JPG or WebP, up to 2 MB') }}</p>
                            </div>
                            <!-- Image preview wrapper (shown inside the zone when an image is selected) -->
                            <div id="create-image-wrapper" class="hidden">
                                <div class="relative">
                                    <img id="create-image-preview" src="" alt="" class="mx-auto h-44 w-44 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
                                    <button type="button" id="create-remove-image"
                                            class="absolute right-2 top-2 z-20 flex h-7 w-7 items-center justify-center rounded-full bg-gray-900/60 text-white transition hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-white/70"
                                            aria-label="{{ __('Remove') }}">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M6 6l12 12M18 6L6 18" />
                                        </svg>
                                        <span class="sr-only">{{ __('Remove') }}</span>
                                    </button>
                                </div>
                                <p id="create-file-info" data-default="{{ __('Click the image to replace it') }}"
                                   class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Click the image to replace it') }}</p>
                            </div>
                            <!-- Hidden file input covering the zone so any click opens the picker -->
                            <input type="file" name="qr_image" id="create-qr-image" accept="image/*"
                                   class="absolute inset-0 w-full h-full cursor-pointer opacity-0 z-10">
                        </div>
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="create-is_active" :checked="true" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="create-payment-method-submit" class="{{ $submitButton }}">{{ __('Create Method') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-payment-method-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Payment Method') }}</h3>
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
            <div id="edit-payment-method-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <form id="edit-payment-method-form" method="POST" action="" enctype="multipart/form-data"
                  data-drawer="edit-payment-method-drawer"
                  data-load-error="{{ __('Unable to load this payment method.') }}"
                  data-subject-field="method_name"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this payment method?') }}"
                  data-confirm-label="{{ __('Update Method') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-method-name" class="{{ $label }}">{{ __('Method Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="method_name" id="edit-method-name" required
                               class="{{ $input }}" placeholder="{{ __('e.g. Cash, GCash, Bank Transfer') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-account-name" class="{{ $label }}">{{ __('Account Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="account_name" id="edit-account-name"
                               class="{{ $input }}" placeholder="{{ __('e.g. Cucina Kitchen') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-account-number" class="{{ $label }}">{{ __('Account Number') }}</label>
                    <div class="mt-1">
                        <input type="text" name="account_number" id="edit-account-number"
                               class="{{ $input }}" placeholder="{{ __('e.g. 0917-000-0000') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-qr-image" class="{{ $label }}">{{ __('QR Code') }}</label>
                    <div class="mt-1">
                        <!-- Drag & drop upload zone -->
                        <div id="edit-drop-zone"
                             class="relative cursor-pointer rounded-xl border-2 border-dashed border-gray-500 bg-gray-50/70 p-3 text-center transition-colors duration-200 hover:border-indigo-400 hover:bg-indigo-50/70 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-200 dark:border-gray-500 dark:bg-gray-800/40 dark:hover:border-indigo-500 dark:hover:bg-indigo-900/20 dark:focus-within:ring-indigo-900/60">
                            <div id="edit-upload-content" class="flex flex-col items-center justify-center gap-2 px-6 py-6">
                                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 15.5V4.25m0 0L8 8.25m4-4 4 4" />
                                        <path d="M4.5 14.5v3A2.5 2.5 0 0 0 7 20h10a2.5 2.5 0 0 0 2.5-2.5v-3" />
                                    </svg>
                                </span>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ __('Drag and drop the QR image here, or') }}
                                    <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ __('click to browse') }}</span>
                                </p>
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('PNG, JPG or WebP, up to 2 MB') }}</p>
                            </div>
                            <!-- Image preview wrapper (shown inside the zone when an image is selected) -->
                            <div id="edit-image-wrapper" class="hidden">
                                <div class="relative">
                                    <img id="edit-image-preview" src="" alt="" class="mx-auto h-44 w-44 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
                                    <button type="button" id="edit-remove-image"
                                            class="absolute right-2 top-2 z-20 flex h-7 w-7 items-center justify-center rounded-full bg-gray-900/60 text-white transition hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-white/70"
                                            aria-label="{{ __('Remove') }}">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                            <path d="M6 6l12 12M18 6L6 18" />
                                        </svg>
                                        <span class="sr-only">{{ __('Remove') }}</span>
                                    </button>
                                </div>
                                <p id="edit-file-info" data-default="{{ __('Click the image to replace it') }}"
                                   class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Click the image to replace it') }}</p>
                            </div>
                            <!-- Hidden file input covering the zone so any click opens the picker -->
                            <input type="file" name="qr_image" id="edit-qr-image" accept="image/*"
                                   class="absolute inset-0 w-full h-full cursor-pointer opacity-0 z-10">
                            <!-- Set to 1 by the X button: clears the stored QR on save -->
                            <input type="hidden" name="remove_image" value="">
                        </div>
                    </div>
                </div>

                <div>
                    <span class="{{ $label }}">{{ __('Availability') }}</span>
                    <div class="mt-2">
                        <x-ui.toggle id="edit-is_active" :checked="true" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Cancel') }}</button>
                    <button type="submit" id="edit-payment-method-submit" class="{{ $submitButton }}">{{ __('Update Method') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- View drawer: read only. resources/js/pages/payment-methods.js fills every
         value from the same endpoint the edit drawer loads. --}}
    <x-ui.drawer name="view-payment-method-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('Payment Method') }}
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
            <div id="view-payment-method-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <div id="view-method-qr-wrapper" class="mb-4 hidden">
                <x-ui.zoomable-image id="view-method-qr" src="" alt=""
                     class="mx-auto h-40 w-40 rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700" />
            </div>

            <dl class="space-y-3.5">
                <div>
                    <dt class="{{ $label }}">{{ __('Method Name') }}</dt>
                    <dd class="mt-1 flex items-center gap-2 text-sm text-gray-900 dark:text-white">
                        <span id="view-method-name"></span>
                        {{-- Shown by resources/js/pages/payment-methods.js for a protected method. --}}
                        <span id="view-method-protected"
                              class="hidden rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">{{ __('Protected') }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Account Name') }}</dt>
                    <dd id="view-method-account-name" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Account Number') }}</dt>
                    <dd id="view-method-account-number" class="mt-1 text-sm tabular-nums text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Availability') }}</dt>
                    <dd id="view-method-status" class="mt-1 text-sm"></dd>
                </div>
            </dl>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this payment method? It stops being offered at checkout, and the orders that used it keep its name.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this payment method?') }}"
        confirm-label="{{ __('Create Method') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/payment-methods.js; see vite.config.js. --}}
        @vite('resources/js/pages/payment-methods.js')
    @endpush
</x-app-layout>