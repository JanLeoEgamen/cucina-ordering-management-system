@props([
    'name' => 'is_active',
    'id' => 'is_active',
    'checked' => false,
    'onLabel' => null,
    'offLabel' => null,
])

@php
    $onLabel = $onLabel ?? __('Active');
    $offLabel = $offLabel ?? __('Inactive');
    $isChecked = filter_var($checked, FILTER_VALIDATE_BOOLEAN);
@endphp

{{--
    Boolean toggle.

    The hidden `0` input matters: an unchecked checkbox submits nothing at all
    and a checked one submits `on`, which fails Laravel's `boolean` rule with
    "The is active field must be true or false". With the hidden field the form
    always posts `0` or `1`.
--}}
<div>
    <label for="{{ $id }}" class="inline-flex cursor-pointer select-none items-center gap-3">
        <input type="hidden" name="{{ $name }}" value="0">
        <input id="{{ $id }}" name="{{ $name }}" type="checkbox" value="1"
               class="peer sr-only" @checked($isChecked)>

        <span class="relative h-6 w-11 flex-shrink-0 rounded-full bg-gray-200 transition-colors duration-200
                     after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-transform after:duration-200 after:content-['']
                     peer-checked:bg-indigo-600 peer-checked:after:translate-x-5 peer-checked:after:border-white
                     peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 peer-focus-visible:ring-offset-2
                     dark:bg-gray-600 dark:peer-focus-visible:ring-offset-gray-800"></span>

        <span class="hidden text-sm font-medium text-indigo-600 peer-checked:inline dark:text-indigo-400">{{ $onLabel }}</span>
        <span class="text-sm font-medium text-gray-500 peer-checked:hidden dark:text-gray-400">{{ $offLabel }}</span>
    </label>

    @error($name)
        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
