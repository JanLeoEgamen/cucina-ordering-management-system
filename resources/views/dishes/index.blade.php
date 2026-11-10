<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Dishes') }}" action-label="{{ __('Add Dish') }}" action-id="create-dish" />
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
                 markup live in resources/js/pages/dishes.js. --}}
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
                 `data-endpoint` is read by resources/js/pages/dishes.js. --}}
            <table id="dishes-table"
                   data-endpoint="{{ route('dishes.index') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th scope="col" class="{{ $th }} w-12">#</th>
                        <th scope="col" class="{{ $th }} w-20">{{ __('Image') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Dish Name') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Dish Type') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Price') }}</th>
                        <th scope="col" class="{{ $th }}">{{ __('Status') }}</th>
                        <th scope="col" class="{{ $th }} text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by resources/js/pages/dishes.js --}}
            <div id="dishes-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No data available yet') }}"></div>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-dish-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Dish') }}</h3>
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
            <div id="create-dish-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            {{--
                The `data-*` attributes carry the page wiring to
                resources/js/pages/dishes.js: which drawer the form lives in, the
                field used as the confirmation subject and the confirmation copy.
                Keeping the copy here means it stays translated by __().
            --}}
            <form id="create-dish-form" method="POST" action="{{ route('dishes.store') }}" enctype="multipart/form-data"
                  data-drawer="create-dish-drawer"
                  data-subject-field="dish_name"
                  data-confirm-title="{{ __('Confirm Create') }}"
                  data-confirm-message="{{ __('Are you sure you want to create this dish?') }}"
                  data-confirm-label="{{ __('Create Dish') }}"
                  class="space-y-5">
                @csrf

                <div>
                    <label for="create-dish-type" class="{{ $label }}">{{ __('Dish Types') }}</label>
                    <div class="mt-1">
                        <x-ui.type-picker id="create-dish-type" :types="$dishTypes" />
                    </div>
                </div>

                <div>
                    <label for="create-dish-name" class="{{ $label }}">{{ __('Dish Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="dish_name" id="create-dish-name" required
                               class="{{ $input }}" placeholder="{{ __('Enter dish name') }}">
                    </div>
                </div>

                <div>
                    <label for="create-description" class="{{ $label }}">{{ __('Description') }}</label>
                    <div class="mt-1">
                        <textarea name="description" id="create-description" rows="3" class="{{ $input }}"
                                  placeholder="{{ __('Enter description') }}"></textarea>
                    </div>
                </div>

                <div>
                    <label for="create-price" class="{{ $label }}">{{ __('Price') }}</label>
                    <div class="mt-1">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400">$</span>
                            <input type="number" name="price" id="create-price" step="0.01" min="0" required
                                   class="{{ $input }} pl-8" placeholder="0.00">
                        </div>
                    </div>
                </div>

                <div>
                    <label for="create-calories" class="{{ $label }}">{{ __('Calories') }}</label>
                    <div class="mt-1">
                        <input type="number" name="calories" id="create-calories" min="0"
                               class="{{ $input }}" placeholder="{{ __('Calories') }}">
                    </div>
                </div>

                <div>
                    <label for="create-main-ingredients" class="{{ $label }}">{{ __('Main Ingredients') }}</label>
                    <div class="mt-1">
                        <input type="text" name="main_ingredients" id="create-main-ingredients"
                               class="{{ $input }}" placeholder="{{ __('e.g. Chicken, Rice, Vegetables') }}">
                    </div>
                </div>

                <div>
                    <label for="create-image" class="{{ $label }}">{{ __('Image') }}</label>
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
                                    {{ __('Drag and drop your image here, or') }}
                                    <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ __('click to browse') }}</span>
                                </p>
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('PNG, JPG or WebP, up to 2 MB') }}</p>
                            </div>
                            <!-- Image preview wrapper (shown inside the zone when an image is selected) -->
                            <div id="create-image-wrapper" class="hidden">
                                <div class="relative">
                                    <img id="create-image-preview" src="" alt="" class="h-44 w-full rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
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
                            <input type="file" name="image" id="create-image" accept="image/*"
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
                    <button type="submit" id="create-dish-submit" class="{{ $submitButton }}">{{ __('Create Dish') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- Edit drawer --}}
    <x-ui.drawer name="edit-dish-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Dish') }}</h3>
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
            <div id="edit-dish-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <form id="edit-dish-form" method="POST" action="" enctype="multipart/form-data"
                  data-drawer="edit-dish-drawer"
                  data-load-error="{{ __('Unable to load this dish.') }}"
                  data-subject-field="dish_name"
                  data-confirm-title="{{ __('Confirm Update') }}"
                  data-confirm-message="{{ __('Are you sure you want to save the changes to this dish?') }}"
                  data-confirm-label="{{ __('Update Dish') }}"
                  class="space-y-5">
                @csrf
                @method('PUT')

                <div>
                    <label for="edit-dish-type" class="{{ $label }}">{{ __('Dish Types') }}</label>
                    <div class="mt-1">
                        <x-ui.type-picker id="edit-dish-type" :types="$dishTypes" />
                    </div>
                </div>

                <div>
                    <label for="edit-dish-name" class="{{ $label }}">{{ __('Dish Name') }}</label>
                    <div class="mt-1">
                        <input type="text" name="dish_name" id="edit-dish-name" required
                               class="{{ $input }}" placeholder="{{ __('Enter dish name') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-description" class="{{ $label }}">{{ __('Description') }}</label>
                    <div class="mt-1">
                        <textarea name="description" id="edit-description" rows="3" class="{{ $input }}"
                                  placeholder="{{ __('Enter description') }}"></textarea>
                    </div>
                </div>

                <div>
                    <label for="edit-price" class="{{ $label }}">{{ __('Price') }}</label>
                    <div class="mt-1">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500 dark:text-gray-400">$</span>
                            <input type="number" name="price" id="edit-price" step="0.01" min="0" required
                                   class="{{ $input }} pl-8" placeholder="0.00">
                        </div>
                    </div>
                </div>

                <div>
                    <label for="edit-calories" class="{{ $label }}">{{ __('Calories') }}</label>
                    <div class="mt-1">
                        <input type="number" name="calories" id="edit-calories" min="0"
                               class="{{ $input }}" placeholder="{{ __('Calories') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-main-ingredients" class="{{ $label }}">{{ __('Main Ingredients') }}</label>
                    <div class="mt-1">
                        <input type="text" name="main_ingredients" id="edit-main-ingredients"
                               class="{{ $input }}" placeholder="{{ __('e.g. Chicken, Rice, Vegetables') }}">
                    </div>
                </div>

                <div>
                    <label for="edit-image" class="{{ $label }}">{{ __('Image') }}</label>
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
                                    {{ __('Drag and drop your image here, or') }}
                                    <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ __('click to browse') }}</span>
                                </p>
                                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('PNG, JPG or WebP, up to 2 MB') }}</p>
                            </div>
                            <!-- Image preview wrapper (shown inside the zone when an image is selected) -->
                            <div id="edit-image-wrapper" class="hidden">
                                <div class="relative">
                                    <img id="edit-image-preview" src="" alt="" class="h-44 w-full rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
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
                            <input type="file" name="image" id="edit-image" accept="image/*"
                                   class="absolute inset-0 w-full h-full cursor-pointer opacity-0 z-10">
                            <!-- Set to 1 by the X button: clears the stored image on save -->
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
                    <button type="submit" id="edit-dish-submit" class="{{ $submitButton }}">{{ __('Update Dish') }}</button>
                </div>
            </form>
        </div>
    </x-ui.drawer>

    {{-- View drawer: read only. resources/js/pages/dishes.js fills every value
         from the same endpoint the edit drawer loads. --}}
    <x-ui.drawer name="view-dish-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('Dish Details') }}
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
            <div id="view-dish-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <div id="view-dish-image-wrapper" class="mb-3 hidden">
                <img id="view-dish-image" src="" alt=""
                     class="h-32 w-full rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700">
            </div>

            <dl class="space-y-3.5">
                <div>
                    <dt class="{{ $label }}">{{ __('Dish Name') }}</dt>
                    <dd id="view-dish-name" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Dish Type') }}</dt>
                    <dd id="view-dish-type" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Availability') }}</dt>
                    <dd id="view-dish-status" class="mt-1 text-sm"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Price') }}</dt>
                    <dd id="view-dish-price" class="mt-1 text-sm font-medium tabular-nums text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Calories') }}</dt>
                    <dd id="view-dish-calories" class="mt-1 text-sm text-gray-900 dark:text-white"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Description') }}</dt>
                    <dd id="view-dish-description" class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300"></dd>
                </div>
                <div>
                    <dt class="{{ $label }}">{{ __('Main Ingredients') }}</dt>
                    <dd id="view-dish-ingredients" class="mt-1 text-sm text-gray-700 dark:text-gray-300"></dd>
                </div>
            </dl>
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    <x-delete-confirmation-modal
        title="{{ __('Confirm Delete') }}"
        message="{{ __('Are you sure you want to delete this dish? This will soft-delete it.') }}"
        confirm-label="{{ __('Delete') }}"
    />

    <x-edit-confirmation-modal
        title="{{ __('Confirm Create') }}"
        message="{{ __('Are you sure you want to create this dish?') }}"
        confirm-label="{{ __('Create Dish') }}"
    />

    @push('scripts')
        {{-- Page behaviour lives in resources/js/pages/dishes.js; see vite.config.js. --}}
        @vite('resources/js/pages/dishes.js')
    @endpush
</x-app-layout>
