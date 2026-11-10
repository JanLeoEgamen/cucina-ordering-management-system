{{--
    Drawer (sliding side panel).

    The panel is ALWAYS rendered and slides with a bound transform, instead of
    `x-show` + `x-transition`. Two reasons:

    1. Smoothness — when `display` flips from none to block in the same frame as
       the transform change, the browser has no starting frame to animate from,
       so the panel pops open instead of sliding right to left.
    2. Reliability — an `x-transition` panel relies on Alpine removing its
       transition classes when the transition ends; if that never happens
       (background tab) the panel stays parked off screen while `show` is true.

    Closed = parked at the slide offset + aria-hidden. `!translate-x-0` carries
    the important flag so the open state wins regardless of class order.

    z-index: backdrop 60, panel 70 (see the layer stack in resources/css/app.css).
    The backdrop is deliberately ABOVE the sidebar (50), otherwise the navigation
    stays sharp and unshaded while the drawer is open.
--}}
@props([
    'name' => 'drawer',
    'show' => false,
    'placement' => 'right',
    'size' => 'lg',
])

@php
$widthClasses = [
    'sm'  => 'w-full max-w-sm',
    'md'  => 'w-full max-w-md',
    'lg'  => 'w-full max-w-lg',
    'xl'  => 'w-full max-w-xl',
    '2xl' => 'w-full max-w-2xl',
    'full'=> 'w-full',
][$size ?? 'lg'];

$side  = $placement === 'right' ? 'right-0' : 'left-0';
$slide = $placement === 'right' ? 'translate-x-full' : '-translate-x-full';
@endphp

<div
    x-data="{ show: @js($show) }"
    x-init="$watch('show', value => {
        if (value) { document.body.classList.add('overflow-hidden'); }
        else { document.body.classList.remove('overflow-hidden'); }
    })"
    x-on:open-drawer.window="$event.detail === '{{ $name }}' ? show = true : null"
    x-on:close-drawer.window="$event.detail === '{{ $name }}' ? show = false : null"
>
    {{-- Backdrop --}}
    <div
        x-bind:class="{ 'opacity-100': show, 'opacity-0 pointer-events-none': ! show }"
        x-bind:aria-hidden="show ? 'false' : 'true'"
        x-on:click="show = false"
        class="fixed inset-0 z-[60] bg-gray-900/60 opacity-0 backdrop-blur-sm transition-opacity duration-300 ease-out"
    ></div>

    {{-- Panel --}}
    <div
        x-bind:class="{ '!translate-x-0': show }"
        x-bind:aria-hidden="show ? 'false' : 'true'"
        class="fixed inset-y-0 {{ $side }} z-[70] {{ $widthClasses }} transform {{ $slide }} transition-transform duration-300 ease-in-out"
    >
        <div class="flex h-full flex-col bg-white shadow-2xl dark:bg-gray-800">
            {{ $slot }}
        </div>
    </div>
</div>
