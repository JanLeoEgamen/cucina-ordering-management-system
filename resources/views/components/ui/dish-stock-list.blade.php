{{--
    Dish + stock list: the dishes a session offers, and the stock it has of each.

    The checkboxes ARE the form field: they post as `dish_ids[]`, and each row's
    number input posts as `stocks[<dish_id>]`, so the selection and the stocks
    travel with the form like any other field. The DOM stays the source of
    truth: the component only re-reads it, and resources/js/pages/order-sessions.js
    ticks the boxes of the record it is editing before dispatching ONE change
    event on this root.

    Every lookup goes through `$root`, passed in from the expression: `$el`
    resolves to the element a directive sits on, not to the component's root.

    A row is a div, not a label: a label wrapping both the checkbox and the stock
    input would toggle the checkbox on every click inside the stock box.

    Props:
      prefix  - id prefix, so the create and edit lists keep their ids apart
      dishes  - the choices: dish_id / dish_name / price rows, each with its dish_types

    Filters (all in the browser, none of them touch what is ticked): the name search,
    a dish type and Offered / Not offered. Select all / Clear only act on the rows
    the filters leave visible. They go back to empty when the drawer fills or clears
    the list (resources/js/pages/order-sessions.js dispatches `dish-list-reset`).
--}}
@props([
    'prefix',
    'dishes',
    // false for a session with no stock (bulk sessions): the same picker, without the number boxes.
    'stock' => true,
])

@php
    $search = 'block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400';
    $stockInput = 'w-20 rounded-lg border border-gray-300 bg-white px-2 py-1 text-sm leading-5 text-gray-900 tabular-nums focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white';

    // What the filters need per dish, and the types on offer (only those some dish has).
    $rows = collect($dishes)->map(fn ($dish) => [
        'id' => (int) $dish->dish_id,
        'name' => $dish->dish_name,
        'types' => collect($dish->dish_types ?? [])->pluck('dish_type_id')->map(fn ($id) => (int) $id)->all(),
    ])->values();

    $typeOptions = collect($dishes)
        ->flatMap(fn ($dish) => $dish->dish_types ?? [])
        ->unique('dish_type_id')
        ->sortBy('type_name', SORT_NATURAL | SORT_FLAG_CASE)
        ->values();

    $select = 'block w-full rounded-lg border border-gray-300 bg-white py-1.5 pl-3 pr-9 text-sm leading-5 text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white';
@endphp

<div
    x-data="{
        search: '',
        types: [],
        show: 'all',
        picked: [],
        rows: @js($rows),

        boxes(root) {
            return [...root.querySelectorAll('input[name=\'dish_ids[]\']')];
        },

        // The checkboxes are the truth, so the styling follows them.
        sync(root) {
            this.picked = this.boxes(root)
                .filter((box) => box.checked)
                .map((box) => Number(box.value));
        },

        // A dish shows when it passes the name search, the type and the offered filter.
        isShown(id) {
            const row = this.rows.find((candidate) => candidate.id === id);

            if (! row) {
                return false;
            }

            const needle = this.search.trim().toLowerCase();
            const offered = this.picked.includes(id);

            return (needle === '' || row.name.toLowerCase().includes(needle))
                && (this.types.length === 0 || this.types.some((type) => row.types.includes(Number(type))))
                && (this.show === 'all' || (this.show === 'offered') === offered);
        },

        get shown() {
            return this.rows.filter((row) => this.isShown(row.id)).length;
        },

        get filtered() {
            return this.search.trim() !== '' || this.types.length > 0 || this.show !== 'all';
        },

        // `root` is passed in ($el is not the root inside a directive). The dropdowns are
        // drawn over the real selects (resources/js/ui/select-menu.js), which only repaint
        // on a change event, and Alpine sets their value without one.
        resetFilters(root) {
            this.search = '';
            this.types = [];
            this.show = 'all';

            this.$nextTick(() => root.querySelectorAll('select').forEach((select) => select.dispatchEvent(new Event('change', { bubbles: true }))));
        },

        pick(root, checked) {
            for (const box of this.boxes(root)) {
                if (this.isShown(Number(box.value))) {
                    box.checked = checked;
                }
            }

            this.sync(root);
        },
    }"
    x-on:change="sync($root)"
    x-on:dish-list-reset="resetFilters($root)"
    data-dish-stock-list
    class="rounded-lg border border-gray-300 dark:border-gray-600"
>
    {{-- Search, select all / clear, and how many dishes are on offer --}}
    <div class="flex items-center gap-3 rounded-t-lg border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-700/40">
        <input type="text" x-model="search" placeholder="{{ __('Search dishes') }}" class="{{ $search }}">

        <button type="button" x-on:click="pick($root, true)"
                class="whitespace-nowrap text-xs font-medium text-indigo-600 transition hover:text-indigo-800 focus:outline-none dark:text-indigo-400 dark:hover:text-indigo-300">
            {{ __('Select all') }}
        </button>

        <button type="button" x-on:click="pick($root, false)"
                class="whitespace-nowrap text-xs font-medium text-gray-500 transition hover:text-gray-700 focus:outline-none dark:text-gray-400 dark:hover:text-gray-200">
            {{ __('Clear') }}
        </button>

        <span class="ml-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
            <span class="font-medium tabular-nums" x-text="picked.length"></span> {{ __('offered') }}
        </span>
    </div>

    {{-- Dish type and offered filters, with a way back when any filter is on --}}
    <div class="flex flex-wrap items-center gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-700/40">
        <select x-model="types" multiple aria-label="{{ __('Dish Type') }}"
                data-menu-size="sm" data-placeholder="{{ __('All dish types') }}" data-count-label="{{ __('{n} dish types') }}"
                class="{{ $select }} min-w-0 flex-1 sm:w-auto sm:flex-none">
            @foreach ($typeOptions as $typeOption)
                <option value="{{ $typeOption['dish_type_id'] }}">{{ $typeOption['type_name'] }}</option>
            @endforeach
        </select>

        <select x-model="show" aria-label="{{ __('Show') }}" data-menu-size="sm" class="{{ $select }} min-w-0 flex-1 sm:w-auto sm:flex-none">
            <option value="all">{{ __('All dishes') }}</option>
            <option value="offered">{{ __('Offered') }}</option>
            <option value="not-offered">{{ __('Not offered') }}</option>
        </select>

        <button type="button" x-show="filtered" x-cloak x-on:click="resetFilters($root)"
                class="whitespace-nowrap text-xs font-medium text-gray-500 transition hover:text-gray-700 focus:outline-none dark:text-gray-400 dark:hover:text-gray-200">
            {{ __('Clear filters') }}
        </button>

        <span x-show="filtered" x-cloak class="ml-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
            <span class="font-medium tabular-nums" x-text="shown"></span> {{ __('shown') }}
        </span>
    </div>

    <div class="max-h-64 divide-y divide-gray-100 overflow-y-auto rounded-b-lg dark:divide-gray-700/70">
        @forelse ($dishes as $dish)
            <div x-show="isShown({{ $dish->dish_id }})"
                 x-bind:class="picked.includes({{ $dish->dish_id }}) ? '' : 'opacity-55'"
                 class="flex items-center gap-3 px-3 py-2">
                <input type="checkbox"
                       id="{{ $prefix }}-dish-{{ $dish->dish_id }}"
                       name="dish_ids[]"
                       value="{{ $dish->dish_id }}"
                       data-name="{{ $dish->dish_name }}"
                       class="h-4 w-4 flex-shrink-0 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-700">

                <label for="{{ $prefix }}-dish-{{ $dish->dish_id }}" class="min-w-0 flex-1 cursor-pointer">
                    <span class="block truncate text-sm text-gray-800 dark:text-gray-200">{{ $dish->dish_name }}</span>
                    <span class="block text-xs tabular-nums text-gray-500 dark:text-gray-400">₱{{ number_format((float) $dish->price, 2) }}</span>
                </label>

                @if ($stock)
                    <label for="{{ $prefix }}-stock-{{ $dish->dish_id }}" class="flex-shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ __('Stock') }}</label>
                    <input type="number"
                           id="{{ $prefix }}-stock-{{ $dish->dish_id }}"
                           name="stocks[{{ $dish->dish_id }}]"
                           min="0" step="1" value="0"
                           class="{{ $stockInput }}">
                    {{-- What the box showed when the edit drawer was opened (order-sessions.js fills it; empty on create).
                         The server applies only the CHANGE from it, so orders placed while the drawer is open are not undone. --}}
                    <input type="hidden" name="loaded_stocks[{{ $dish->dish_id }}]" value="">
                @endif
            </div>
        @empty
            <p class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('There are no dishes to offer yet.') }}</p>
        @endforelse

        @if (count($rows) > 0)
            <p x-show="shown === 0" x-cloak class="px-3 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No dishes match these filters.') }}</p>
        @endif
    </div>
</div>
