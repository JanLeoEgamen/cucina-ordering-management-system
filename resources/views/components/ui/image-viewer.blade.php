{{--
    Image viewer (lightbox). Include ONCE per layout (layouts/app.blade.php).

    Anything marked `data-zoom` opens here when clicked, so there is nothing to
    wire per page: resources/js/ui/image-viewer.js listens on the document, which
    also covers markup built later by DataTables or a page script.

        <x-ui.zoomable-image :src="$url" alt="..." class="h-32 w-32" />   Blade
        <img src="..." data-zoom data-zoom-caption="Name">               JS markup
        <a href="full.jpg" data-zoom data-zoom-caption="Proof">View</a>   link (falls
                                                                           back to the link
                                                                           without JS)

    Closes with the X, a click on the dark area, or Escape (Escape closes only the
    viewer, never the drawer or modal behind it). Above drawers, modals and toasts.
--}}
<div id="image-viewer"
     class="fixed inset-0 z-[120] hidden animate-fade-in items-center justify-center bg-gray-900/80 p-4 backdrop-blur-sm"
     role="dialog" aria-modal="true" aria-label="{{ __('Image preview') }}"
     data-save-label="{{ __('Save image') }}">
    <button type="button" data-viewer-close
            class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-gray-900/60 text-white transition hover:bg-gray-900/90 focus:outline-none focus:ring-2 focus:ring-white/70">
        <span class="sr-only">{{ __('Close') }}</span>
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M6 6l12 12M18 6L6 18" />
        </svg>
    </button>

    <figure class="flex max-h-full max-w-full animate-pop flex-col items-center gap-3">
        <img data-viewer-image src="" alt=""
             class="max-h-[78vh] max-w-full rounded-xl bg-white object-contain shadow-2xl">

        <figcaption data-viewer-caption class="hidden max-w-md text-center text-sm font-medium text-white"></figcaption>

        <a data-viewer-save href="#" download
           class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-800 shadow transition hover:bg-gray-100 focus:outline-none focus:ring-4 focus:ring-white/40">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" />
            </svg>
            {{ __('Save image') }}
        </a>
    </figure>
</div>
