{{--
    The app's mark. The logo uploaded in System Configuration when there is one (a picture with
    softly rounded corners: a square box, centred and cropped to fill it, so a photo looks right),
    else the built-in Cucina mark: a serving dome (cloche) on its plate, filled with the current
    text colour.

    `aspect-square` keeps it square where a caller sizes only the height (`h-8 w-auto` in the menu).
    For a rounder or squarer look change `rounded-lg` below (`rounded-md` ... `rounded-2xl`).

    `:custom="false"` keeps the built-in mark: for the faint watermark on the dish tiles, where
    a coloured picture would not fit.
--}}
@props(['custom' => true])
@php($logoUrl = $custom ? \App\Models\Setting::appLogoUrl() : null)
@if ($logoUrl)
    <img src="{{ $logoUrl }}" alt="" aria-hidden="true" decoding="async" {{ $attributes->class(['aspect-square rounded-lg object-cover']) }}>
@else
    <svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" {{ $attributes }}>
        <circle cx="24" cy="9.5" r="3"/>
        <path d="M6 33.5C6 23.836 13.835 16 23.5 16h1C34.165 16 42 23.836 42 33.5H6Z"/>
        <rect x="3" y="36" width="42" height="4.5" rx="2.25"/>
    </svg>
@endif
