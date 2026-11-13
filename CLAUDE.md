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
php artisan test                    # PHPUnit (baseline: 409 passing)
php artisan schedule:work           # optional locally: runs the daily notifications:prune (server: cron `schedule:run`)
php scripts/harness.php             # docs-drift check + tests;  --docs = drift only
npm run build                       # must pass after frontend changes
php artisan db:seed --class=PermissionSeeder   # after adding a permission
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
- Registered users have no role → they get 403 on the menu until an admin assigns one.
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
- Notifications go through `App\Support\OrderNotifier`, called **after** the event's transaction has committed (never inside it). It pushes ONE queued job (`DeliverNotification`, queue `notifications`) inside `rescue()`; the worker writes everyone told by ONE bulk insert. Do not add a per-recipient query or a per-recipient job. The bell polls (AJAX, ~8 s); there is deliberately no websocket / Reverb. Without a worker (`queue:work --queue=notifications,default`) nobody is notified. A new event = a constant + a method + two texts (customer / staff) + a `NotificationTest` case.
- Windows is case-insensitive: `__('Pagination')` (also `Auth`, `Passwords`, `Validation`) resolves to Laravel's `pagination.php` language file and returns an **array**, which crashes `{{ }}` with "htmlspecialchars(): Argument #1 must be of type string, array given". Never use a label that equals a language file name; it works on Linux, which hides the bug.
- The table is `user_notifications`, not `notifications` (Laravel's own uuid/morph table, which the `User` model's `Notifiable` trait would use). Run `php artisan migrate` after pulling.
- `Job::dispatch()` returns a `PendingDispatch` that only fires in its destructor, i.e. **after** a surrounding `rescue()` has returned: dispatch with `Bus::dispatch(new Job(...))` inside `rescue()` or a push failure escapes into the request.
- Windows: a stray lowercase `composer` environment variable (pointing at another profile's `vendor\bin`) makes `composer require` fail with "file_put_contents(...vendor\bin): Permission denied"; `unset composer` / `Remove-Item Env:composer` in that shell.
- `users.email` is unique **including soft deleted rows**: any code that creates a user from an outside email (Google sign-in) must look with `User::withTrashed()` and refuse a deleted account, or the insert throws a duplicate-key error.
- A new route behind `auth` must live inside the `['auth', 'profile.complete']` group in `routes/web.php` (or carry that middleware), or a Google sign-up that has not completed its profile can reach it. Tests that need an unfinished user use `User::factory()->profileIncomplete()`; the plain factory user is complete.
