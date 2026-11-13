# Testing

```
php artisan test                      # all (baseline 2026-11-13: 448 passed)
php artisan test --filter=DishTest    # one class
php scripts/harness.php               # docs drift check + full suite
```

- PHPUnit 11, SQLite in-memory (`phpunit.xml`), `RefreshDatabase` per class.
- Helpers in `tests/TestCase.php`:
  - `superAdmin()` — user with every permission (seeds `PermissionSeeder`).
  - `userWith('view dishes', ...)` — user with exactly those permissions; use it for **403** cases.
- Factories exist for every model in `database/factories/`.

## Coverage today

| Area | Tests |
|---|---|
| Dishes (CRUD, uploads, query builder) | `DishTest`, `DishQueryBuilderTest` |
| Menu seeder (CSVs loaded, every type seeded, multi-type dishes, old `A / B` type split, idempotent, keeps edits) | `MenuSeederTest` |
| Full `DatabaseSeeder` (what `migrate:fresh --seed` leaves: Jan Leo Egamen as Super Admin with every permission, roles, dishes, dish types, re-run changes nothing) | `DatabaseSeederTest` |
| User names (first / last, required, full name in feeds and joins, the migration's split) | `UserNameTest` |
| User company (optional; registration, Users page create / edit / feed, profile, 255 limit) | `UserCompanyTest` |
| Dish types (CRUD, feed, 403s, redirect of the old page, Manage Types drawer on the masterlist) | `DishTypeTest` |
| Payment methods (protected Cash on Pick up, 403) | `PaymentMethodTest` |
| Image viewer present; drawer images zoomable | `ImageViewerTest` |
| Theme shell: dark / light switch (top right of both bars, login) + before-paint script, the account menu's identity card (initials + name + role, once, none in the sidebar, no external avatar), ink active pill, dashboard tiles by permission + pending count + live session, status badges are one gray pill with `data-status` | `ThemeShellTest` |
| Menu bar: search, sort options, every filter control | `MenuCheckoutTest` |
| Table toolbar on every module: one search field, the module's sort choices, a Filter dropdown only where there are filters, the view tabs; filters keep their ids | `TableToolbarTest` |
| Menu dish cards: photo-first markup, stock level dots, sold-out flag | `MenuCheckoutTest` |
| Checkout: the error banner is pinned (sticky, assertive) and the proof drop zone exists for the script to flag | `MenuCheckoutTest` |
| Order session page: 403/404, orders feed scoped to the session, list links to it, Verify/Reject buttons and decision URLs only with the permissions, pending payments only | `OrderSessionShowTest` |
| Order sessions list: Table / Card / Calendar switch + calendar markup, rows carry what the calendar reads (date, times, status badge, View link) | `OrderSessionIndexTest` |
| Placing orders: proof required unless cash on pickup | `OrderProofTest` |
| Error pages: each of the 12 listed statuses (401 … 504) shows its own title, the status number, the theme (before-paint script, switch, paper / dark classes) and not the framework's grey page; 418 / 507 fall back to the generic 4xx / 5xx; "Try again" only where a retry can help; Go to dashboard vs Back to home; a real missing URL and a real 403 (no Spatie text); the app's own 403 sentence; an uncaught exception in production shows the 500 page without its SQL message; AJAX still gets JSON | `ErrorPagesTest` |
| Concurrent ordering + live stock: stock feed (403, numbers, no-store, ended session, turned-off dish), last plate to one customer, refusal names the dish and carries the real stock, per-customer lock (409) and release, idempotency key replay, crosswise multi-dish orders, all-or-nothing multi-dish failure, proof deleted on refusal, `reserve` refuses a switched-off dish, inactive payment method refused, cent rounding, 10/min throttle, double Cancel releases once. SQLite cannot run two requests in parallel, so the guards are tested, not the interleaving itself | `OrderConcurrencyTest` |
| Price changes during checkout: the stock feed carries current prices (none for a turned-off dish or a closed session), an order at the seen price is placed, a changed price refuses it with the new price and leaves no order, stock or proof behind, one changed line refuses the cart, a dish outside the cart does not matter, cents compare equal, retry with the new price works under the same idempotency key, no `expected_prices` = unchecked, non-numeric refused, price pill hooks in the menu | `OrderPriceChangeTest` |
| Editing a live session's stock: unchanged box keeps orders placed meanwhile, adding / lowering applies only the change, clamps at 0, `reserved_stock` untouched, no loaded value = typed number, new / put-back dish, negative loaded refused, create ignores it, drawer markup carries `loaded_stocks[]` | `OrderSessionStockEditTest` |
| Completing an order: cash verifies on completion, bank needs a verified payment, cash cannot be verified/rejected by hand, no undo after completion, feed buttons per method | `OrderCompleteTest` |
| Cancelling an order: releases stock once, rejects a pending payment, blocked by a verified payment until undone, pending orders only, cancelled order's payment frozen, feed + button per permission | `OrderCancelTest` |
| Payments page: permissions, row buttons + decision URLs, verify, reject (reason required), undo (clears reviewer + reason, mirrors the order), delete rules, customer sees the reason, nav link, filter controls + the fields the filters read | `PaymentTest` |
| Fixing a rejected payment: new proof (back to pending, reviewer / reason cleared, old picture deleted, reference kept or replaced, plates untouched, image required), switch to cash (pending cash payment, no proof, then staff complete it), cash unavailable / inactive refused, only a pending order with a rejected payment, double tap, cancelled / completed refused, owner-only (404), `create orders` (403), refused upload not kept, the My Orders panel per state and permission | `RejectedPaymentFixTest` |
| Notifications: every event (placed, verified, rejected + reason, completed, cancelled, new proof, switched to cash, sold out) for the customer and the watchers, own-action exclusion, customer-also-watcher once, deleted watcher, replay does not notify twice, a failing notification never fails the order, one INSERT for everyone, the feed (count + newest id, only newer, own rows only, no-store, `recent=1` gives the latest 6 newest first with read state and the poll never reads the list), the top right corner (bell panel + account menu with Profile / Log Out once per bar, none left in the sidebar), the page (own, newest first, numbered pages with "Showing x–y of z", total counts only my rows, no controls for a single page, a page past the end redirects to the last, empty), open (marks read once, local links only, 404 for others), read-all, guests, the bell markup, order cards scroll targets; the watchers setting (page, save / change / empty, absent key, unknown or deleted user, 403 without `edit settings`, damaged value, takes effect at once); prune command + schedule; the three indexes | `NotificationTest` |
| Email copy of notifications: off by default (bell still works), on = customer and watcher mailed in their own words with an absolute link, the email says exactly what the bell says, own action not mailed, sold out to watchers only, a mail failure never fails the order or duplicates the bell rows, one bad address does not stop the rest, not retried, one extra job on the notifications queue, the switch (needs `edit settings`, stored, left alone when omitted, validated), the log-mailer warning | `NotificationEmailTest` |
| Notification queue: placing an order pushes ONE job on the `notifications` queue and writes nothing itself, the sold-out job only when a dish reached zero, queue name from config, running the job writes the rows, 3 tries + backoff + the `failed()` log, a failing job never fails the order, a failed sold-out run can be retried. The test queue is `sync` (jobs run inside the request) | `NotificationQueueTest` |
| Auth / profile (Breeze) | `tests/Feature/Auth/*`, `ProfileTest` |
| Customer role on sign-up (form and Google; adds without replacing, idempotent, works before the roles are seeded) | `Auth/RegistrationTest`, `Auth/GoogleAuthTest` |
| Complete-profile gate (redirect from gated pages, 403 for AJAX, prefilled form, required company, no overwrite, logout allowed, register / admin / migration set it) | `Auth/CompleteProfileTest` |
| Google sign-in (off by default, button, redirect, create / link / returning user, refusals: unverified, deleted, other Google id, failed exchange; Google is mocked through Socialite's `Provider`) | `Auth/GoogleAuthTest` |
| Landing page and signed-out pages (session status, payment methods, links, brand panel, the dish collage on the sign-in panel and the landing hero: available dishes only, no prices, photo spread, plain fallback) | `WelcomeTest` |
| Export menu + `data-no-export` / title on every table toolbar | `TableExportTest` |
| System configuration (defaults, permissions, validation, the create session drawer starts from the saved times, the bulk order minimum / maximum: saved, cleared, validated, damaged values, the unsaved-change indicator's markup for editors only) | `SettingTest` |
| Orders page (permission, feed across sessions, per-user action URLs, shared popup, nav / dashboard link) | `OrdersPageTest` |
| Bulk orders data layer: session status + live + end once, overlap guard (back to back is fine, ended sessions ignored, editing ignores itself), dish list replace / restore, menu + prices only for dishes on offer, own `BLK-` order numbers, order feeds with lines, payment decision stamped / cleared / resubmitted, locked state, permissions seeded + the Customer role's three | `BulkModelsTest` |
| Bulk sessions page (staff): guests, a 403 per action, page + drawers (minimum field, no stock boxes), feed (counts, status, escaped title, buttons per permission, End only for the creator), show page (facts, dishes, no stock column, End button), create / title optional, validation (minimum, dishes, times, date, title length), overlap (back to back and other days fine, ended sessions ignored, edit keeps its own window but cannot move onto another), update replaces dishes + 404s, edit JSON, End (creator only, once), delete (refused with orders), nav + dashboard link by permission, order sessions unaffected | `BulkSessionTest` |
| Bulk ordering (customer): guests, a 403 per page / the order (seeing the menu is not ordering), menu empty states, menu lists the live session's dishes with its minimum and no stock, off-menu dishes hidden, regular and bulk menus never share a session, cash order placed with the right number / totals / lines and nothing written to the regular tables, placed order keeps its price, mixed dishes + cents, minimum (below refused with nothing left behind incl. the stored picture, exactly the minimum, a minimum raised meanwhile), not-running sessions (ended / scheduled / ended by hand / unknown), foreign or removed dishes, price change (carries the new price, cents equal, no expected price = unchecked), bank order proof required + stored with the reference, proof must be a small image, inactive method, items validation (0, negative, >10000), idempotent retry, same key from another customer, 409 lock + release, a regular lock does not block bulk and a regular receipt never answers a bulk key, `BLK-` numbering, 10/min throttle, My Bulk Orders (own rows, newest first, lines, rejection reason, anchors, empty), not shown in My Orders / Orders / Payments, nav + dashboard links by permission, Customer role works out of the box | `BulkCheckoutTest` |
| Users, Roles, Order sessions, Orders/menu/my-orders | **none** (see known-issues.md) |

## Rules for new work

1. Every new endpoint gets: happy path, validation failure, 404, and a **403** test (`userWith()` without the permission).
2. Business rules get a test each (protected role/method, creator-only end, delete-with-orders refused, stock never negative / oversold).
3. Money and stock assertions check DB state, not just the HTTP status.
4. Use `Storage::fake('public')` for uploads.
5. Do not run `pint` repo-wide (see architecture.md, convention 9). `.phpunit.result.cache` is local noise.
6. Known stale comment: `tests/TestCase.php` says "16 permissions"; the real count is `count(Role::PERMISSIONS)` (27).
