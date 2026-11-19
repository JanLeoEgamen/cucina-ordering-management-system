# Security

How access is controlled today, and what is known to be weak. Update this file whenever you touch auth, permissions,
uploads, validation, sessions, secrets or config. `scripts/harness.php` fails if a permission in `Role::PERMISSIONS`
is not listed below.

## Authentication

- Laravel Breeze (session guard `web`). Login, register, password reset, email verification, password confirmation.
- **Password policy:** one rule, `Password::defaults()` set in `AppServiceProvider::boot` — at least 8 characters with a letter, a number and a special character (Laravel's `symbols()`: punctuation, symbol or space). Register, reset password and change password all use it. The sign up page shows it live (`<x-password-input meter>`: a Weak / Good / Strong bar and a checklist, hidden until the first character; `resources/js/ui/password-field.js`, same Unicode classes as the server) — a guide only, the server decides. Deliberately **no** `uncompromised()` (it would send a hash prefix to an outside service on every sign up). Passwords created by an admin in Users are still only `min:8` (S13).
- **Google sign-in** (Laravel Socialite, `GoogleAuthController`, off until `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` are set; the secret lives in `.env` only). Accounts are matched on Google's stable `sub` (`users.google_id`), then on the **verified** email, which links an existing account on its first Google sign-in (so a Google account that controls that address can enter it: acceptable because Google verified it, and refused when `email_verified` is false). An email already linked to a different `google_id`, and soft deleted accounts, are refused. New Google accounts get the **Customer** role, like `/register` (`User::giveCustomerRole()`), and an **incomplete profile**: the `profile.complete` middleware (`EnsureProfileIsComplete`, on `/dashboard` and the whole `auth` group of `web.php`) holds them on `/complete-profile` until company and names are saved (a new route in `web.php` must sit inside that group; AJAX gets 403). Registration, admin-created users, the seeder and the factory set `profile_completed_at` at once, and the migration marked every existing account complete. They also get and a random unknown password (they can set one through "Forgot your password?"). The OAuth `state` is checked by Socialite (session based); both routes are `guest` + `throttle:20,1`.
- Sessions are stored in the database (`SESSION_DRIVER=database`, lifetime 120 min). Tabs of one browser share the session cookie, so logging out in one tab ends the session for all of them **on the server** at once; the other tabs' stale pages are sent to the login page by `resources/js/ui/session-guard.js` (see frontend.md), and any request they still make is refused (401 for AJAX, redirect to login otherwise).
- **Behind a proxy / CDN / load balancer:** set `TRUSTED_PROXIES` (`*` or a comma list of IPs / CIDRs; `bootstrap/app.php`) so the client IP, host and https come from `X-Forwarded-*`. Unset by default on purpose: trusting everyone on a server that faces the internet directly lets any client forge its IP and dodge the login throttle. Use the proxy's real address range when it is known (Cloudflare publishes its ranges); `*` is only acceptable when nothing but the proxy can reach the app.
- Passwords hashed with bcrypt (`BCRYPT_ROUNDS=12`).
- `User` does **not** implement `MustVerifyEmail`, so the `verified` middleware on `/dashboard` is currently a no-op.

## Authorisation (Spatie laravel-permission)

- Gate = `permission:<name>` middleware declared per controller (`HasMiddleware::middleware()`), **not** in routes.
  A new controller method must be added to that list or it is unprotected.
- `App\Models\Role::PERMISSIONS` is the single list of permissions. Add one there, then run
  `php artisan db:seed --class=PermissionSeeder` (idempotent; re-syncs *Super Admin*).
- **Accounts with no role get 403 everywhere.** Sign-ups get *Customer* automatically (`User::giveCustomerRole()`); accounts that registered before that have none. Run `php artisan users:give-customer-role --dry-run` to list them and then without `--dry-run` to give them *Customer* (adds only; skips accounts that already hold any role and deleted ones; refuses until `PermissionSeeder` has created the role). **Review the dry-run first:** an admin may have emptied someone's roles on purpose to block them, and this would give that person ordering access back.
- `Role::SUPER_ADMIN` ("Super Admin") holds every permission. `Role::PROTECTED` roles cannot be edited/deleted from the UI.
- `PaymentMethod::PROTECTED` ("Cash on Pick up", the only cash method) cannot be edited/deleted (matched by name; the table feed also hides their buttons).
- Row-level rule: only the creator of an order session can end it (`OrderSessionController::canEnd`), on top of `end order-sessions`.
- Customer cancel: `POST /my-orders/{id}/cancel` (`create orders`, throttled 10 / minute) lets a customer cancel only their OWN order (anyone else's answers 404), only while the System Configuration switch `orders.customer_cancel` is on (off by default, read on every request, so switching it off stops the next cancel; 403 otherwise), only while the order is pending and its payment is **not verified** (then staff must undo it first). The order row is locked and judged again inside the transaction, so a double tap gives the stock back once. A customer's cancel never stamps them as the payment's reviewer. The switch is behind `edit settings`.
- Bulk ordering: `POST /bulk-orders` is throttled (10/min per user), takes a per-customer cache lock and an idempotency key, prices every line from the database at the time of the order (the page's prices are only compared, never trusted), and refuses a dish that is not on the live session's bulk menu. The size of an order is bounded server-side by the limits in System Configuration (`edit settings`): not under the minimum, not over the maximum, both read fresh on every order. The proof of payment is an image ≤2 MB stored on the `public` disk and deleted when the order is not written. `My Bulk Orders` reads only `user_id = the signed-in user`.
- Row-level rule: only the creator of a bulk session can end it (`BulkSessionController::canEnd`), on top of `end bulk-sessions`. A bulk session with orders cannot be deleted. The bulk session title is typed by staff and printed in the table feed: DataTables escapes it (it is not a raw column).
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
| Bulk sessions | `view bulk-sessions`, `create bulk-sessions`, `edit bulk-sessions`, `delete bulk-sessions`, `end bulk-sessions` |
| Bulk ordering | `view bulk-menu`, `create bulk-orders`, `view my bulk-orders` (the three are in the Customer role), `view bulk-orders`, `complete bulk-orders`, `cancel bulk-orders` |
| Bulk payments | `verify bulk-payments`, `reject bulk-payments`, `undo bulk-payments` (no page of their own: the buttons are on the bulk session page) |
| Roles | `view roles`, `create roles`, `edit roles`, `delete roles` |
| Users | `view users`, `create users`, `edit users`, `delete users` |
| System configuration | `view settings`, `edit settings` |
| Audit trail | `view audit-logs` (the list, one entry and the CSV export; read only, there is no permission to change or delete an entry because no route does). Re-run `php artisan db:seed --class=PermissionSeeder` after pulling: Super Admin gets it, nobody else until a role is given it. |

## Audit trail

`App\Http\Middleware\AuditTrail` sits in the `web` group, so **every route is covered, present and future**; sign in / out / failed sign in come from Laravel's auth events (`App\Listeners\AuditAuthEvents`), so Breeze, Google sign-in and any later way in are covered the same. Entries go to `audit_logs` (see [database.md](database.md)).

- **What is written** (one row each): every **change** (POST / PUT / PATCH / DELETE) of a signed-in person, whatever the result — success, a rule that said no (`failed`, with the app's message), a 403 / 401 (`denied`), a 5xx (`error`) — with who (copied: name, email), what (`users.destroy` → "Deleted a user"), which record (`users`, id, label copied *before* the request runs, so a deleted record is still named), **what changed** (`{field: [before, after]}` for the record the route names, read before and after), what was sent (the input), IP and browser. **Page views are off by default** (`AUDIT_LOG_VIEWS=true` logs every full page load, never table feeds or polls): navigation is the bulk of the rows and says little, and sign in / out, changes and refusals do not depend on it. **Sign in, sign out, failed sign in** (the address typed, never the password), **lockout**, **registration**, **password reset**. Taking the log out as CSV, and the prune command, are entries too.
- **What is never written:** passwords, tokens, secrets (any input field whose name contains `password`, `token`, `secret`, `pin`, `card`, `cvv` is stored as `[hidden]`), the `users.password` / `remember_token` / `google_id` columns, uploads (only their file name), the query string (an OAuth `code=` or reset token can sit in it), guests' requests.
- **Append only:** the model has `record()` and `prune()` only; no route updates or deletes an entry (a test asserts 405). The only delete is `php artisan audit:prune` (scheduled daily, `AUDIT_RETENTION_DAYS`, default 365, `0` = keep for ever), and each run that deletes something writes a `system` entry.
- **It never gets in the way:** every write is inside `rescue()`: a failure is logged and the person's request goes on (tested by dropping the table mid-request). `AUDIT_ENABLED=false` switches it off.
- **Personal data:** the log holds names, emails, IPs and what people typed. Treat it like the users table: only `view audit-logs` holders read it, set a retention you can justify, and exclude it from any public export. CSV cells starting with `= + - @` are prefixed with `'` (a name typed as a formula cannot run in Excel).
- **Adding a route:** nothing to do, it is logged under its route name (or `METHOD path` when unnamed). To get a proper sentence add it to `config/audit.php` `actions`; to get the record's label and a before / after diff add its route prefix to `subjects` (`{id}` must be the route parameter).

## Input handling

- All writes validate with `$request->validate()` inside the controller; ids in paths are `whereNumber`.
- DB access uses the query builder with bindings (no string-concatenated SQL). The two `DB::raw()` stock updates in
  `SessionDish` cast the quantity to `(int)`.
- Output: Blade escapes by default. Controller-built HTML (badges, row buttons) must wrap every dynamic value in `e()`.
- Errors: every HTTP error renders the themed `<x-error-page>` (`resources/views/errors/`). It prints fixed wording per status and, for a 403 only, the message an `abort(403, '…')` call chose (escaped; the framework's and Spatie's own wording is suppressed). Exception messages of a 500 (SQL, paths) are never printed; with `APP_DEBUG=false` (production) a stack trace never reaches the browser. Keep `APP_DEBUG=false` outside local development.
- Uploads: `image` + `mimes` allow-list, 2 MB cap, stored with a generated name (`store()`), never the client file name. The **app logo** (System Configuration, `edit settings`) follows the same rule and additionally **refuses SVG and GIF** (`mimes:jpeg,png,jpg,webp`): an SVG opened on its own URL runs its scripts on this site's origin (stored XSS by anyone who can edit settings). The app name is plain text, escaped by Blade everywhere it is printed.
- Stock: reservation is a single conditional `UPDATE` inside a transaction (no oversell race), in a fixed line order with deadlock retry.
- Concurrency on orders: `POST /orders` holds a per-customer cache lock (409 on a parallel request) and honours an `idempotency_key`
  (replays return the first receipt); Complete / Cancel / Verify / Reject lock the order row and re-check inside the transaction
  (no double release of stock, no verified payment on a cancelled order). The cache driver must support locks (`database`, `redis`, `array` do).
- `payment_method_id` must be an **active** method (a switched-off one is refused). A database error is never shown to the customer
  (`StockRanOutException` is its own type; the old `catch (RuntimeException)` also caught `PDOException`).
- `GET /menu/stock` exposes only stock numbers and prices, behind `view menu`.
- Notifications (`/notifications*`) have **no permission on purpose**, like the profile: every query is scoped by `user_id = auth id`, an id
  that is not yours answers 404, and *open* only follows a local path (a url not starting with a single `/` is ignored: no open redirect).
  The text is written by the app (order number, dish name, customer name, the reviewer's rejection reason), always printed escaped.
  **Privacy:** a watcher (System Configuration → Notifications, behind `edit settings`) receives every customer's name, order total,
  payment method and rejection reasons, so pick them like you would grant `view orders` + `view payments`; a notification's link is
  only set when the watcher holds the permission for the page it opens. The poll is not throttled (a count and an indexed range read).
  **Google Chat copy** (System Configuration → Notifications → *Send notifications to Google Chat*, behind `edit settings`, **off by default**): the same people and words as the bell, sent as a direct message by a Chat app (`App\Support\GoogleChat`, job `SendGoogleChatMessages`, `tries = 1`). **The text of every notification leaves the server for Google** (customer names, order numbers, totals), like email does. The **service account key** is a file whose path is `GOOGLE_CHAT_CREDENTIALS` in `.env` (never in the database, the repo or the log; only `client_email` / `private_key` are read, and a log line carries a status code, never the key or the message). The app signs in with the self-granted `chat.bot` scope only (no admin-approved `chat.app.*` scope): it can only write to spaces it is in, i.e. to people who added the app themselves. People are found by `users.google_id`; accounts without one are skipped. **Email copy** (System Configuration → Notifications → *Send notifications by email*, behind `edit settings`, **off by default**): the same text goes to the account email of everyone who got the in-app notification, so the privacy note above now reaches mailboxes the app does not control (a watcher's address is a place customer names and totals leave the system). The mail credentials stay in `.env` (`MAIL_*`), never in the settings table. The page warns when the mailer is `log` / `array` (emails are only recorded). Sent text is escaped by the markdown mail template; failures are logged with the user id, not the address.
- Fixing a rejected payment (`POST /my-orders/{id}/payment/proof|cash`) is behind `create orders` (no new permission) and **owner-only**: an
  order that is not the caller's answers 404, like a missing one. The new proof goes through the same upload rules as at checkout (`image`,
  `mimes`, 2 MB, generated name); it is deleted again when the request is refused, and the replaced picture is deleted when it succeeds.
  The state change happens under the order's row lock. Throttled 10 / minute per user. A customer can only move their own payment from
  `rejected` back to `pending`; only staff with `verify payments` can ever make it `verified`.
- CSRF: Laravel `web` middleware on all routes; axios attaches the token.

## Known weaknesses (tracked in known-issues.md)

Reviewed 2026-11-11. Priority: **P0** fix before any real use · **P1** before go-live · **P2** next sprint · **P3** hygiene.
All items are open. Functional and quality gaps are in [known-issues.md](known-issues.md).

| ID | Pri | Issue | Where | Fix |
|---|---|---|---|---|
| S1 | **P0** | Privilege escalation via users: `create users` / `edit users` can assign *Super Admin* to anyone, including yourself. | `UserController::validateUser`, `User::updateUser` | Only Super Admin assigns Super Admin; block editing own roles; limit assignable roles to those you hold. |
| S2 | **P0** | Privilege escalation via roles: `edit roles` / `create roles` can add any permission to a role you hold. | `RoleController::update`, `validateRole` | Grant only permissions you hold; treat as admin-only; add tests. |
| S3 | **P0** | Default credentials: seeded `test@example.com` / `password` is *Super Admin*, and so is `jleoegamen6@gmail.com` with the default password `Password123!` written in `UserSeeder` (override with `SEED_USER_PASSWORD`). | `DatabaseSeeder`, `UserSeeder`, `PermissionSeeder` | Seed only in `local`; credentials from env; force change on first login. |
| S4 | **P1** | Payment proofs on the **public** disk: guessable `/storage/payments/...` URLs with no auth (financial/personal data). | `OrderController::store`, `Payment::proofUrl` | Private disk + authorised download route (owner or verifier). |
| S5 | **P1** | **Partly fixed:** staff can now cancel a pending order (`cancel orders`), which gives its stock back. Still open: orders reserve stock instantly and stay `pending` until someone acts, and there is no quantity cap, per-user limit or automatic expiry, so one customer can still tie up a lot of stock. | `SessionDish::reserve`, `validateOrder`, `OrderController::cancel` | Per-line and per-user caps; pending timeout that cancels automatically. |
| S6 | **P1** | **Fixed:** `POST /orders` is throttled to 10 / minute per user, `POST /register` to 5 / minute and 30 / hour per IP (named limiter `register` in `AppServiceProvider`; invalid attempts count; a 429 past the cap), login to 5 tries (Breeze). Still open: no CAPTCHA, so a script that rotates IPs is not stopped; behind a proxy the limit sees the client's IP only when `TRUSTED_PROXIES` is set, otherwise everyone shares the proxy's address and one burst blocks all sign-ups. | `routes/auth.php`, `AppServiceProvider`, `RegisteredUserController` | CAPTCHA if sign-up is public and abused. |
| S7 | **P1** | **Fixed:** open `/register` (and the first Google sign-in) now assigns the minimal *Customer* role (`Role::CUSTOMER_PERMISSIONS`: menu, ordering, my orders, bulk menu / orders). Still open: if `PermissionSeeder` has not run the role does not exist and the account stays role-less; accounts registered before the change have no role. | `routes/auth.php`, `RegisteredUserController`, `GoogleAuthController`, `User::giveCustomerRole` | Seed on deploy; give old accounts the role in the Users page. |
| S8 | **P1** | Unsafe production defaults: `APP_DEBUG=true` in `.env.example`; `SESSION_SECURE_COOKIE` unset; `SESSION_ENCRYPT=false`. | `.env.example`, `config/session.php` | Prod: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, HTTPS only. |
| S9 | **P2** | `reference_number` is free text with no uniqueness: one transfer can "pay" many orders. | `validateOrder`, `payments` | Unique per method + check at verification. |
| S10 | **P2** | **Fixed:** a Super Admin account cannot be deleted (`UserController::destroy` 422, `ProfileController::destroy` refuses, `User::deleteUser` returns false). Still open: an `edit users` holder can strip the Super Admin role first and then delete (see S1). | `User::isSuperAdmin`, `UserController::destroy` | Close with S1 (only a Super Admin edits a Super Admin's roles). |
| S11 | **P2** | **Partly fixed:** `verify payments`, `reject payments` (reason required) and `undo payments` exist; a decision stamps the reviewer, a verified payment cannot be deleted. The audit trail now keeps every decision, undo and delete (who, when, the before / after), so history survives an undo there; the payment row itself still forgets the earlier reviewer and reason, and the order's own screen has no history panel. | `PaymentController` | Transition / delete log (see S12) so undo keeps history. |
| S12 | **P2** | **Fixed:** an audit trail records every change, refusal and sign in / out, and optionally page views (see "Audit trail" above). Still open: it is append-only by code, not by the database (see known-issues A1). | `AuditTrail` middleware, `audit_logs` | DB-level protection (no UPDATE / DELETE grant for the app user) and a hash chain if tamper evidence is required. |
| S13 | **P2** | Inconsistent password policy: register, reset and change password use `Password::defaults()` (8+ with a letter, a number and a special character), admin-created users only `min:8`. | `UserController::validateUser` | Use `Password::defaults()` everywhere. |
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
