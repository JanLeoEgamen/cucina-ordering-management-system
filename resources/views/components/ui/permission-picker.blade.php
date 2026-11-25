{{--
    Permission picker: every permission on the page, grouped so it can be scanned
    instead of hunted for one by one.

    Layout: a sticky bar (search, "N of M selected", Select all / Clear / View only),
    then one collapsible section per area of the app ("Dish masterlist", "Payments
    and discounts" ...), each holding one row per resource ("Dishes", "Payments")
    with its actions as toggle chips (View, Create, Edit, Delete ...). A section has a
    Select all / Clear text button. Kept deliberately light: no box around every button,
    no checkbox per row.

    The chips ARE the form's checkboxes (`name`), server-rendered and only ever
    hidden by the search (x-show), never re-created, so a tick survives a search.
    The page script (pages/roles.js) ticks them for the record being edited and then
    dispatches ONE change event on this root, exactly as it does for
    <x-ui.checkbox-picker>; that is why the root carries `data-checkbox-picker`.
    The "select all" boxes have no name, so they are never posted.

    Every DOM lookup goes through `$root`: `$el` resolves to the element a directive
    sits on, not to the component's root.

    Props:
      groups             - Role::permissionGroups(): [{label, resources: [{label, permissions: [{id, action, name}]}]}]
      name               - form field name for the checkboxes
      searchPlaceholder  - placeholder of the search box
      emptyText          - shown when the search matches nothing
--}}
@props([
    'groups',
    'name',
    'searchPlaceholder' => null,
    'emptyText' => null,
])

@php
    $searchPlaceholder = $searchPlaceholder ?? __('Search permissions');
    $emptyText = $emptyText ?? __('No permissions match that search.');

    // What Alpine needs to count and filter without reading the DOM: the ids of
    // every row and section, and the text each row answers a search with.
    $model = collect($groups)->map(fn ($group) => [
        'rows' => collect($group['resources'])->map(fn ($row) => [
            'ids' => collect($row['permissions'])->pluck('id')->all(),
            'text' => mb_strtolower($row['label'] . ' ' . collect($row['permissions'])->pluck('name')->implode(' ')),
        ])->all(),
    ])->all();

    $total = collect($groups)->sum(fn ($group) => collect($group['resources'])->sum(fn ($row) => count($row['permissions'])));

    // A view permission starts with "view": the quick "View only" pick.
    $viewIds = collect($groups)->flatMap(fn ($group) => collect($group['resources'])->flatMap(
        fn ($row) => collect($row['permissions'])->filter(fn ($permission) => str_starts_with(mb_strtolower($permission['name']), 'view '))->pluck('id')
    ))->values()->all();

    $link = 'rounded text-xs font-medium text-gray-600 underline-offset-2 transition hover:text-gray-900 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-gray-300 dark:hover:text-white';
@endphp

<div
    x-data="{
        field: @js($name),
        sections: @js($model),
        viewIds: @js($viewIds),
        total: @js($total),
        picked: [],
        search: '',
        closed: [],

        boxes(root) {
            return [...root.querySelectorAll('input[name=\'' + this.field + '\']')];
        },

        // The checkboxes are the truth; the counters follow them.
        sync(root) {
            this.picked = this.boxes(root)
                .filter((box) => box.checked)
                .map((box) => Number(box.value));
        },

        // Tick exactly these ids (the boxes whose id is in `ids`), leave the rest.
        setIds(root, ids, checked) {
            const wanted = new Set(ids.map(String));

            for (const box of this.boxes(root)) {
                if (wanted.has(box.value)) {
                    box.checked = checked;
                }
            }

            this.sync(root);
        },

        // Count of ids that are ticked.
        count(ids) {
            return ids.filter((id) => this.picked.includes(id)).length;
        },

        needle() {
            return this.search.trim().toLowerCase();
        },

        rowShown(s, r) {
            const needle = this.needle();

            return needle === '' || this.sections[s].rows[r].text.includes(needle);
        },

        sectionShown(s) {
            return this.sections[s].rows.some((row, r) => this.rowShown(s, r));
        },

        get anyShown() {
            return this.sections.some((section, s) => this.sectionShown(s));
        },

        // The ids a section's / the whole page's buttons act on: only what the
        // search leaves on screen, like the checkbox picker's Select all.
        shownIds(s) {
            return this.sections[s].rows.flatMap((row, r) => this.rowShown(s, r) ? row.ids : []);
        },

        allShownIds() {
            return this.sections.flatMap((section, s) => this.shownIds(s));
        },

        toggleSection(root, s, checked) {
            this.setIds(root, this.shownIds(s), checked);
        },

        selectAll(root) {
            this.setIds(root, this.allShownIds(), true);
        },

        clear(root) {
            this.setIds(root, this.allShownIds(), false);
        },

        // Exactly the view permissions, nothing else: a read only role in one click.
        viewOnly(root) {
            this.setIds(root, this.boxes(root).map((box) => Number(box.value)), false);
            this.setIds(root, this.viewIds, true);
        },

        toggleOpen(s) {
            this.closed = this.closed.includes(s) ? this.closed.filter((i) => i !== s) : [...this.closed, s];
        },

        isOpen(s) {
            // A search opens every section that still has a match.
            return this.needle() !== '' || ! this.closed.includes(s);
        },
    }"
    x-on:change="sync($root)"
    x-init="sync($root)"
    data-checkbox-picker
    class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800"
>
    {{-- Sticky bar: search, how many, and the bulk actions (plain text buttons: boxes here were noise) --}}
    <div class="sticky top-0 z-10 space-y-2 rounded-t-xl border-b border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
            </svg>
            <input type="search" x-model="search" placeholder="{{ $searchPlaceholder }}"
                   x-on:keydown.enter.prevent
                   class="block w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm leading-5 text-gray-900 placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400">
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <p class="mr-auto text-xs text-gray-500 dark:text-gray-400" aria-live="polite">
                <span class="font-semibold tabular-nums text-gray-900 dark:text-white" x-text="picked.length"></span>
                {{ __('of') }} {{ $total }} {{ __('selected') }}
            </p>
            <button type="button" x-on:click="selectAll($root)" class="{{ $link }}">{{ __('Select all') }}</button>
            <button type="button" x-on:click="viewOnly($root)" class="{{ $link }}" title="{{ __('Tick every View permission and nothing else') }}">{{ __('View only') }}</button>
            <button type="button" x-on:click="clear($root)" class="{{ $link }}">{{ __('Clear') }}</button>
        </div>
    </div>

    <div>
        @foreach ($groups as $s => $group)
            <section x-show="sectionShown({{ $s }})" class="border-b border-gray-100 last:border-b-0 dark:border-gray-700">
                {{-- Section header: fold, name, how many, and one text button for the section --}}
                <div class="flex items-center gap-3 bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                    <button type="button" x-on:click="toggleOpen({{ $s }})"
                            x-bind:aria-expanded="isOpen({{ $s }}) ? 'true' : 'false'"
                            class="flex min-w-0 flex-1 items-center gap-2 rounded-md text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        <svg class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-200"
                             x-bind:class="isOpen({{ $s }}) || '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                        <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ __($group['label']) }}</span>
                        <span class="flex-shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400"
                              x-text="count(sections[{{ $s }}].rows.flatMap((row) => row.ids)) + ' / ' + sections[{{ $s }}].rows.flatMap((row) => row.ids).length"></span>
                    </button>

                    <button type="button" class="{{ $link }} flex-shrink-0"
                            x-on:click="toggleSection($root, {{ $s }}, count(shownIds({{ $s }})) !== shownIds({{ $s }}).length)"
                            x-text="count(shownIds({{ $s }})) === shownIds({{ $s }}).length ? @js(__('Clear')) : @js(__('Select all'))"></button>
                </div>

                {{-- Rows: the resource on the left, its actions as light chips (a tick = the accent) --}}
                <ul x-show="isOpen({{ $s }})" class="px-3 py-1">
                    @foreach ($group['resources'] as $r => $row)
                        <li x-show="rowShown({{ $s }}, {{ $r }})" class="flex items-start gap-3 py-1.5">
                            <span class="w-28 flex-shrink-0 pt-1 text-sm text-gray-800 dark:text-gray-100">{{ $row['label'] }}</span>

                            <div class="flex flex-wrap gap-1">
                                @foreach ($row['permissions'] as $permission)
                                    <label title="{{ $permission['name'] }}" class="cursor-pointer">
                                        <input type="checkbox" name="{{ $name }}" value="{{ $permission['id'] }}" class="peer sr-only">
                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:bg-gray-200 peer-checked:bg-accent peer-checked:text-accent-on peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 peer-focus-visible:ring-offset-1 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                            {{ $permission['action'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <p x-show="! anyShown" x-cloak class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
            {{ $emptyText }}
        </p>
    </div>
</div>
