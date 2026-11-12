# Security

How access is controlled today, and what is known to be weak. Update this file whenever you touch auth, permissions,
uploads, validation, sessions, secrets or config. `scripts/harness.php` fails if a permission in `Role::PERMISSIONS`
is not listed below.

## Authentication

- Laravel Breeze (session guard `web`). Login, register, password reset, email verification, password confirmation.
- Sessions are stored in the database (`SESSION_DRIVER=database`, lifetime 120 min).
- Passwords hashed with bcrypt (`BCRYPT_ROUNDS=12`).
- `User` does **not** implement `MustVerifyEmail`, so the `verified` middleware on `/dashboard` is currently a no-op.

## Authorisation (Spatie laravel-permission)

- Gate = `permission:<name>` middleware declared per controller (`HasMiddleware::middleware()`), **not** in routes.
  A new controller method must be added to that list or it is unprotected.
- `App\Models\Role::PERMISSIONS` is the single list of permissions. Add one there, then run
  `php artisan db:seed --class=PermissionSeeder` (idempotent; re-syncs *Super Admin*).
- `Role::SUPER_ADMIN` ("Super Admin") holds every permission. `Role::PROTECTED` roles cannot be edited/deleted from the UI.
- `PaymentMethod::PROTECTED` ("Cash on Pick up", the only cash method) cannot be edited/deleted (matched by name; the table feed also hides their buttons).
- Row-level rule: only the creator of an order session can end it (`OrderSessionController::canEnd`), on top of `end order-sessions`.
- Users cannot delete their own account via `/users/{id}`.
- Table-cell action buttons are rendered server-side only if the user holds the permission (UI hint only — the route middleware is the real gate).

### Permission catalogue

| Area | Permissions |
|---|---|
| Dishes | `view dishes`, `create dishes`, `edit dishes`, `delete dishes` |
| Dish types (managed from the Dish Masterlist's Manage Types drawer; the drawer and its script load only with `view dish-types`) | `view dish-types`, `create dish-types`, `edit dish-types`, `delete dish-types` |
| Payment methods | `view payment-methods`, `create payment-methods`, `edit payment-methods`, `delete payment-methods` |
| Payments | `view payments`, `verify payments`, `reject payments`, `undo payments`, `delete payments` |
| Order sessions | `view order-sessions`, `create order-sessions`, `edit order-sessions`, `delete order-sessions`, `end order-sessions` |
| Ordering | `view menu`, `create orders`, `view my orders`, `view orders`, `complete orders`, `cancel orders` |

`view orders` opens the **Orders** page (every order of every session). It only lists and opens orders: the Complete / Cancel / Verify / Reject buttons in its popup each appear only with `complete orders`, `cancel orders`, `verify payments` and `reject payments`, exactly as on the order session page (the feed row carries an action URL only when the user may take it). Not part of the Customer role.
| Roles | `view roles`, `create roles`, `edit roles`, `delete roles` |
| Users | `view users`, `create users`, `edit users`, `delete users` |
| System configuration | `view settings`, `edit settings` |

## Input handling

- All writes validate with `$request->validate()` inside the controller; ids in paths are `whereNumber`.
- DB access uses the query builder with bindings (no string-concatenated SQL). The two `DB::raw()` stock updates in
  `SessionDish` cast the quantity to `(int)`.
- Output: Blade escapes by default. Controller-built HTML (badges, row buttons) must wrap every dynamic value in `e()`.
- Uploads: `image` + `mimes` allow-list, 2 MB cap, stored with a generated name (`store()`), never the client file name.
- Stock: reservation is a single conditional `UPDATE` inside a transaction (no oversell race).
- CSRF: Laravel `web` middleware on all routes; axios attaches the token.

## Known weaknesses (tracked in known-issues.md)

Reviewed 2026-11-11. Priority: **P0** fix before any real use · **P1** before go-live · **P2** next sprint · **P3** hygiene.
All items are open. Functional and quality gaps are in [known-issues.md](known-issues.md).

| ID | Pri | Issue | Where | Fix |
|---|---|---|---|---|
| S1 | **P0** | Privilege escalation via users: `create users` / `edit users` can assign *Super Admin* to anyone, including yourself. | `UserController::validateUser`, `User::updateUser` | Only Super Admin assigns Super Admin; block editing own roles; limit assignable roles to those you hold. |
| S2 | **P0** | Privilege escalation via roles: `edit roles` / `create roles` can add any permission to a role you hold. | `RoleController::update`, `validateRole` | Grant only permissions you hold; treat as admin-only; add tests. |
| S3 | **P0** | Default credentials: seeded `test@example.com` / `password` is *Super Admin*. | `DatabaseSeeder`, `PermissionSeeder` | Seed only in `local`; credentials from env; force change on first login. |
| S4 | **P1** | Payment proofs on the **public** disk: guessable `/storage/payments/...` URLs with no auth (financial/personal data). | `OrderController::store`, `Payment::proofUrl` | Private disk + authorised download route (owner or verifier). |
| S5 | **P1** | **Partly fixed:** staff can now cancel a pending order (`cancel orders`), which gives its stock back. Still open: orders reserve stock instantly and stay `pending` until someone acts, and there is no quantity cap, per-user limit or automatic expiry, so one customer can still tie up a lot of stock. | `SessionDish::reserve`, `validateOrder`, `OrderController::cancel` | Per-line and per-user caps; pending timeout that cancels automatically. |
| S6 | **P1** | No rate limiting on `POST /orders` and `POST /register` (login is limited to 5 tries by Breeze). | `routes/web.php`, `RegisteredUserController` | `throttle:` middleware; CAPTCHA on register if public. |
| S7 | **P1** | Open `/register` creates role-less accounts; any default role added later must be a minimal *Customer* role. | `routes/auth.php`, `RegisteredUserController` | Disable registration, or auto-assign `view menu`, `create orders`, `view my orders` only. |
| S8 | **P1** | Unsafe production defaults: `APP_DEBUG=true` in `.env.example`; `SESSION_SECURE_COOKIE` unset; `SESSION_ENCRYPT=false`. | `.env.example`, `config/session.php` | Prod: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, HTTPS only. |
| S9 | **P2** | `reference_number` is free text with no uniqueness: one transfer can "pay" many orders. | `validateOrder`, `payments` | Unique per method + check at verification. |
| S10 | **P2** | Super Admin lock-out/takeover: any `delete users` holder can soft-delete a Super Admin (only self-delete is blocked). | `UserController::destroy` | Protect Super Admin accounts; require at least one active. |
| S11 | **P2** | **Partly fixed:** `verify payments`, `reject payments` (reason required) and `undo payments` exist; a decision stamps the reviewer, a verified payment cannot be deleted. Still no audit trail: an undo erases the earlier reviewer and reason, and a delete is not logged. | `PaymentController` | Transition / delete log (see S12) so undo keeps history. |
| S12 | **P2** | No audit log of role / permission / user / payment changes. | — | Activity log package or own table. |
| S13 | **P2** | Inconsistent password policy: register uses `Password::defaults()`, admin-created users only `min:8`. | `UserController::validateUser` | Use `Password::defaults()` everywhere. |
| S14 | **P3** | `verified` middleware is a no-op (`User` lacks `MustVerifyEmail`). | `routes/web.php` `/dashboard` | Implement the interface or drop the middleware. |
| S15 | **P3** | No security headers / CSP; no `composer audit` / `npm audit` step. | `bootstrap/app.php` | Header middleware; audits in CI. |
| S16 | **P3** | Controller-built HTML relies on `e()` discipline; one `{!! $icon !!}` in `notification.blade.php` (static today, safe). | `Controller.php`, `notification.blade.php` | Keep the `e()` rule; add an escaping test. |
| S17 | **P3** | Uploads: allow-list and 2 MB cap are good, but no re-encoding or dimension limit. | Dish / PaymentMethod / Order controllers | Optional re-encode, cap dimensions. |

## Secrets & config

- `.env` is git-ignored; `.env.example` is committed and must contain **no real secrets**.
- `APP_KEY` is generated per environment (`php artisan key:generate`).
- Timezone `APP_TIMEZONE=Asia/Manila` — session start/end times are compared in this zone.

## When you change something

| You change... | Also check |
|---|---|
| Add/rename a permission | `Role::PERMISSIONS`, `PermissionSeeder`, tables above, `tests/TestCase.php` helpers, api.md |
| Add a controller action | its `middleware()` list, api.md, a 403 test with `userWith()` |
| Add an upload | validation rules, disk choice (public vs private), this file's S2 |
| Add controller-built HTML | `e()` on every dynamic value |
