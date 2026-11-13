@props(['label'])

{{--
    One labelled field inside the Filter dropdown of <x-ui.table-toolbar>.

        <x-ui.filter-field :label="__('Status')">
            <select id="..."> ... </select>
        </x-ui.filter-field>
--}}
<label class="block">
    <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</span>
    {{ $slot }}
</label>
