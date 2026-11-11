@props([
    'src' => '',
    'alt' => '',
    // Shown under the enlarged picture; defaults to the alt text.
    'caption' => null,
])

{{--
    An <img> that opens in the image viewer when clicked (see image-viewer.blade.php
    and resources/js/ui/image-viewer.js). Extra attributes (id, class, loading...)
    go to the <img>. The src may stay empty when a page script fills it later.
--}}
<img src="{{ $src }}" alt="{{ $alt }}"
     data-zoom data-zoom-caption="{{ $caption ?? $alt }}"
     {{ $attributes->class(['cursor-zoom-in']) }}>
