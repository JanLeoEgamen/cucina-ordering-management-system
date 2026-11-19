# Cucina Ordering Management System

Laravel 12 / PHP 8.2 monolith (Blade + Alpine + Tailwind 3 + Vite) for session-based food ordering: staff publish timed
*order sessions* with limited dish stock; customers order from the live menu and pay cash-on-pickup or bank/e-wallet.
Auth = Breeze; permissions = spatie/laravel-permission. Overview and conventions: @docs/architecture.md

## Working loop (mandatory)

@docs/harness.md

Short form: **understand → plan → implement → verify → sync docs → report.** A task is not done until
`php scripts/harness.php` is green and every doc whose trigger fired (table in harness.md step 5) is updated.
**On every new feature, fix, error resolved, schema/permission change: update the docs automatically, in the same change,
and add a line to `docs/changelog.md`.** Do not wait to be asked.

## Commands

```
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed          # seeds test@example.com / password as Super Admin (dev only)
php artisan storage:link            # needed for uploaded images
composer dev                        # server + queue worker (notifications,default) + pail logs + vite
php artisan test                    # PHPUnit (baseline: 587 passing)
php artisan schedule:work           # optional locally: runs the daily notifications:prune + audit:prune (server: cron `schedule:run`)
php artisan audit:prune [--days=N]  # delete audit trail entries past AUDIT_RETENTION_DAYS (365); 0 keeps all
php scripts/harness.php             # docs-drift check + tests;  --docs = drift only
npm run build                       # must pass after frontend changes
php artisan db:seed --class=PermissionSeeder   # after adding a permission
php artisan users:give-customer-role --dry-run # once on an old database: lists role-less accounts; drop the flag to give them Customer
```

Shell is PowerShell on Windows (Git Bash also available). `php` and `npm` are on PATH.

## Docs map (keep in sync — see harness.md)

@docs/README.md lists them: `docs/api.md`, `docs/security.md`, `docs/database.md`, `docs/frontend.md`,
`docs/architecture.md`, `docs/testing.md`, `docs/known-issues.md`, `docs/changelog.md`.

## Rules that are easy to get wrong

- Models are **static query-builder classes**, not Eloquent relations. Match the existing model you are editing.
- Authorisation is in each controller's `middleware()` (`permission:<name>`). New action ⇒ new middleware entry ⇒ 403 test.
- New permission ⇒ add to `Role::PERMISSIONS`, rerun `PermissionSeeder`, update security.md.
- Reuse `Controller::successResponse()/errorResponse()` (`{status,message}`); validation errors stay Laravel-default 422.
- Escape with `e()` every dynamic value in controller-built HTML. Keep badge class strings under `app/Http/Controllers/**` (Tailwind scans it).
- Colours come from the theme (docs/frontend.md, Theme): primary buttons are `bg-indigo-600 text-white` (it is ink; mist in dark mode), `gray` is the warm paper scale. No colour accent and no hard-coded hex. Motion stays short and must respect reduced motion.
- Dark mode is the `dark` class on `<html>` (a switch, default = OS). Hand-written dark-only CSS must be `html.dark …`, not `@media (prefers-color-scheme)`.
- Soft deletes everywhere: filter `deleted_at IS NULL`. Never edit an applied migration.
- Stock changes only via `SessionDish::reserve()/release()` inside a DB transaction.
- Code style is hand-formatted (`' . '` concatenation). **Do not run Pint repo-wide.**
- Never commit `.env`, real credentials, or uploaded files. Do not commit unless asked.

## Gotchas (append one line per trap you hit)

- `User` is not `MustVerifyEmail`, so the `verified` middleware does nothing.
- Self-registered users (form or Google) get the **Customer** role through `User::giveCustomerRole()` (menu + ordering only). If `PermissionSeeder` has not run the role does not exist and they stay role-less (403 on the menu), so seed before opening registration. Accounts that registered before this change have no role: run `php artisan users:give-customer-role --dry-run`, then without the flag (it skips anyone who already has a role).
- Payment rules depend on the method: Cash on Pick up is verified by completing its order (no manual verify/reject); other methods are verified first, then completed. Cancelling an order releases its stock and is refused while its payment is verified (undo that first). A *rejection* does NOT release the stock: the customer can resend a proof or switch to cash (`my-orders.payment.*`), so the plates stay held until staff cancel.
- `#delete-modal-*` / `#save-modal-*` exist once per page and `dishes.js` owns them: a second page module on the same view (the Manage Types drawer) must not bind them — it confirms deletes inline and tags its buttons `data-type-action`.
- Windows: running `php artisan test` while the dev server is up can fail with "rename(...storage/framework/views/...tmp) Access is denied"; run the tests with `VIEW_COMPILED_PATH` pointing at another folder.
- Users have `first_name` + `last_name`, **no `name` column**: read `$user->name` (accessor) in PHP and use `User::fullNameSql($alias)` in query builder selects; never select `users.name`.
- Order rows are decorated in ONE place, `Controller::orderRow()` (badges, proof URL, action URLs by permission); the session page and the Orders page both use it with the shared `<x-ui.order-popup>` / `order-popup.js`. Do not copy that logic into a page.
- A new table needs `initTableToolbar(table)`, `data-no-export` on its non-data `<th>` (#, Actions, pictures) and `:export-title` on `<x-ui.table-toolbar>`: that is all the Excel / PDF export needs.
- `tests/TestCase.php` comment says 16 permissions; real count is `count(Role::PERMISSIONS)`.
- `PDOException` / `QueryException` **are** `RuntimeException`s: never `catch (RuntimeException)` around a transaction to report a business rule (it shows SQL to the customer). Throw and catch a dedicated exception (`StockRanOutException`).
- Anything that changes an order's state (complete, cancel, verify/reject) must start with `Order::lockedStatus($id)` inside `DB::transaction` and re-check there: checks done before the transaction are racy (a double Cancel gave stock back twice).
- Reserve several dishes in ascending `session_dish_id` order, or two crosswise carts deadlock MySQL. `SessionDish::stockOfSession()` is what the menu polls; a dish missing from it means "sold out".
- **My Orders and My Pre-Orders are paginated (10 a page): never link to an order with `#order-ID` alone**, the card may be on another page. Link `route('my-orders.index', ['order' => $id], false) . '#order-' . $id` (`?order=ID` redirects to the right page; `NotificationController::open` upgrades old `#order-ID` links). A customer's order popup data comes from `Controller::customerOrderPopup()` and must never gain a staff field or an action URL: the popup shows or hides rows by the presence of keys.
- Notifications go through `App\Support\OrderNotifier`, called **after** the event's transaction has committed (never inside it). It pushes ONE queued job (`DeliverNotification`, queue `notifications`) inside `rescue()`; the worker writes everyone told by ONE bulk insert. Do not add a per-recipient query or a per-recipient job. The bell polls (AJAX, ~8 s); there is deliberately no websocket / Reverb. Without a worker (`queue:work --queue=notifications,default`) nobody is notified. A new event = a constant + a method + two texts (customer / staff) + a `NotificationTest` case.
- Windows is case-insensitive: `__('Pagination')` (also `Auth`, `Passwords`, `Validation`) resolves to Laravel's `pagination.php` language file and returns an **array**, which crashes `{{ }}` with "htmlspecialchars(): Argument #1 must be of type string, array given". Never use a label that equals a language file name; it works on Linux, which hides the bug.
- The table is `user_notifications`, not `notifications` (Laravel's own uuid/morph table, which the `User` model's `Notifiable` trait would use). Run `php artisan migrate` after pulling.
- `Job::dispatch()` returns a `PendingDispatch` that only fires in its destructor, i.e. **after** a surrounding `rescue()` has returned: dispatch with `Bus::dispatch(new Job(...))` inside `rescue()` or a push failure escapes into the request.
- Windows: **PHP without OPcache makes every local request 300 ms+ and `artisan serve` (one request at a time) queues parallel ones**, so a table feed shows 1-2 s in DevTools although the endpoint takes ~5 ms. Check `php -m` lists `Zend OPcache`; if not, enable `zend_extension=opcache` + `opcache.enable=1` in the php.ini `php --ini` reports and restart `composer dev`. Profile the app with an in-process request before touching code.
- Windows: a stray lowercase `composer` environment variable (pointing at another profile's `vendor\bin`) makes `composer require` fail with "file_put_contents(...vendor\bin): Permission denied"; `unset composer` / `Remove-Item Env:composer` in that shell.
- `users.email` is unique **including soft deleted rows**: any code that creates a user from an outside email (Google sign-in) must look with `User::withTrashed()` and refuse a deleted account, or the insert throws a duplicate-key error.
- The app's **name and logo are settings** (System Configuration → Branding), not code: print `\App\Models\Setting::appName()` and use `<x-application-logo>`; never type "Cucina" in a view and never use `config('app.name')` (it is `.env`'s "Laravel"; only the email job overwrites it from the setting). `Setting::brand()` must stay non-throwing (error pages call it) and logo uploads must stay raster-only (no SVG: stored XSS).
- A new route behind `auth` must live inside the `['auth', 'profile.complete']` group in `routes/web.php` (or carry that middleware), or a Google sign-up that has not completed its profile can reach it. Tests that need an unfinished user use `User::factory()->profileIncomplete()`; the plain factory user is complete.
- The audit trail is ONE middleware (`AuditTrail`, in the `web` group) plus the auth events: **do not add audit calls to controllers**. A new `{id}` resource needs its route prefix in `config/audit.php` `subjects` (else its entries have no record label / before-after diff), and a readable name in `actions`. Read `config('audit.actions')[$name]`, never `config('audit.actions.' . $name)`: route names contain dots, which config reads as nesting. `audit_logs` is append only: never add an update / delete route or method for it (prune is the only delete). After pulling, run `migrate` and `db:seed --class=PermissionSeeder` (new permission `view audit-logs`).
- The feature once called **bulk orders** is **Pre-orders** (`PreOrder*` models, `pre_*` tables, `pre-order-*` routes / permissions, `PRE-` order numbers). Old changelog entries and the four `create_bulk_*` migrations still say "bulk" on purpose (history / applied migrations); a rename migration (`2026_11_19_210000`) moved the data. Never type "bulk" for it in new code or docs; "bulk insert" (notifications) is unrelated.
- A controller that REFUSES with a redirect must flash an error (`withErrors(...)`), not only a status: the audit trail reads "redirected back with errors" as `failed`, a bare redirect is logged as a success.
- Google Chat copy: same rules as email (`OrderNotifier::copies()` runs AFTER `insertMany()`, inside `rescue()`; `SendGoogleChatMessages` stays `tries = 1`). Only people with `users.google_id` who added the Chat app can be messaged, and `GoogleChat::message()` must never throw. The key is a FILE named in `GOOGLE_CHAT_CREDENTIALS`: never put it in the DB, the repo, a log or a test fixture (the test generates a throwaway one). The `google_id` = Chat user id equivalence is assumed from Google's docs, unverified against a real Workspace.
- Notification emails: `OrderNotifier::email()` must stay AFTER `insertMany()` and inside `rescue()`, and `SendNotificationEmails` must stay `tries = 1`. A throw in either makes `DeliverNotification` retry (bell rows written twice) or mails everyone twice. The switch is `Setting::emailNotifications()` (default off); new notification events get email for free because they build their rows through `mailRow()`.
