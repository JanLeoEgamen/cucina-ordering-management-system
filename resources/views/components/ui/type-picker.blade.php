{{--
    Dish type picker: a searchable checkbox list in a popover, with a master
    "select all", a divider under it and an OK / Cancel footer.

    The checkboxes are the source of truth: they carry the form field name, so
    the selection posts like any other field, and Alpine reads them back after
    every change. resources/js/pages/dishes.js only ticks them when the edit
    drawer opens and then dispatches a single change event.

    Every DOM lookup goes through `$root`, passed in from the expression: `$el`
    resolves to the element a directive sits on, not to the component's root.

    Props:
      id     - id of the trigger, so the label above can point at it
      types  - the choices: dish_type_id / type_name rows
      name   - form field name for the checkboxes
--}}
@props([
    'id',
    'types',
    'name' => 'dish_types[]',
])

<div
    x-data="{
        open: false,
        search: '',
        field: @js($name),
        types: @js($types->map(fn ($type) => ['id' => (int) $type->dish_type_id, 'name' => $type->type_name])->values()),
        picked: [],
        snapshot: [],

        boxes(root) {
            return [...root.querySelectorAll('input[name=\'' + this.field + '\']')];
        },

        // The checkboxes are the truth, so the chips follow them.
        sync(root) {
            this.picked = this.boxes(root)
                .filter((box) => box.checked)
                .map((box) => Number(box.value));
        },

        allPicked(root) {
            const boxes = this.boxes(root);

            return boxes.length > 0 && boxes.every((box) => box.checked);
        },

        somePicked(root) {
            return this.picked.length > 0 && ! this.allPicked(root);
        },

        get chosen() {
            return this.types.filter((type) => this.picked.includes(type.id));
        },

        get matches() {
            const needle = this.search.trim().toLowerCase();

            return needle === '' ? this.types : this.types.filter((type) => type.name.toLowerCase().includes(needle));
        },

        show(root) {
            this.search = '';
            this.sync(root);
            // What Cancel has to put back.
            this.snapshot = this.boxes(root).filter((box) => box.checked).map((box) => box.value);
            this.open = true;
            this.$nextTick(() => this.$refs.searchBox.focus());
        },

        // OK: keep the ticks and close.
        close() {
            this.open = false;
            this.search = '';
        },

        // Cancel, Escape, outside click: put the ticks back as they were.
        cancel(root) {
            for (const box of this.boxes(root)) {
                box.checked = this.snapshot.includes(box.value);
            }

            this.sync(root);
            this.close();
        },

        unpick(root, id) {
            const box = this.boxes(root).find((candidate) => Number(candidate.value) === id);

            if (box) {
                box.checked = false;
                this.sync(root);
            }
        },

        // Only the rows the search left on screen.
        toggleAll(root, checked) {
            for (const box of this.boxes(root)) {
                if (this.matches.some((type) => String(type.id) === box.value)) {
                    box.checked = checked;
                }
            }

            this.sync(root);
        },

        // A dish can sit in a type the list no longer offers. The page script
        // sends it here so it keeps its place, selected, instead of vanishing.
        add(root, type) {
            if (! this.types.some((known) => known.id === type.id)) {
                this.types.push(type);
            }

            this.$nextTick(() => {
                const box = this.boxes(root).find((candidate) => Number(candidate.value) === type.id);

                if (box) {
                    box.checked = true;
                }

                this.sync(root);
            });
        },
    }"
    x-on:change="sync($root)"
    x-on:add-dish-type="add($root, $event.detail)"
    x-on:click.outside="close()"
    x-on:keydown.escape.window="cancel($root)"
    data-type-picker
    class="relative"
>
    {{-- Trigger: chips for what is picked, or a placeholder --}}
    <div id="{{ $id }}"
         role="button" tabindex="0"
         x-on:click="open ? close() : show($root)"
         x-on:keydown.enter.prevent="open ? close() : show($root)"
         x-bind:aria-expanded="open ? 'true' : 'false'"
         class="flex max-h-24 min-h-11 w-full cursor-pointer flex-wrap items-center gap-1 overflow-y-auto rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-left text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700">
        <template x-if="picked.length === 0">
            <span class="px-1 text-gray-400 dark:text-gray-400">{{ __('Select dish types') }}</span>
        </template>

        <template x-for="type in chosen" x-bind:key="type.id">
            <span class="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-medium text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                <span x-text="type.name"></span>
                <button type="button" x-on:click.stop="unpick($root, type.id)"
                        class="text-gray-500 transition hover:text-red-600 focus:outline-none dark:text-gray-400 dark:hover:text-red-400">
                    <span class="sr-only">{{ __('Remove') }}</span>
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </span>
        </template>

        <svg class="ml-auto h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-200"
             x-bind:class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6" />
        </svg>
    </div>

    {{-- Popover --}}
    <div x-show="open" x-cloak
         class="absolute left-0 right-0 z-30 mt-1 rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-600 dark:bg-gray-800">
        <input type="text" x-model="search" x-ref="searchBox"
               placeholder="{{ __('Search dish types') }}"
               class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400">

        <label class="mt-2 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">
            <input type="checkbox" x-ref="allBox" name=""
                   x-bind:checked="allPicked($root)"
                   x-effect="$refs.allBox.indeterminate = somePicked($root)"
                   x-on:change="toggleAll($root, $event.target.checked)"
                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-700">
            {{ __('Select all') }}
            {{-- While a search is on, say what "all" currently means. --}}
            <span x-show="search !== ''" class="text-xs font-normal text-gray-500 dark:text-gray-400">
                (<span x-text="matches.length"></span> {{ __('matches') }})
            </span>
        </label>

        <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>

        <div class="max-h-52 space-y-0.5 overflow-y-auto">
            <template x-for="type in matches" x-bind:key="type.id">
                <label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">
                    <input type="checkbox" name="{{ $name }}" x-bind:value="type.id"
                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-700">
                    <span x-text="type.name"></span>
                </label>
            </template>

            <p x-show="matches.length === 0" class="px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400">
                {{ __('No dish types match that search.') }}
            </p>
        </div>

        <div class="mt-2 flex justify-end gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
            <button type="button" x-on:click="cancel($root)"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                {{ __('Cancel') }}
            </button>
            <button type="button" x-on:click="close()"
                    class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:focus:ring-indigo-800">
                {{ __('OK') }}
            </button>
        </div>
    </div>
</div>
