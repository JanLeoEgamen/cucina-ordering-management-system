@props([
    'label',
    'for',
    'hint' => null,
    'stacked' => false,
    'switch' => false,
])

@php
    // One shared look for every setting: the words on the left, the control on the right
    // (under the words on a phone, or when `stacked`: a big textarea / list needs the width).
    $layout = $switch
        ? 'grid-cols-[minmax(0,1fr)_auto] items-start'
        : ($stacked ? 'grid-cols-1' : 'grid-cols-1 sm:grid-cols-[minmax(0,1fr)_17rem] sm:items-start');
@endphp

{{--
    One setting.

        label   the field's name (a <label for="...">)
        for     the control's id (settings.js tracks every control that has one)
        hint    a plain sentence under the name; a `note` slot holds anything richer (a warning)
        stacked the control goes under the words, full width
        switch  the control is an on / off switch kept beside the words

    [data-field-text] / [data-control] are where settings.js puts the "Was: ... Undo" line.
--}}
<div data-field {{ $attributes->class(['grid gap-x-8 gap-y-2.5 py-4 first:pt-3 last:pb-1', $layout]) }}>
    <div data-field-text class="min-w-0">
        <label for="{{ $for }}" class="block text-sm font-medium text-gray-800 dark:text-gray-200">{{ $label }}</label>
        @if ($hint)
            <p class="mt-1 text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ $hint }}</p>
        @endif
        @isset($note)
            {{ $note }}
        @endisset
    </div>

    <div data-control @class(['min-w-0', 'pt-0.5' => $switch])>
        {{ $slot }}
    </div>
</div>
