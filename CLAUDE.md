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
composer dev                        # server + queue + pail logs + vite
php artisan test                    # PHPUnit (baseline: 169 passing)
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
- Payment rules depend on the method: Cash on Pick up is verified by completing its order (no manual verify/reject); other methods are verified first, then completed. Cancelling an order releases its stock and is refused while its payment is verified (undo that first).
- `#delete-modal-*` / `#save-modal-*` exist once per page and `dishes.js` owns them: a second page module on the same view (the Manage Types drawer) must not bind them — it confirms deletes inline and tags its buttons `data-type-action`.
- Windows: running `php artisan test` while the dev server is up can fail with "rename(...storage/framework/views/...tmp) Access is denied"; run the tests with `VIEW_COMPILED_PATH` pointing at another folder.
- Users have `first_name` + `last_name`, **no `name` column**: read `$user->name` (accessor) in PHP and use `User::fullNameSql($alias)` in query builder selects; never select `users.name`.
- Order rows are decorated in ONE place, `Controller::orderRow()` (badges, proof URL, action URLs by permission); the session page and the Orders page both use it with the shared `<x-ui.order-popup>` / `order-popup.js`. Do not copy that logic into a page.
- `tests/TestCase.php` comment says 16 permissions; real count is `count(Role::PERMISSIONS)`.
