@props([
    'label',
    'for',
    'hint' => null,
    'detail' => null,
    'stacked' => false,
    'switch' => false,
])

@php
    // One shared look for every setting: the words on the left, the control on the right
    // (under the words on a phone, or when `stacked`: a big textarea / list needs the width).
    $layout = $switch
        ? 'grid-cols-[minmax(0,1fr)_auto] items-center'
        : ($stacked ? 'grid-cols-1' : 'grid-cols-1 sm:grid-cols-[minmax(0,1fr)_17rem] sm:items-center');
@endphp

{{--
    One setting.

        label   the field's name (a <label for="...">)
        for     the control's id (settings.js tracks every control that has one)
        hint    ONE short line under the name, always visible (what the setting does)
        detail  the longer explanation, behind an "i" icon next to the name (hover / tap)
        note    a slot for anything that must stay visible and rich (a warning)
        stacked the control goes under the words, full width
        switch  the control is an on / off switch kept beside the words

    [data-field-text] / [data-control] are where settings.js puts the "Was: ... Undo" line.
--}}
<div data-field {{ $attributes->class(['grid gap-x-8 gap-y-2.5 py-4 first:pt-3 last:pb-1', $layout]) }}>
    <div data-field-text class="min-w-0">
        <div class="flex items-center gap-1">
            <label for="{{ $for }}" class="block text-sm font-normal text-gray-700 dark:text-gray-300">{{ $label }}</label>
            @if ($detail)
                <x-ui.info-tip :text="$detail" :label="__('About :setting', ['setting' => $label])" />
            @endif
        </div>
        @if ($hint)
            <p class="mt-0.5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $hint }}</p>
        @endif
        @isset($note)
            {{ $note }}
        @endisset
    </div>

    <div data-control class="min-w-0">
        {{ $slot }}
    </div>
</div>
