@props([
    'name' => 'modal',
    'show' => false,
    'size' => 'md',
])

@php
$widthClasses = [
    'sm'  => 'max-w-sm',
    'md'  => 'max-w-md',
    'lg'  => 'max-w-lg',
    'xl'  => 'max-w-xl',
    '2xl' => 'max-w-2xl',
    'full'=> 'w-full',
][$size ?? 'md'];
@endphp

{{--
    Centred modal.

    Centring uses flexbox on a full-viewport wrapper, NOT
    `left-1/2 -translate-x-1/2`: the panel is `w-full`, so translating it by 50%
    of its own width centres the full-width panel while the visible `max-w-*` box
    stays at that panel's left edge — the dialog then renders at the left of the
    screen. Flexbox also cannot be disturbed by the opening transform (scale).

    The panel is always rendered and bound open/closed (opacity + scale), so the
    animation always runs and it can never be left hidden by a transition that
    never finished. The wrapper is `pointer-events-none` and the panel takes them
    back only while shown, so the invisible panel cannot swallow clicks.
--}}
<div
    x-data="{ show: @js($show) }"
    x-init="$watch('show', value => {
        if (value) { document.body.classList.add('overflow-hidden'); }
        else { document.body.classList.remove('overflow-hidden'); }
    })"
    x-on:open-modal.window="$event.detail === '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail === '{{ $name }}' ? show = false : null"
    x-on:keydown.escape.window="show = false"
>
    {{-- Backdrop --}}
    <div
        x-bind:class="{ 'opacity-100': show, 'opacity-0 pointer-events-none': ! show }"
        x-bind:aria-hidden="show ? 'false' : 'true'"
        x-on:click="show = false"
        class="fixed inset-0 z-[80] bg-gray-900/60 opacity-0 backdrop-blur-sm transition-opacity duration-300 ease-out"
    ></div>

    {{-- Centring wrapper --}}
    <div class="pointer-events-none fixed inset-0 z-[90] flex items-center justify-center p-4">
        <div
            x-bind:class="{ '!opacity-100 !scale-100 !pointer-events-auto': show }"
            x-bind:aria-hidden="show ? 'false' : 'true'"
            role="dialog"
            aria-modal="true"
            class="{{ $widthClasses }} pointer-events-none max-h-full w-full scale-95 overflow-y-auto rounded-2xl border border-gray-200 bg-white opacity-0 shadow-xl transition duration-200 ease-out dark:border-gray-700 dark:bg-gray-800"
        >
            {{ $slot }}
        </div>
    </div>
</div>
