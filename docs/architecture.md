# Architecture

**Cucina Ordering Management System** — a kitchen/canteen ordering app. Staff publish an *order session* (a time window
with a limited stock of dishes); customers order from the live menu and pay by cash on pickup or bank/e-wallet (with
proof upload). Admins manage dishes and their types (one **Dish Masterlist** page), payment methods, sessions, roles and users.

## Stack

| Layer | Choice |
|---|---|
| Backend | PHP ^8.2, Laravel 12 (monolith, server-rendered) |
| Auth | Laravel Breeze (session) |
| Permissions | spatie/laravel-permission ^8 |
| Tables | yajra/laravel-datatables-oracle (server feed) + datatables.net (browser) |
| Frontend | Blade, Alpine.js 3, Tailwind 3, Vite 6, axios |
| DB / cache / queue / session | SQLite locally; `database` driver for cache, queue and sessions |
| Tests | PHPUnit 11 (`php artisan test`), SQLite in-memory |

## Request flow

```
Route (routes/web.php, group: auth)
  → Controller::middleware()  permission:<name>          (authorisation)
  → Controller action         $request->validate()       (validation)
  → Model static method       DB query builder           (data access)
  → View (Blade) | JSON {status,message} | DataTables JSON
```

## Key conventions (follow them — the codebase is consistent)

1. **Query-builder models.** `app/Models/*` expose `public static` methods (`getAll`, `findById`, `existsById`,
   `create…`, `update…`, `delete…`) over `DB::table()`. Eloquent is only kept where a package needs it (`User`, `Role`).
   Define table/column constants at the top of the model.
2. **IDs in routes are raw ints** (`{id}` + `whereNumber`); controllers cast with `(int) $id`.
3. **Responses** use `Controller::successResponse()` / `errorResponse()`; never invent another JSON shape.
4. **Authorisation lives in the controller's `middleware()`**, one `permission:` entry per action.
5. **Soft deletes + `is_active`** everywhere; reads filter `deleted_at IS NULL`.
6. **Business rules in the controller + model, not the view.** Examples: protected roles/payment methods, "creator ends the session", "no delete while it has orders".
7. **Money is `decimal(10,2)`**, line prices are copied into `order_items` at order time.
8. **Stock** is changed only through `SessionDish::reserve()` / `release()` (conditional UPDATE) inside a transaction.
9. **Comments explain *why*, in plain sentences**; the codebase uses `' . '` string concatenation with spaces
   (Pint's default would rewrite it — do **not** run `pint` over existing files).

## Domain model

- `Dish` ↔ `DishType` many-to-many.
- `OrderSession` has many `SessionDish` (a dish + its stock for that window). Only **one session may be `live`** at a time (`OrderSession::live()` returns the first).
- `Order` belongs to a session and a user; has many `OrderItem` (→ `SessionDish`); has one `Payment` (→ `PaymentMethod`).
- State machines:
  - session: `scheduled → live → ended` (computed from time + `ended_at` + `is_active`)
  - order: `pending → completed | cancelled` — both are built (`POST /orders/{id}/complete`, `POST /orders/{id}/cancel`); only a pending order moves
  - payment: `pending → verified | rejected` (mirrored on `orders.payment_status`)
  - **Payment rules by method:** *Cash on Pick up* is paid at the counter, so its payment is **verified when the order is completed** (no separate verify / reject). Any other method is paid first: its payment can be **verified or rejected as soon as the order exists**, and the order can only be **completed once that payment is verified**. A completed or cancelled order's payment decision cannot be undone.
  - **Cancelling** a pending order gives its reserved stock back (`SessionDish::release`) and rejects a payment that was still pending (reason "The order was cancelled."). A **verified** payment blocks it: undo the verification first. A cancelled order's payment can no longer be verified, rejected or undone.

## Directory map

```
app/Http/Controllers/   one controller per resource + Auth/ + Controller.php (shared badges/responses)
app/Models/             query-builder models (+ Role, User Eloquent)
database/migrations/    schema            database/seeders/   starter data      database/factories/  test data
resources/js/pages/     one module per page   resources/views/   Blade
routes/web.php          all app routes    routes/auth.php    Breeze routes
tests/Feature/          feature tests     tests/TestCase.php helpers superAdmin() / userWith()
docs/                   project docs      scripts/harness.php docs-drift + test harness
```

## Related docs

[api.md](api.md) · [database.md](database.md) · [security.md](security.md) · [frontend.md](frontend.md) · [testing.md](testing.md) · [harness.md](harness.md)
