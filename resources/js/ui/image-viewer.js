/**
 * Image viewer: click anything marked `data-zoom` to see it enlarged.
 *
 * The markup is the <x-ui.image-viewer> component, included once in the layout.
 * This module only listens on the document, so it works for images that are
 * built after the page loaded (DataTables cells, drawers, the checkout) with no
 * per-page wiring:
 *
 *   <img src="..." data-zoom data-zoom-caption="Optional caption">
 *   <a href="full.jpg" data-zoom>View</a>   (data-zoom="other.jpg" overrides the URL)
 *
 * The picture shown is, in order: the value of data-zoom, the link's href, the
 * image's current src.
 */
const viewer = document.getElementById('image-viewer');

if (viewer) {
    const image = viewer.querySelector('[data-viewer-image]');
    const caption = viewer.querySelector('[data-viewer-caption]');
    const save = viewer.querySelector('[data-viewer-save]');
    const closeButton = viewer.querySelector('[data-viewer-close]');

    let opener = null;

    const isOpen = () => ! viewer.classList.contains('hidden');

    // "gcash-qr.png": a readable name for the saved file.
    const fileName = (url, label) => {
        const extension = (String(url).split('?')[0].match(/\.(png|jpe?g|gif|webp|svg)$/i) ?? [])[0] ?? '.png';
        const slug = String(label || 'image').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'image';

        return slug + extension;
    };

    const open = (source, label, from) => {
        opener = from;

        image.src = source;
        image.alt = label;

        caption.textContent = label;
        caption.classList.toggle('hidden', label === '');

        // A pasted-in data: URL (a proof that is not uploaded yet) saves too.
        save.href = source;
        save.setAttribute('download', fileName(source, label));

        viewer.classList.remove('hidden');
        viewer.classList.add('flex');
        document.body.classList.add('overflow-hidden');

        closeButton.focus();
    };

    const close = () => {
        viewer.classList.add('hidden');
        viewer.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');

        image.removeAttribute('src');

        opener?.focus?.();
        opener = null;
    };

    document.addEventListener('click', (event) => {
        const target = event.target.closest('[data-zoom]');

        if (! target || viewer.contains(target)) {
            return;
        }

        const source = target.dataset.zoom
            || target.getAttribute('href')
            || target.currentSrc
            || target.getAttribute('src')
            || '';

        // An image that has not got a picture (yet) has nothing to enlarge.
        if (source === '' || source === window.location.href) {
            return;
        }

        // A link keeps working as a plain link without JavaScript only.
        event.preventDefault();

        open(source, target.dataset.zoomCaption || target.getAttribute('alt') || '', target);
    });

    viewer.addEventListener('click', (event) => {
        // Not on the picture, the caption or the save link: the dark area or the X.
        if (event.target === viewer || event.target.closest('[data-viewer-close]')) {
            close();
        }

        // Do not let a drawer or picker behind the viewer take this as an outside click.
        event.stopPropagation();
    });

    // Capture phase, before the modals' own Escape handlers: Escape closes only
    // the viewer and leaves the drawer or modal underneath open.
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            event.stopImmediatePropagation();
            event.preventDefault();
            close();
        }
    }, true);
}
