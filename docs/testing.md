# Testing

```
php artisan test                      # all (baseline 2026-11-12: 169 passed)
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
| Menu seeder (CSV loaded, idempotent, keeps edits) | `MenuSeederTest` |
| User names (first / last, required, full name in feeds and joins, the migration's split) | `UserNameTest` |
| Dish types (CRUD, feed, 403s, redirect of the old page, Manage Types drawer on the masterlist) | `DishTypeTest` |
| Payment methods (protected Cash on Pick up, 403) | `PaymentMethodTest` |
| Image viewer present; drawer images zoomable | `ImageViewerTest` |
| Theme shell: dark / light switch (sidebar, phone bar, login) + before-paint script, sidebar profile card (initial + role, no external avatar), ink active pill, dashboard tiles by permission + pending count + live session, status badges are one gray pill with `data-status` | `ThemeShellTest` |
| Menu bar: search, sort options, every filter control | `MenuCheckoutTest` |
| Table toolbar on every module: one search field, the module's sort choices, a Filter dropdown only where there are filters, the view tabs; filters keep their ids | `TableToolbarTest` |
| Menu dish cards: photo-first markup, stock level dots, sold-out flag | `MenuCheckoutTest` |
| Checkout: the error banner is pinned (sticky, assertive) and the proof drop zone exists for the script to flag | `MenuCheckoutTest` |
| Order session page: 403/404, orders feed scoped to the session, list links to it, Verify/Reject buttons and decision URLs only with the permissions, pending payments only | `OrderSessionShowTest` |
| Order sessions list: Table / Card / Calendar switch + calendar markup, rows carry what the calendar reads (date, times, status badge, View link) | `OrderSessionIndexTest` |
| Placing orders: proof required unless cash on pickup | `OrderProofTest` |
| Completing an order: cash verifies on completion, bank needs a verified payment, cash cannot be verified/rejected by hand, no undo after completion, feed buttons per method | `OrderCompleteTest` |
| Cancelling an order: releases stock once, rejects a pending payment, blocked by a verified payment until undone, pending orders only, cancelled order's payment frozen, feed + button per permission | `OrderCancelTest` |
| Payments page: permissions, row buttons + decision URLs, verify, reject (reason required), undo (clears reviewer + reason, mirrors the order), delete rules, customer sees the reason, nav link, filter controls + the fields the filters read | `PaymentTest` |
| Auth / profile (Breeze) | `tests/Feature/Auth/*`, `ProfileTest` |
| System configuration (defaults, permissions, validation, the create session drawer starts from the saved times) | `SettingTest` |
| Orders page (permission, feed across sessions, per-user action URLs, shared popup, nav / dashboard link) | `OrdersPageTest` |
| Users, Roles, Order sessions, Orders/menu/my-orders | **none** (see known-issues.md) |

## Rules for new work

1. Every new endpoint gets: happy path, validation failure, 404, and a **403** test (`userWith()` without the permission).
2. Business rules get a test each (protected role/method, creator-only end, delete-with-orders refused, stock never negative / oversold).
3. Money and stock assertions check DB state, not just the HTTP status.
4. Use `Storage::fake('public')` for uploads.
5. Do not run `pint` repo-wide (see architecture.md, convention 9). `.phpunit.result.cache` is local noise.
6. Known stale comment: `tests/TestCase.php` says "16 permissions"; the real count is `count(Role::PERMISSIONS)` (27).
