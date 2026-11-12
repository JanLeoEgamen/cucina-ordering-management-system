<x-app-layout>
    <x-slot name="header">
        <x-page-header title="{{ __('Dish Masterlist') }}" action-label="{{ __('Add Dish') }}" action-id="create-dish">
            {{-- Dish types are managed in a drawer here instead of on a page of their own. --}}
            @can('view dish-types')
                <button type="button" id="manage-dish-types"
                        class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.41.59l7 7a2 2 0 010 2.82l-5 5a2 2 0 01-2.82 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z" /></svg>
                    {{ __('Manage Types') }}
                </button>
            @endcan
        </x-page-header>
    </x-slot>

    @php
        $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
        $input = 'block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm leading-5 text-gray-900 placeholder-gray-400 transition focus:border-gray-900 focus:bg-white focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700/60 dark:text-white dark:placeholder-gray-400 dark:focus:border-gray-300 dark:focus:bg-gray-700 dark:focus:ring-gray-600';
        $th = 'border-b border-gray-100 bg-transparent px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 whitespace-nowrap dark:border-gray-700 dark:text-gray-400';
        $cancelButton = 'rounded-full border border-gray-200 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:focus:ring-gray-700';
        $submitButton = 'rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-4 focus:ring-gray-300 dark:focus:ring-gray-600';
        // pr-10 keeps the @tailwindcss/forms chevron from sitting on the value.
        $filter = 'block w-full rounded-full border border-gray-200 bg-white py-2.5 pl-4 pr-10 text-sm leading-5 text-gray-900 focus:border-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 sm:w-auto dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:focus:border-gray-300 dark:focus:ring-gray-600';
    @endphp

    <div class="container mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">
            {{-- Search, sort, filters and the view tabs (resources/js/ui/table-toolbar.js). The filters
                 narrow the feed in the browser (no request); they are read by resources/js/pages/dishes.js. --}}
            <x-ui.table-toolbar :views="['table', 'card']" :sorts="[
                [__('Name: A to Z'), 2, 'asc'],
                [__('Name: Z to A'), 2, 'desc'],
                [__('Price: low to high'), 4, 'asc'],
                [__('Price: high to low'), 4, 'desc'],
            ]">
                <x-ui.filter-field :label="__('Status')">
                    <select id="dish-status-filter" aria-label="{{ __('Status') }}" class="{{ $filter }} sm:!w-full">
                        <option value="">{{ __('All statuses') }}</option>
                        <option value="active">{{ __('Active') }}</option>
                        <option value="inactive">{{ __('Inactive') }}</option>
                    </select>
                </x-ui.filter-field>
            
                <x-ui.filter-field :label="__('Dish Type')">
                    {{-- Every live dish type, not only the available ones: a dish can still be
                         tagged with a type that was turned off. --}}
                    <select id="dish-type-filter" multiple aria-label="{{ __('Dish Type') }}"
                            data-placeholder="{{ __('All dish types') }}" data-count-label="{{ __('{n} dish types') }}"
                            class="{{ $filter }} sm:!w-full">
                        @foreach ($dishTypeFilters as $dishTypeFilter)
                            <option value="{{ $dishTypeFilter->dish_type_id }}">{{ $dishTypeFilter->type_name }}</option>
                        @endforeach
                    </select>
                </x-ui.filter-field>
            </x-ui.table-toolbar>

            {{-- Rows come from this route's AJAX response (client side table);
                 `data-endpoint` is read by resources/js/pages/dishes.js. --}}
            <table id="dishes-table"
                   data-endpoint="{{ route('dishes.index') }}"
                   class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50/70 dark:bg-gray-900/40">
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
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-700 dark:bg-gray-800"></tbody>
            </table>

            {{-- Card grid for the card view; populated by resources/js/pages/dishes.js --}}
            <div id="dishes-cards"
                 class="hidden grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                 data-empty="{{ __('No data available yet') }}"></div>
        </div>
    </div>

    {{-- Create drawer --}}
    <x-ui.drawer name="create-dish-drawer" placement="right" size="lg">
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Create Dish') }}</h3>
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
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Edit Dish') }}</h3>
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
        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h3 class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-white">
                {{ __('Dish Details') }}
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
            <div id="view-dish-errors" class="mb-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

            <div id="view-dish-image-wrapper" class="mb-3 hidden">
                <x-ui.zoomable-image id="view-dish-image" src="" alt=""
                     class="h-32 w-full rounded-lg bg-gray-50 object-contain ring-1 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700" />
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

        <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
            <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Close') }}</button>
        </div>
    </x-ui.drawer>

    @can('view dish-types')
        {{--
            Manage Types drawer: dish types live here instead of on a page of their
            own. Two panels, swapped by resources/js/pages/dish-types-manager.js:
            the list, and one form that creates or edits (its `_method` input is
            switched between POST and PUT). It does not use the page's shared
            #delete-modal / #save-modal (dishes.js owns those), so deleting is
            confirmed inside the list row.
        --}}
        <x-ui.drawer name="manage-dish-types-drawer" placement="right" size="md">
            <div id="manage-dish-types-panel" class="flex h-full flex-col overflow-hidden"
                 data-feed-url="{{ route('dish-types.index') }}"
                 data-store-url="{{ route('dish-types.store') }}"
                 data-drawer="manage-dish-types-drawer"
                 data-empty="{{ __('No dish types yet') }}"
                 data-load-error="{{ __('Unable to load this dish type.') }}"
                 data-delete-prompt="{{ __('Delete the dish type {name}?') }}"
                 data-confirm-create="{{ __('Create the dish type {name}?') }}"
                 data-confirm-edit="{{ __('Save the changes to {name}?') }}"
                 data-yes="{{ __('Delete') }}"
                 data-no="{{ __('Cancel') }}"
                 data-title-create="{{ __('Add Dish Type') }}"
                 data-title-edit="{{ __('Edit Dish Type') }}"
                 data-label-create="{{ __('Create Dish Type') }}"
                 data-label-edit="{{ __('Update Dish Type') }}">
                <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-gray-700">
                    <h3 id="types-title" class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Dish Types') }}</h3>
                    <button type="button"
                            class="rounded-full border border-gray-200 p-2.5 text-gray-500 transition hover:bg-gray-100 dark:border-gray-600 hover:text-gray-900 focus:outline-none focus:ring-4 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white"
                            x-on:click="show = false">
                        <span class="sr-only">{{ __('Close') }}</span>
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div id="types-errors" class="mx-6 mt-4 hidden rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300"></div>

                {{-- Panel 1: the list --}}
                <div id="types-list-panel" class="flex min-h-0 flex-1 flex-col">
                    <ul id="types-list" class="flex-1 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700"></ul>

                    <div class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
                        <button type="button" class="{{ $cancelButton }}" x-on:click="show = false">{{ __('Done') }}</button>
                        @can('create dish-types')
                            <button type="button" id="types-add" class="{{ $submitButton }}">{{ __('Add Dish Type') }}</button>
                        @endcan
                    </div>
                </div>

                {{-- Panel 2: create / edit --}}
                <form id="types-form" method="POST" action="" class="hidden min-h-0 flex-1 flex-col">
                    @csrf
                    <input type="hidden" name="_method" value="PUT" disabled>

                    <div class="flex-1 space-y-5 overflow-y-auto p-6">
                        <div>
                            <label for="types-name" class="{{ $label }}">{{ __('Type Name') }}</label>
                            <div class="mt-1">
                                <input type="text" name="type_name" id="types-name" required
                                       class="{{ $input }}" placeholder="{{ __('Enter dish type name') }}">
                            </div>
                        </div>

                        <div>
                            <label for="types-description" class="{{ $label }}">{{ __('Description') }}</label>
                            <div class="mt-1">
                                <textarea name="description" id="types-description" rows="3" class="{{ $input }}"
                                          placeholder="{{ __('Enter description') }}"></textarea>
                            </div>
                        </div>

                        <div>
                            <span class="{{ $label }}">{{ __('Availability') }}</span>
                            <div class="mt-2">
                                <x-ui.toggle id="types-is_active" :checked="true" />
                            </div>
                        </div>
                    </div>

                    <div id="types-form-actions" class="flex justify-end gap-3 border-t border-gray-100 px-6 py-4 dark:border-gray-700">
                        <button type="button" id="types-back" class="{{ $cancelButton }}">{{ __('Back') }}</button>
                        <button type="submit" id="types-submit" class="{{ $submitButton }}">{{ __('Create Dish Type') }}</button>
                    </div>

                    {{-- Asked after Save and before anything is sent: swaps with the buttons above. --}}
                    <div id="types-confirm" role="alertdialog" aria-labelledby="types-confirm-text"
                         class="hidden flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-900/40">
                        <p id="types-confirm-text" class="min-w-0 flex-1 basis-48 text-sm font-medium text-gray-900 dark:text-white"></p>
                        <div class="flex flex-shrink-0 gap-3">
                            <button type="button" id="types-confirm-back" class="{{ $cancelButton }}">{{ __('Cancel') }}</button>
                            <button type="button" id="types-confirm-go" data-loading-label="{{ __('Saving...') }}" class="{{ $submitButton }}"></button>
                        </div>
                    </div>
                </form>
            </div>
        </x-ui.drawer>
    @endcan

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

        {{-- The Manage Types drawer (resources/js/pages/dish-types-manager.js). --}}
        @can('view dish-types')
            @vite('resources/js/pages/dish-types-manager.js')
        @endcan
    @endpush
</x-app-layout>
