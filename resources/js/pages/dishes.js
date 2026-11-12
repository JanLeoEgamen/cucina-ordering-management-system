/**
 * Dishes list page.
 *
 * One self contained script: table, drawer wiring, confirmations, requests.
 * The only import is the toast (resources/js/ui/toast.js); everything else the
 * page needs - cell markup, copy, URLs - comes from the Blade view through
 * `data-*` attributes, so nothing is hard coded here.
 *
 * Flow:
 *   #create-dish            -> reset the create form, open its drawer
 *   [data-view-mode]        -> swap the table for the card grid (and back)
 *   [data-row-action=edit]  -> GET the record, fill the edit form, open its drawer
 *   [data-row-action=delete]-> delete form points at the record, open the modal
 *   form submit             -> confirmation modal (copy from the form's data-*)
 *   confirm                 -> send the form, toast, reload; failures keep the drawer open
 */
import DataTable from 'datatables.net-dt';
import { initTableToolbar } from '../ui/table-toolbar';
import { showToast } from '../ui/toast';

const start = () => {
    const tableElement = document.querySelector('#dishes-table');
    const createButton = document.querySelector('#create-dish');
    const createForm = document.querySelector('#create-dish-form');
    const createErrors = document.querySelector('#create-dish-errors');
    const editForm = document.querySelector('#edit-dish-form');
    const editErrors = document.querySelector('#edit-dish-errors');
    const deleteForm = document.querySelector('#delete-modal-form');
    const deleteErrors = document.querySelector('#delete-modal-errors');
    const deleteButton = document.querySelector('#delete-modal-button');
    const viewErrors = document.querySelector('#view-dish-errors');
    const viewImage = document.querySelector('#view-dish-image');
    const viewImageWrapper = document.querySelector('#view-dish-image-wrapper');
    const viewName = document.querySelector('#view-dish-name');
    const viewType = document.querySelector('#view-dish-type');
    const viewPrice = document.querySelector('#view-dish-price');
    const viewCalories = document.querySelector('#view-dish-calories');
    const viewStatus = document.querySelector('#view-dish-status');
    const viewDescription = document.querySelector('#view-dish-description');
    const viewIngredients = document.querySelector('#view-dish-ingredients');
    const confirmTitle = document.querySelector('#save-modal-title');
    const confirmMessage = document.querySelector('#save-modal-message');
    const confirmButton = document.querySelector('#save-modal-button');
    const confirmSubject = document.querySelector('#save-modal-subject');

    if (!tableElement || !createForm || !editForm) {
        console.warn('The dishes page is incomplete; nothing was wired.');

        return;
    }

    /* ---- table ----------------------------------------------------------- */

    /*
     * Cell renderers referenced by the table config below.
     *
     * `rowIndexes` numbers the rows in the current sort and filter order,
     * continuous across pages; the preDraw hook after the table rebuilds it
     * for every draw. escapeHtml (defined in the helpers further down) only
     * runs when a cell renders, which is after this script has set everything
     * up, so referencing it from here is safe.
     */
    let rowIndexes = new Map();

    // Cell styling lives in the renderer, never in columns[].className: that
    // class is applied to the header cell as well, which would tint the labels.
    const indexCell = (data, type, row, meta) => {
        const number = String(rowIndexes.get(meta.row) ?? meta.row + 1);

        return type === 'display' ? '<span class="tabular-nums text-gray-400 dark:text-gray-500">' + number + '</span>' : number;
    };

    const nameCell = (value, type) => (type === 'display'
        ? '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(value) + '</span>'
        : value ?? '');

    // Thumbnail with a placeholder underneath: no URL shows the icon alone,
    // and a broken image removes itself (onerror) so the icon shows through.
    const thumbnailCell = (value, type, row) => {
        if (type !== 'display') {
            return value ?? '';
        }

        const icon = '<svg class="h-5 w-5 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>';
        const image = value
            ? '<img src="' + escapeHtml(value) + '" alt="" data-zoom data-zoom-caption="' + escapeHtml(row?.dish_name ?? '') + '" loading="lazy" class="absolute inset-0 h-full w-full cursor-zoom-in object-cover" onerror="this.remove()">'
            : '';

        return '<span class="relative flex h-10 w-10 items-center justify-center overflow-hidden rounded-lg bg-gray-100 ring-1 ring-inset ring-black/5 dark:bg-gray-700 dark:ring-white/10">' + icon + image + '</span>';
    };

    // Muted chip for the category, so it supports the dish name instead of
    // competing with it the way a second bold column would.
    const typeCell = (value, type) => {
        const types = Array.isArray(value) ? value : [];

        // Sorting and searching work on the joined names, not on the markup.
        if (type !== 'display') {
            return types.map((item) => item.type_name).join(', ');
        }

        if (types.length === 0) {
            return '<span class="text-gray-400 dark:text-gray-500">—</span>';
        }

        return '<span class="inline-flex flex-wrap items-center gap-1">'
            + types.map((item) => '<span class="inline-flex items-center rounded-md border border-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">'
                + escapeHtml(item.type_name) + '</span>').join('')
            + '</span>';
    };

    // Currency with thousands separators; tabular figures line the decimals up.
    const money = (value) => '$' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const priceCell = (value, type) => (type === 'display' ? money(value) : Number(value));

    const table = new DataTable(tableElement, {
        processing: true,
        serverSide: false,
        autoWidth: false,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        // Column 0 (#) is not orderable; the first content column sorts.
        order: [[2, 'asc']],
        // Toolbar: the search keeps the top row, the page length moves down next
        // to the "showing ..." summary, and its label drops the word "entries"
        // so the footer does not say it twice.
        layout: {
            // `null`, not omitted: the layout object is merged over DataTables'
            // defaults, which place the page length in the top row as well.
            topStart: null,
            // The toolbar above the table (resources/js/ui/table-toolbar.js) searches instead.
            topEnd: null,
            bottomStart: ['info', 'pageLength'],
            bottomEnd: 'paging',
        },
        language: {
            // No label next to the box: the placeholder says it (the label is hidden in app.css).
            search: '',
            searchPlaceholder: 'Search…',
            lengthMenu: '_MENU_ per page',
            infoEmpty: 'No rows to show',
            infoFiltered: '(filtered from _MAX_ rows)',
            emptyTable: 'No data available yet',
            zeroRecords: 'No rows match the search and filters',
        },
        ajax: tableElement.dataset.endpoint,
        columns: [
            // Weights and alignment: the row counter and currency are metadata
            // (light, tabular so the digits line up), the dish name carries the
            // row, the category is a chip, and the actions stay right aligned.
            // `hidden sm:table-cell` hides the column on phones: className is
            // applied to the header cell as well, so both disappear together.
            { data: null, orderable: false, searchable: false, className: 'hidden w-12 sm:table-cell', render: indexCell },
            { data: 'image', orderable: false, searchable: false, className: 'w-16 sm:w-20', render: thumbnailCell },
            { data: 'dish_name', render: nameCell },
            { data: 'dish_types', className: 'hidden sm:table-cell', render: typeCell },
            { data: 'price', className: 'tabular-nums text-right', render: priceCell },
            // On phones the badge goes too: an inactive row is already tinted,
            // and keeping the buttons on screen matters more than the pill.
            { data: 'is_active', className: 'hidden sm:table-cell' },
            { data: 'action', orderable: false, searchable: false, className: 'whitespace-nowrap text-right' },
        ],
    });

    // Search, sort and the filter dropdown above the table.
    initTableToolbar(table);

    const reload = () => table.ajax.reload(null, false);

    // Rebuild the display numbers (indexCell above) for the row order the
    // coming draw will show.
    table.on('preDraw', () => {
        rowIndexes = new Map(
            table.rows({ search: 'applied', order: 'applied' }).indexes().toArray()
                .map((index, position) => [index, position + 1]),
        );
    });

    /* ---- filters --------------------------------------------------------- */

    /*
     * The two selects narrow the feed without a request: the rows are already in
     * the browser, so this only decides which of them the table shows. It is a
     * *named* search, so the table's own search box keeps working next to it.
     */
    const statusFilter = document.querySelector('#dish-status-filter');
    const typeFilter = document.querySelector('#dish-type-filter');

    table.search.fixed('filters', (searchData, rowData) => {
        const status = statusFilter?.value ?? '';

        // The server renders the badge, and it carries its state in data-status,
        // so the filter reads the same value the cell shows.
        if (status !== '' && ! String(rowData.is_active ?? '').includes('data-status="' + status + '"')) {
            return false;
        }

        // The type filter is a checkbox list: a dish shows when it has ANY ticked type
        // (nothing ticked = every dish).
        const types = [...(typeFilter?.selectedOptions ?? [])].map((option) => option.value);

        return types.length === 0
            || (rowData.dish_types ?? []).some((dishType) => types.includes(String(dishType.dish_type_id)));
    });

    statusFilter?.addEventListener('change', () => table.draw());
    typeFilter?.addEventListener('change', () => table.draw());

    /* ---- helpers --------------------------------------------------------- */

    const emit = (event, detail) => window.dispatchEvent(new CustomEvent(event, { detail }));

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    }[character]));

    const messages = (payload) => {
        if (payload && payload.errors) {
            const list = Object.values(payload.errors).flat().filter(Boolean);

            if (list.length) {
                return list;
            }
        }

        return payload && typeof payload.message === 'string' ? [payload.message] : [];
    };

    const showErrors = (element, list) => {
        if (!element) {
            return;
        }

        const items = (list ?? []).filter((message) => typeof message === 'string' && message !== '');

        element.innerHTML = items.map((message) => '<div>' + escapeHtml(message) + '</div>').join('');
        element.classList.toggle('hidden', items.length === 0);
    };

    const send = async (form) => {
        try {
            const response = await fetch(form.action, {
                method: 'POST', // the forms carry _method for PUT / DELETE
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: new FormData(form),
            });

            let payload = {};

            try {
                payload = await response.json();
            } catch (error) {
                payload = {};
            }

            return { ok: response.ok, payload };
        } catch (error) {
            console.warn('The request could not be sent.', error);

            return { ok: false, payload: {} };
        }
    };

    // Block a button and show its loading label for as long as a request runs.
    const busy = (button) => {
        const label = button.textContent;

        button.disabled = true;
        button.textContent = button.dataset.loadingLabel ?? label;

        return () => {
            button.disabled = false;
            button.textContent = label;
        };
    };

    // An empty column reads better as a dash than as nothing at all.
    const fillText = (element, value) => {
        if (!element) {
            return;
        }

        element.textContent = value === null || value === undefined || value === '' ? '—' : value;
    };

    const eachField = (form, callback) => {
        for (const field of form.elements) {
            if (!field.name || field.name.startsWith('_')) continue;          // _token, _method
            if (['hidden', 'submit', 'button', 'reset', 'file'].includes(field.type)) continue;

            callback(field);
        }
    };

    const resetForm = (form) => {
        eachField(form, (field) => {
            if (field.type === 'checkbox') {
                field.checked = field.defaultChecked;

                return;
            }

            field.value = '';
        });

        if (form.elements['remove_image']) {
            form.elements['remove_image'].value = '';
        }

        const typePicker = form.querySelector('[data-type-picker]');

        if (typePicker) {
            for (const box of typePicker.querySelectorAll('input[name="dish_types[]"]')) {
                box.checked = false;
            }

            typePicker.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // File inputs can only be cleared (set to ''), never to a filename.
        for (const field of form.elements) {
            if (field.type === 'file') {
                field.value = '';
            }
        }

        // Reset image preview state when the form is reset.
        const preview = form.querySelector('img[id$="-image-preview"]');
        const remove = form.querySelector('button[id$="-remove-image"]');
        const wrapper = form.querySelector('[id$="-image-wrapper"]');
        if (preview) {
            preview.src = '';
            preview.classList.add('hidden');
        }
        if (wrapper) {
            wrapper.classList.add('hidden');
        }

        // Bring the upload content back so the zone shows its default state again.
        const uploadContent = form.querySelector('[id$="-upload-content"]');
        if (uploadContent) {
            uploadContent.classList.remove('hidden');
        }

        const fileInfo = form.querySelector('[id$="-file-info"]');
        if (fileInfo) {
            fileInfo.textContent = fileInfo.dataset.default ?? '';
        }
    };

    const fillForm = (form, record) => {
        // A "remove" clicked for the previous dish must not apply to this one.
        if (form.elements['remove_image']) {
            form.elements['remove_image'].value = '';
        }

        eachField(form, (field) => {
            if (field.type === 'checkbox') {
                field.checked = Boolean(record[field.name]);

                return;
            }

            field.value = record[field.name] ?? '';
        });

        // Dish types: tick the boxes the record has, and hand the picker any
        // type the list no longer offers so saving does not silently drop it.
        const typePicker = form.querySelector('[data-type-picker]');

        if (typePicker) {
            const chosen = (record.dish_types ?? []).map((item) => String(item.dish_type_id));

            for (const item of record.dish_types ?? []) {
                if (! typePicker.querySelector('input[name="dish_types[]"][value="' + item.dish_type_id + '"]')) {
                    typePicker.dispatchEvent(new CustomEvent('add-dish-type', {
                        detail: { id: Number(item.dish_type_id), name: item.type_name },
                        bubbles: true,
                    }));
                }
            }

            for (const box of typePicker.querySelectorAll('input[name="dish_types[]"]')) {
                box.checked = chosen.includes(box.value);
            }

            // One event is enough: the picker re-reads its own boxes on change.
            typePicker.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // Show the stored image on the edit form (file inputs cannot be pre-filled);
        // the upload block hides while it is on show, so the two never stack.
        const imagePreview = form.querySelector('#edit-image-preview');
        const imageWrapper = form.querySelector('#edit-image-wrapper');
        const uploadContent = form.querySelector('[id$="-upload-content"]');
        const fileInfo = form.querySelector('[id$="-file-info"]');

        if (imagePreview) {
            imagePreview.src = record.image ?? '';
            imagePreview.classList.toggle('hidden', !record.image);
        }
        if (imageWrapper) {
            // The preview block, and the X inside it, follow the record.
            imageWrapper.classList.toggle('hidden', !record.image);
        }
        if (uploadContent) {
            uploadContent.classList.toggle('hidden', Boolean(record.image));
        }
        if (fileInfo) {
            fileInfo.textContent = fileInfo.dataset.default ?? '';
        }
    };

    // Read only counterpart of fillForm: the same record, laid out as text.
    const fillView = (record) => {
        fillText(viewName, record.dish_name);
        if (viewType) {
            viewType.innerHTML = typeCell(record.dish_types, 'display');
        }
        fillText(viewPrice, money(record.price));
        fillText(viewCalories, record.calories);
        fillText(viewDescription, record.description);
        fillText(viewIngredients, record.main_ingredients);

        // The badge is rendered server side and travels with the record.
        if (viewStatus) {
            viewStatus.innerHTML = record.status ?? '';
        }

        if (viewImage) {
            viewImage.src = record.image ?? '';
            viewImage.dataset.zoomCaption = record.dish_name ?? '';
        }

        if (viewImageWrapper) {
            viewImageWrapper.classList.toggle('hidden', !record.image);
        }
    };

    /* ---- image drag & drop zone + preview -------------------------------- */

    // Set up the drag & drop upload zone for a given form prefix ('create' | 'edit').
    const setupImageUpload = (prefix) => {
        const zone = document.getElementById(`${prefix}-drop-zone`);
        const input = document.getElementById(`${prefix}-image`);
        const browse = document.getElementById(`${prefix}-browse`);
        const preview = document.getElementById(`${prefix}-image-preview`);
        const remove = document.getElementById(`${prefix}-remove-image`);
        const uploadContent = document.getElementById(`${prefix}-upload-content`);
        const wrapper = document.getElementById(`${prefix}-image-wrapper`);
        const info = document.getElementById(`${prefix}-file-info`);

        if (!zone || !input || !preview) {
            return;
        }

        // The hidden input covers the whole zone, so clicking the zone opens the
        // picker by itself; asking for it again here opened it two or three times
        // per click. Only the Browse button, which sits above the input, forwards.
        const form = zone.closest('form');
        const removeFlag = form?.querySelector('input[name="remove_image"]') ?? null;

        // Kept optional: the zone itself is the click target, the input inside it
        // opens the picker natively and is the keyboard control.
        browse?.addEventListener('click', (event) => {
            event.stopPropagation();
            input.click();
        });

        zone.addEventListener('click', (event) => {
            // Already handled: the input itself, or the remove button.
            if (event.target === input || (remove && remove.contains(event.target))) {
                return;
            }

            input.click();
        });

        // Drag over visual feedback.
        const highlight = () => {
            zone.classList.add('border-indigo-400', 'bg-indigo-50', 'dark:border-indigo-500', 'dark:bg-indigo-900/20');
        };
        const unhighlight = () => {
            zone.classList.remove('border-indigo-400', 'bg-indigo-50', 'dark:border-indigo-500', 'dark:bg-indigo-900/20');
        };

        ['dragenter', 'dragover'].forEach((event) => {
            zone.addEventListener(event, (e) => { e.preventDefault(); e.stopPropagation(); highlight(); });
        });
        ['dragleave', 'drop'].forEach((event) => {
            zone.addEventListener(event, (e) => { e.preventDefault(); e.stopPropagation(); unhighlight(); });
        });

        // Handle dropped files: attach to the hidden input and refresh the preview.
        zone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (!files.length) {
                return;
            }

            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(files[0]);
            input.files = dataTransfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        // Picking a new file cancels a removal that was requested earlier.
        const clearRemoveFlag = () => {
            if (removeFlag) {
                removeFlag.value = '';
            }
        };

        // Read the selected file and show a thumbnail preview.
        const showPreview = (file) => {
            clearRemoveFlag();

            // Nothing picked, or not an image: back to the empty state and the
            // "click the image to replace it" hint.
            if (!file || !file.type.startsWith('image/')) {
                preview.src = '';
                preview.classList.add('hidden');
                if (wrapper) wrapper.classList.add('hidden');
                if (uploadContent) uploadContent.classList.remove('hidden');
                if (info) info.textContent = info.dataset.default ?? '';
                return;
            }

            const reader = new FileReader();
            reader.onload = (event) => {
                preview.src = event.target.result;
                preview.classList.remove('hidden');
                if (wrapper) wrapper.classList.remove('hidden');
                if (uploadContent) uploadContent.classList.add('hidden');
                if (info) info.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
            };
            reader.readAsDataURL(file);
        };

        input.addEventListener('change', () => {
            // An empty picker means "no change", not "remove what is there".
            showPreview(input.files?.[0] ?? null);
        });

        // Remove the selected image.
        if (remove) {
            remove.addEventListener('click', () => {
                input.value = '';
                preview.src = '';
                preview.classList.add('hidden');
                if (wrapper) wrapper.classList.add('hidden');
                if (uploadContent) uploadContent.classList.remove('hidden');

                // Tells the server to clear the column and delete the file.
                if (removeFlag) {
                    removeFlag.value = '1';
                }
                if (info) info.textContent = info.dataset.default ?? '';
            });
        }
    };

    setupImageUpload('create');
    setupImageUpload('edit');

    /* ---- view mode ------------------------------------------------------- */

    /*
     * The switch itself lives in the Blade view; this wires it up. Cards are
     * rendered from the current page of the table's filtered rows, so search,
     * page length and pagination keep working unchanged in both views. The
     * Edit / Delete buttons inside `row.action` already carry `data-row-action`
     * and their URLs, so the delegated listener below serves them from cards
     * exactly as it does from table rows.
     */
    const cardsElement = document.querySelector('#dishes-cards');
    const viewButtons = Array.from(document.querySelectorAll('[data-view-mode]'));
    const VIEW_STORAGE_KEY = 'cucina.viewMode.dishes';

    // Phones start on the card grid: the table cannot breathe in 375px. A view
    // the user picked themselves is remembered and always wins over this default.
    const storedView = (() => {
        try {
            return localStorage.getItem(VIEW_STORAGE_KEY);
        } catch (error) {
            return null;
        }
    })();

    const phoneLayout = window.matchMedia('(max-width: 639px)').matches;

    let viewMode = storedView === 'card' || storedView === 'table'
        ? storedView
        : (phoneLayout ? 'card' : 'table');

    // No empty state until the first payload has actually been drawn.
    let feedLoaded = false;

    const cardTemplate = (dish) => `
        <article class="flex flex-col justify-between overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div data-card-photo class="relative flex h-32 w-full items-center justify-center overflow-hidden bg-gray-100 dark:bg-gray-700">
                <svg class="h-8 w-8 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
                ${dish.image ? `<img src="${escapeHtml(dish.image)}" alt="" data-zoom data-zoom-caption="${escapeHtml(dish.dish_name)}" loading="lazy" class="absolute inset-0 h-full w-full cursor-zoom-in object-cover" onerror="this.remove()">` : ''}
            </div>
            <div class="flex flex-1 flex-col gap-1 p-4">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="truncate text-sm font-semibold text-gray-900 dark:text-white" title="${escapeHtml(dish.dish_name)}">${escapeHtml(dish.dish_name)}</h3>
                    ${dish.is_active ?? ''}
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">${typeCell(dish.dish_types, 'display')}</p>
                <p class="mt-3 text-lg font-semibold tabular-nums text-indigo-600 dark:text-indigo-400">${money(dish.price)}</p>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 px-4 py-3 dark:border-gray-700/60">
                ${dish.action ?? ''}
            </div>
        </article>`;

    const renderCards = () => {
        if (viewMode !== 'card' || !cardsElement || !feedLoaded) {
            return;
        }

        const rows = table.rows({ page: 'current', search: 'applied' }).data().toArray();

        if (rows.length === 0) {
            cardsElement.innerHTML = '<p class="col-span-full py-8 text-center text-sm text-gray-500 dark:text-gray-400">' + escapeHtml(cardsElement.dataset.empty ?? '') + '</p>';

            return;
        }

        cardsElement.innerHTML = rows.map(cardTemplate).join('');
    };

    // `remember` is false for the automatic first paint: only a real click is
    // persisted, so a phone still starts on cards after a desktop preference.
    const applyViewMode = (mode, remember = true) => {
        viewMode = mode === 'card' ? 'card' : 'table';

        const cardMode = viewMode === 'card';

        tableElement.classList.toggle('hidden', cardMode);
        cardsElement?.classList.toggle('hidden', !cardMode);

        viewButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.viewMode === viewMode)));

        if (cardMode) {
            renderCards();
        } else {
            // The columns could not be measured while the table was hidden.
            table.columns.adjust();
        }

        if (!remember) {
            return;
        }

        try {
            localStorage.setItem(VIEW_STORAGE_KEY, viewMode);
        } catch (error) {
            // Storage may be disabled; the choice then only lasts this visit.
        }
    };

    // DataTables wraps the table in `.dt-container`; move the card grid into
    // that container's table row so search and pagination frame both views.
    const tableRow = tableElement.closest('.dt-layout-table');

    if (cardsElement && tableRow) {
        tableRow.append(cardsElement);
    }

    table.on('draw', () => {
        feedLoaded = true;
        renderCards();
    });

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => applyViewMode(button.dataset.viewMode));
    });

    applyViewMode(viewMode, false);

    /* ---- wiring ---------------------------------------------------------- */

    createButton?.addEventListener('click', () => {
        resetForm(createForm);
        showErrors(createErrors, []);
        emit('open-drawer', createForm.dataset.drawer);
    });

    // One click = one action: a button ignores further clicks for this long, so
    // hammering Edit or Delete cannot fire a burst of requests or modal opens.
    const CLICK_COOLDOWN_MS = 600;

    // Rows are injected by DataTables, so the actions are handled by delegation.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-row-action]');

        if (!button || button.disabled) {
            return;
        }

        const now = Date.now();

        if (now < Number(button.dataset.readyAt ?? 0)) {
            return;
        }

        button.dataset.readyAt = String(now + CLICK_COOLDOWN_MS);

        if (button.dataset.rowAction === 'view') {
            // Read only: the record comes from the same endpoint the edit drawer
            // loads, and fillView lays it out instead of filling a form.
            const done = busy(button);

            try {
                const response = await fetch(button.dataset.viewUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));

                    showToast(payload.message ?? button.dataset.loadError, 'error');
                    showErrors(viewErrors, messages(payload));

                    return;
                }

                fillView(await response.json());
                showErrors(viewErrors, []);
                emit('open-drawer', 'view-dish-drawer');
            } finally {
                done();
            }

            return;
        }

        if (button.dataset.rowAction === 'edit') {
            // Labelled and blocked while the record is fetched, so a click cannot
            // queue behind the one already running.
            const done = busy(button);

            try {
                const response = await fetch(button.dataset.editUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });

                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));

                    showToast(payload.message ?? editForm.dataset.loadError, 'error');
                    showErrors(editErrors, messages(payload));

                    return;
                }

                editForm.action = button.dataset.updateUrl;
                fillForm(editForm, await response.json());
                showErrors(editErrors, []);
                emit('open-drawer', editForm.dataset.drawer);
            } finally {
                done();
            }

            return;
        }

        if (button.dataset.rowAction === 'delete') {
            // The confirmation is already up: ignore repeat clicks on the row.
            if (deleteForm.closest('[role="dialog"]')?.getAttribute('aria-hidden') === 'false') {
                return;
            }

            deleteForm.action = button.dataset.deleteUrl;
            showErrors(deleteErrors, []);
            emit('open-modal', 'delete-confirmation-modal');
        }
    });

    // Confirmation before saving: the form waits in `pendingForm`.
    let pendingForm = null;
    let saving = false;

    [createForm, editForm].forEach((form) => {
        const errors = form === createForm ? createErrors : editErrors;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            showErrors(errors, []);
            pendingForm = { form, errors };

            const subject = form.dataset.subjectField
                ? String(form.elements[form.dataset.subjectField]?.value ?? '').trim()
                : '';

            confirmTitle.textContent = form.dataset.confirmTitle ?? confirmTitle.textContent;
            confirmMessage.textContent = form.dataset.confirmMessage ?? confirmMessage.textContent;
            confirmButton.textContent = form.dataset.confirmLabel ?? confirmButton.textContent;
            confirmSubject.textContent = subject;
            confirmSubject.classList.toggle('hidden', subject === '');

            emit('open-modal', 'edit-confirmation-modal');
        });
    });

    confirmButton.addEventListener('click', async () => {
        // A second click while the request is in flight would save twice.
        if (!pendingForm || saving) {
            return;
        }

        const { form, errors } = pendingForm;

        pendingForm = null;
        saving = true;

        const done = busy(confirmButton);

        try {
            const { ok, payload } = await send(form);

            // The confirmation stays up, and busy, until the server answers.
            emit('close-modal', 'edit-confirmation-modal');

            if (!ok) {
                // The drawer stays open with the user's input and the errors.
                showErrors(errors, messages(payload));

                return;
            }

            emit('close-drawer', form.dataset.drawer);
            showToast(payload.message);
            reload();
        } finally {
            saving = false;
            done();
        }
    });

    // Delete (soft delete on the server).
    let deleting = false;

    deleteForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (deleting) {
            return;
        }

        deleting = true;

        const done = busy(deleteButton);

        try {
            const { ok, payload } = await send(deleteForm);

            if (!ok) {
                showErrors(deleteErrors, messages(payload));

                return;
            }

            emit('close-modal', 'delete-confirmation-modal');
            showToast(payload.message);
            reload();
        } finally {
            deleting = false;
            done();
        }
    });
};

// Loaded as a module (deferred), so the DOM is normally already parsed.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
