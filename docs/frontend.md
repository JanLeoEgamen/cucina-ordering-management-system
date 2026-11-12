# Frontend

Blade + Alpine.js + Tailwind CSS 3 + Vite. DataTables for tables. No Flowbite, no SPA framework.

## Layout

| Path | Purpose |
|---|---|
| `resources/js/app.js` | Entry: Alpine only. Nothing else belongs here. |
| `resources/js/bootstrap.js` | axios + CSRF defaults. |
| `resources/js/ui/toast.js` | Shared toast notifications. |
| `resources/js/pages/*.js` | One self-contained module per page, loaded by that view via `@vite`. DataTables is imported here, so it only downloads on table pages. |
| `resources/views/<resource>/` | `index.blade.php` per CRUD page. |
| `resources/views/components/ui/` | `drawer`, `modal`, `notification`, `toggle`, `checkbox-picker`, `type-picker`, `dish-stock-list` (the order session's dish + stock picker: name search, **dish types (tick several)** and **Offered / Not offered** filters, all in the browser; Select all / Clear act only on the rows still shown; filters reset when a drawer fills or clears the list), `image-viewer` (lightbox, included once in `layouts/app`), `zoomable-image` (an `<img>` that opens in it), `table-toolbar` + `filter-field` (the search / sort / filter bar above every table), `theme-toggle` (the dark / light switch; `labeled` for the sidebar row), `payment-popup` (one payment's details with Verify / Reject / Undo, its reason popup and its own confirmation; used by the Payments page). |
| `resources/js/ui/image-viewer.js` | Loaded on every page via `app.js`. Delegated: any element with `data-zoom` opens in the viewer (QR codes, dish pictures, proof links) — works on DataTables/JS-built markup too. |
| `resources/js/ui/theme.js` | Loaded on every page via `app.js`. Delegated: any element with `data-theme-toggle` flips dark / light, saves it to `localStorage` (`cucina.theme`) and keeps `aria-pressed` in step; follows the OS live until a choice is saved. |
| `resources/js/ui/table-toolbar.js` | `initTableToolbar(table)`: wires `<x-ui.table-toolbar>` to a DataTable (search with a 150 ms pause, sort options, filter badge + clear, dropdowns that close on an outside click / Escape). Imported by the 7 table page scripts. |
| `resources/js/ui/order-popup.js` | `initOrderPopup({ onChange })` → `{ open(order) }`, used by `order-session-show.js` and `orders.js`; fills `<x-ui.order-popup />` (details, session link, inline confirm bar for Complete / Cancel / Verify, Reject with its reason popup) from a feed row (see `Controller::orderRow`), then calls `onChange(kind)` so the page refreshes (the session page reloads after a cancel: stock changes). Also exports `escapeHtml`, `money`, `prettyDateTime`, `post`. |
| `resources/js/ui/payment-popup.js` | `initPaymentPopup({ onChange })` → `{ open(payment) }`, imported by `payments.js`. Fills `<x-ui.payment-popup>` from a payment in the feed-row shape and runs the three decisions. |
| `resources/views/components/` | Breeze components + `delete-confirmation-modal`, `edit-confirmation-modal`, `page-header` (its default slot holds secondary header buttons such as Manage Types; only the `action-id` button is the floating `data-fab`). |

## Page modules

`scripts/harness.php` fails if a file in `resources/js/pages/` is not listed here (write each as `` `file.js` ``).

| Module | View | Notes |
|---|---|---|
| `dish-types-manager.js` | `dishes/` | The **Manage Types** drawer of the Dish Masterlist (no page of its own): a list with Edit / Delete per type and one form that creates or edits (`_method` switched POST ↔ PUT). It does not bind the page's shared `#delete-modal` / `#save-modal` (dishes.js owns them): **every change is confirmed**: Save swaps the form footer for "Create / Save changes to X?" (Cancel / go), and Delete turns the row into a red "Delete the dish type X?" (Cancel / Delete) and its buttons use `data-type-action`, not `data-row-action`. After any change the page reloads once the drawer closes (watched through the panel's `aria-hidden`), so the type picker, the filter and the Types column are current. Loaded only with `view dish-types`. |
| `dishes.js` | `dishes/` | **Dish Masterlist** page: CRUD drawer, image upload/preview/remove, type picker, type filter, read-only view drawer. |
| `payment-methods.js` | `payment-methods/` | CRUD drawer, QR image upload. |
| `payments.js` | `payments/` | Payments table or cards, proof thumbnails; filters (all client side, one named search, so the cards follow): status, **payment method**, **order session** and a **date range** (From / To, the day the payment was made; From can never be later than To — each box limits the other, and a typed-in bad date is pulled back with a warning toast) plus a Clear link — the method and session lists are built from the payments the page holds; **View** opens a popup (modal, filled from the row: proof zoom, lines, rejection reason) with **Verify**, **Reject** and **Undo** inside. Reject opens its own popup that requires the reason; Verify and Undo use the shared confirmation. Delete (confirmation). |
| `order-sessions.js` | `order-sessions/` | CRUD drawer with per-dish stock, "End" action. View is a plain link to the session page. Three views (Table / Card / **Calendar**, remembered in `localStorage` `cucina.viewMode.orderSessions`): the calendar is a Sunday-first month grid built from the table's filtered rows (search and status filter apply), a chip per session coloured by status (read from the badge's `data-status`) and linking to its page (the View link of the row); prev / next / Today; the paging controls are hidden while it shows. |
| `orders.js` | `orders/` | **Orders** page: every order of every session as table or cards (remembered in `localStorage` `cucina.viewMode.orders`); filters (client side, one named search): order status, payment status, payment method and order session (built from the orders the page holds) and a placed-date range (From can never be later than To); View opens the shared **order popup**, so an order is completed, cancelled, or its payment verified / rejected from here as on the session page. |
| `order-session-show.js` | `order-sessions/show` | Session page: orders as DataTable or cards (order + payment status filters), an Actions column (View opens a read-only order details modal: customer, payment, proof, lines), proof links open in the image viewer, the order details have **Cancel Order** (reloads the page, as the stock changes), **Complete Order** (cash: verifies on completion; others: needs a verified payment — a hint line explains which), and **Verify** / **Reject** for a pending non-cash payment (Verify: the page's confirmation modal; Reject: its own popup asking for the reason; each shown only with its permission), "End Session" with confirmation. **Complete Order, Cancel Order and Verify Payment are confirmed inline**: the details' footer is swapped for a confirm bar (message, **Back**, coloured go button) instead of opening the shared confirmation modal on top of the details; only End Session (and Verify elsewhere) still use that modal, and Reject keeps its reason popup. |
| `menu.js` | `menu/` | Card stepper = cart quantity (starts 0, "Added to cart" indicator, no Add button), round always-visible cart, checkout (expandable payment-method list: cash on pickup jumps to Confirm; a bank/e-wallet row opens QR + details + Save QR + reference + proof under it), proof upload, live stock refresh from `POST /orders` response. |
| `roles.js` | `roles/` | CRUD drawer with permission checkboxes. |
| `users.js` | `users/` | CRUD drawer with role picker. |
| `settings.js` | `settings/` | **System Configuration**: one form for all settings (a card per group; today the default order session start / end time). Save asks the page's shared confirmation modal, then PUTs the form and toasts; a validation error stays on the page. The create order session drawer reads the saved times from `data-default-start` / `data-default-end` on its form (`order-sessions.js`) when it opens. |

`my-orders/` is a server-rendered card list with no module.

## Conventions

- **CRUD pattern**: page = table + drawer. Row buttons carry `data-row-action`, `data-*-url` attributes built by the controller; the module reads them. Edit loads JSON from `GET .../{id}/edit`, submit goes to `PUT`/`POST`, responses are `{status, message}` → toast.
- **Controller-built HTML**: badges and row buttons are generated in PHP (`Controller::statusBadge`, `sessionBadge`, `orderStatusBadge`, `paymentStatusBadge`). Tailwind only keeps those class names because `tailwind.config.js` scans `app/Http/Controllers/**/*.php` — do not move the class strings out of scanned paths.
- **Enlargeable images**: put `data-zoom` (+ `data-zoom-caption`) on an `<img>`, or on a proof/receipt `<a href>` (it keeps working as a link), or use `<x-ui.zoomable-image>`. Do not add `data-zoom` where a click already does something (menu dish cards open the dish modal; upload drop zones open the file picker). Escape closes only the viewer.
- **Dark mode** classes (`dark:`) are required on every new colour.
- Strings are wrapped in `__()` / `e()`.
- Buttons render only if the user has the permission (UI hint; the route middleware is the real gate — see security.md).
- Nav lives in `resources/views/layouts/navigation.blade.php`; add a link there for every new page, guarded by `@can`, inside its group: **Ordering** (Menu, My Orders), **Service** (Order Sessions, Payments), **Catalog** (Dish Masterlist, Payment Methods), **Access** (Roles, Users); Dashboard sits above them. A group is dropped when none of its links is visible; the collapsed rail shows a thin rule instead of the title.

## Theme

A **warm gray** look. The tokens come from tokenharbor.ai (read from its stylesheet): a warm paper page and near-black ink. There is deliberately **no colour accent**: the emphasis is the contrast between ink and paper. Layout and shapes follow the two reference screens the redesign started from: a floating rounded sidebar with an ink profile card, big rounded dish cards with inset photos, pill search and chips, round +/− steppers.

| Token | Light | Dark | Tailwind |
|---|---|---|---|
| Page (paper) | `#f7f6f2` | `#141413` | `gray-50` / `gray-900` |
| Cards | white | `#23221e` | `bg-white` / `dark:bg-gray-800` |
| Ink (text, primary buttons) | `#17170f` | — | `ink`, `indigo-600` |
| Mist (ink's counterpart in dark) | — | `#e8e6e1` | `mist` (`mist-deep` `#d8d5cc`) |
| Muted text | `#6b6a64` | `#9a9793` | `gray-500` / `gray-400` |
| Hairline borders | ink at ~10% | white at ~9% | `gray-200` / `gray-700` |

**How it is applied.** `tailwind.config.js` *remaps* two scales instead of renaming them, so the existing classes pick the theme up unchanged: `gray` is warm neutrals, and `indigo` is the **brand**, which here is ink and grays (600 = ink, 300/400 = the light grays that carry text and rings on the dark page, 50/100 = hover and active washes). Dark mode is the **`dark` class on `<html>`** (`darkMode: 'class'` in `tailwind.config.js`): `layouts/theme-script` sets it before first paint from the saved choice (`localStorage` `cucina.theme`: `dark` / `light`) or, until someone has chosen, from the OS setting; the switch (`x-ui.theme-toggle`, `resources/js/ui/theme.js`) flips it, remembers the choice and eases the colours over 0.3 s. The switch is a labelled row in the sidebar (above Profile), a round icon in the phone's top bar, and a round icon on the login pages. Until the switch is used the page still follows the OS live. An ink button would vanish on the dark page, so `resources/css/app.css` turns every `bg-indigo-600 text-white` into `mist` with ink text there (also the pager's current page, the checkout progress bar, checkboxes and toggles).

**Rules for new UI**
- Dark-only CSS that you write by hand in `app.css` must be scoped `html.dark …` (never `@media (prefers-color-scheme: dark)`: that would ignore the switch). `dark:` utilities already work.
- Primary action: `bg-indigo-600 text-white` (ink; mist in dark). Do not hard-code hex colours; use `paper`, `ink`, `mist`, or the remapped scales.
- **Every action has a border.** Ghost and text-style buttons are outlined pills: row actions (View grey, Edit darker grey, Delete red, End amber, with `mx-0.5` between them), Clear filters, the round close buttons, the stepper `−`, sidebar Profile / Log Out. Solid ink buttons are their own edge, and the paired pager / filter controls already carry borders. A new button must not be borderless text.
- Keep **one solid highlight** per card or row (the card `+`, the "Added to cart" chip, the active nav pill) so the eye finds it: `bg-gray-900 text-white dark:bg-mist dark:text-ink`.
- Cards: `rounded-2xl bg-white shadow-card` (hover: `shadow-card-lift`), no heavy borders. Controls that stand alone (search, chips, steppers) are pills (`rounded-full`).
- Active navigation: ink pill, white text (`dark:bg-mist dark:text-ink`).
- **Add buttons** (`x-page-header`, `data-fab`): on a phone a round floating plus in the lower right (like the menu's cart; the page gets bottom padding so it never covers the last row); from `sm` it sits in the page header, upper right, as a rounded button with its label. Keep the `fixed ... sm:static` pair when editing it.
- Page titles sit directly on the paper (no header bar): `text-2xl font-bold tracking-tight`.

**Admin pages** share the same shapes, mostly through a few class strings defined once per page (`$th`, `$input`, `$submitButton`, `$cancelButton`, `$filter`) and `app.css`:
- **Tables** (DataTables) are **row cards**, modelled on tokenharbor's model table: one soft header band (small-caps labels), and every row its own rounded, hairline-bordered card with a gap to the next. A table cannot round a `<tr>`, so `app.css` sets `border-collapse: separate` + `border-spacing`, puts the top / bottom border on every cell and the corners + side borders on the first / last cell; `<tbody>` backgrounds are forced transparent. Hover lights the whole row; an inactive row is muted. The search box is a pill with a magnifier inside and a `Search…` placeholder (`language.searchPlaceholder` in each page script; the label is hidden). Round pager buttons, a calm centred empty message (`td.dt-empty`). Phones start on the card view.
- **Table toolbar** (`<x-ui.table-toolbar>`, `resources/js/ui/table-toolbar.js`): the same bar on every module with a table. Left: the view tabs (Table / Card / Calendar, or none). Right: one rounded bar with **search**, a **Sort** dropdown and a **Filter** dropdown, exactly like the menu's. A page declares it in its view — `<x-ui.table-toolbar :views="['table','card']" :sorts="[[__('Name: A to Z'), 2, 'asc'], ...]">` with the filter fields as the slot (each wrapped in `<x-ui.filter-field :label="...">`; an empty slot draws no Filter button) — and calls `initTableToolbar(table)` right after creating its DataTable with `layout.topEnd: null` (DataTables' own search box is off; the toolbar's field calls `table.search()`). A sort option is `[label, column index, direction]`; the label follows the table when a header is clicked. The controls in the slot **keep their ids and their own listeners**: the helper only counts how many are away from their default (the badge) and offers *Clear filters*, which resets them and fires `change` so the page reacts (payments.js's date-range rule included). The cards and the calendar are built from the filtered rows, so they follow search, sort and filters.
  **On a phone** (modelled on the reference's mobile view) the bar stays **one row**: the search box shrinks, Sort and Filter become compact buttons after hairlines (the sort choice is clipped and hidden below 400 px, leaving the icon), and a dropdown spans the **whole bar** — the bar is its positioning parent (`absolute inset-x-0`), so it cannot run off the screen; from `sm` it hangs under its own button (`sm:relative` cell, `sm:w-80`). The menu's own bar works the same way. Card lists (`[id$='-cards']`) turn into **flat rows** below `sm`: no box, shadow or inner rules, one hairline between rows, inside the page's white card. A card with a picture (dish photo, payment-method QR) marks it `data-card-photo`: on a phone that picture becomes a 4.5 rem rounded **thumbnail on the left** with the details beside it and the actions underneath (a full-width banner above every row buried the text); from `sm` the boxed card with the big photo is unchanged. A new card template with a picture must carry `data-card-photo` on its picture block.
- **Forms** (`$input`): tinted field (`bg-gray-50`), `rounded-xl`, ink focus border with a soft gray ring; buttons are pills (`$submitButton` ink, `$cancelButton` outlined). The Breeze components (`x-primary-button`, `x-secondary-button`, `x-danger-button`, `x-text-input`) follow the same shapes; the login card is a rounded card on the paper.
- **Drawers and modals:** rounded outer corners (`sm:rounded-l-3xl` on the drawer, `rounded-2xl` on the modal), a lighter backdrop, hairline header / footer rules, round close buttons.
- **Status badges** (`Controller::badge`): one quiet gray pill for every status. The state is a small dot in front of the label, drawn by `app.css` from `data-status` (green: active / live / verified / completed; amber: pending / scheduled; red: inactive / rejected / cancelled; gray: ended). A new status is one CSS line; `live` also breathes. Row actions (View / Edit / Delete) are small pills.
- **Checkout errors must be seen** (the body of the modal scrolls, so a message at its top is easily out of sight): `showErrors` in `menu.js` shows each message as a **toast** (always on screen) *and* in the banner, which is `sticky top-0` so it stays pinned while scrolling; for a missing proof, `flagField` also scrolls the upload box to the middle and gives it a short shake (`animate-shake`) and a red ring. Any new checkout validation message goes through `showErrors`.
- **Checkout** (the menu modal): bigger title and total, pill buttons, tinted cart lines with a round stepper, payment tiles as `rounded-2xl` with an ink outline when picked, tinted summary cards on the confirm step.
- **Dashboard:** a greeting, the live session (ink card, with the window, dish and order counts and links), the pending-payment count (only with `view payments`), and a "Jump to" grid of tiles for exactly the places the user may open.

**The menu bar** is one rounded bar (like tokenharbor's model search): the search box, then a **Sort** dropdown and a **Filter** dropdown (popovers: `[data-popover]`, closed by an outside click or Escape). Sort: Default (the server's order), Price low→high / high→low, Name A→Z, Calories low→high, Most available. Filter: *In stock only*, *In my cart* (follows the cart live), price from / to, calories from / to; the badge on Filter counts those, and *Clear filters* resets them with the search box and the category. The category chips under the bar stay the quick single-select category filter. All of it runs on the dishes already on the page (`menu.js`: `shows`, `applySort`), so nothing asks the server. On a phone the search box takes the first row and Sort / Filter share the second.

**Menu dish cards are photo-first.** The photo is the card: it fills the top edge to edge (`aspect-[5/4]` on a phone, square from 520 px), zooms slowly on hover (`group-hover:scale-110`), and the text sits on a black gradient over it so white type always reads: glass category chips top left (two, then `+N`), a frosted price pill top right, the name (two lines max) and the stock line at the bottom. The stock line has a dot in front (`data-level` = `ok` green / `low` amber, 3 or fewer / `out` red); a sold-out dish turns grey with a "Sold out" label (`data-sold-out` on the card, `group-data-[sold-out=true]` variants). `menu.js` `setStock` keeps both attributes in step when stock changes. The +/− stepper sits in a strip under the photo. The dish popup's photo is taller (`h-56 sm:h-72`) to match. No photo (or a broken one): a soft gradient with an image icon.

**Motion.** Short and soft, defined once in `tailwind.config.js` (keyframes) and `app.css`:
- `animate-fade-up` — every page's `<main>` slides up as it arrives; menu cards and the search bar do the same, staggered 45 ms apart (the first 12).
- `animate-fade-in` / `animate-pop` — the image viewer opens with a fade and a small pop.
- Cards lift on hover (`hover:-translate-y-0.5`, `shadow-card-lift`); buttons and links with the `transition` utility sink slightly while pressed; sidebar icons lean toward the label under the pointer; table rows fade their hover background.
- **Add to cart:** the round cart bounces (`animate-cart-bump`) and a round copy of the dish's photo flies from its card to the cart (`flyToCart` in `menu.js`, Web Animations API; skipped when it cannot be measured).
- A **live** session badge shows a small breathing dot (`animate-live-dot`, from `span[data-status=live]::before`).
- Everything is switched off by the `prefers-reduced-motion` block in `app.css`. Fill mode is `backwards`, so a finished entrance never freezes out a hover transform.

**Mobile.** Touch targets on the menu are 44 px (`h-11 w-11`); buttons inside cards are at least 40 px; form controls are 16 px below `sm` (iOS zooms the page on anything smaller); the dish grid is one column under 520 px, then two, three, four; the round cart clears the phone's home bar (`env(safe-area-inset-bottom)`).

The sidebar's collapse / expand button is a small round **handle on the sidebar's right edge, halfway down** (half outside): in the middle it cannot meet the profile card or the account links, expanded or in the 5rem icon rail. It is centred with a negative margin, not a transform, because the press effect in `app.css` sets `transform` on pressed buttons. The sidebar profile card shows the user's **initial** in a light circle plus their role; the external avatar image (`ui-avatars.com`) was removed, so a user's name is no longer sent to a third party.
## Build & dev

```
npm install
npm run dev     # vite
npm run build   # production bundle -> public/build (git-ignored)
```

## When you change something

| You change... | Also update |
|---|---|
| Add a page | `resources/js/pages/<name>.js`, view, nav link, `vite.config.js` input, the table above, api.md |
| Add controller-built markup with new classes | confirm the path is in `tailwind.config.js` `content` |

**Action buttons.** Solid rounded rectangles (`rounded-xl`, soft shadow, light weight, small icon + label). Row actions are styled by `button[data-row-action=edit|view|delete|end]` in app.css (icon is a CSS mask), not by the class strings in the controllers; new row actions need a `data-row-action` value and a rule there.

**Dropdowns.** **Every** `<select>` in the app is enhanced by `resources/js/ui/select-menu.js` (`autoEnhanceSelects()` from `app.js`, watching for selects added later: drawers, the DataTables page-length box): a styled button + listbox over the hidden native `<select>`, in the look of the toolbar's sort menu (rounded card, roomy rows, chosen row shaded and bold with a check, lighter shade under the pointer). The select still holds the value and fires `change`. Keep writing a plain `<select id=...>` in a page; do not listen to the custom button. Options are picked up automatically; Alpine `x-model` writes the value without an event, so dispatch a `change` after setting it from script. Looks: `data-menu-size="sm"` (smaller button), `data-menu-placement="top"` (opens upward; automatic in `.dt-length`).

**Dish type filters are checkbox lists.** A `<select multiple data-placeholder="All dish types" data-count-label="{n} dish types">` is drawn as checkboxes that stay open while ticking; the button shows the placeholder, one or two names, or the count; the selected options are the value (`select.selectedOptions`) and a dish matches when it has ANY ticked type. Used by the Dish Masterlist filter (`#dish-type-filter`, read in `dishes.js`) and the order session dish list (`types[]`); the menu's category chips are checkboxes too (several on, "All" clears). The toolbar's badge and Clear filters understand multi selects.
