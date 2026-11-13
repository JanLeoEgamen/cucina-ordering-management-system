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
| Google sign-in | laravel/socialite ^5 (optional, needs `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`) |
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
  - payment: `pending → verified | rejected` (mirrored on `orders.payment_status`); a **rejected** payment on a still-pending order goes back to `pending` when its customer sends a new proof or switches to Cash on Pick up (`POST /my-orders/{id}/payment/proof|cash`) — the order and its reserved plates stay as they are meanwhile
  - **Payment rules by method:** *Cash on Pick up* is paid at the counter, so its payment is **verified when the order is completed** (no separate verify / reject). Any other method is paid first: its payment can be **verified or rejected as soon as the order exists**, and the order can only be **completed once that payment is verified**. A completed or cancelled order's payment decision cannot be undone.
  - **Cancelling** a pending order gives its reserved stock back (`SessionDish::release`) and rejects a payment that was still pending (reason "The order was cancelled."). A **verified** payment blocks it: undo the verification first. A cancelled order's payment can no longer be verified, rejected or undone.

## Bulk orders (separate from the menu)

A second, parallel ordering feature: staff open a **bulk session** (a window like an order session, plus `min_quantity` plates per order; System Configuration sets the minimum and maximum plates of any bulk order, and a session may ask for more than the minimum, never less) and customers order big quantities from a **separate bulk menu**. Decided with the owner: customers order themselves; the dishes come from the same Dish Masterlist; **no stock** (the kitchen decides its capacity); the same payment methods, but its own pages, not the Orders / Payments pages.

It is its own tables and models (`BulkSession`, `BulkSessionDish`, `BulkOrder`, `BulkOrderItem`), **not** a `session_type` column: `orders.session_id` / `order_items.session_dish_id` are NOT NULL stock links, and the menu, dashboard, welcome page, `Order::list`, `Payment::listQuery`, `orderRow()`, `OrderNotifier` and the order popup all assume one kind of session. The payment is stored on `bulk_orders` itself (one per order), so an order and its payment change state under the same row lock (`BulkOrder::lockedState`). Same payment rules as orders (cash is verified by completing, others verified first; a verified payment blocks cancel) apply. At most **one open bulk session at a time** (overlap check, unlike order sessions). Status of this work: **data layer, permissions, factories, the staff Bulk Sessions page (`BulkSessionController`) and customer ordering (`BulkOrderController`: bulk menu, place order, My Bulk Orders) done; the order lifecycle (complete / cancel / verify / reject / undo, fixing a rejected payment), notifications and the session page's orders table follow** (see changelog). A placed bulk order is `pending` with a `pending` payment and nothing can move it yet.

## Notifications

In-app notifications (a bell with an unread badge and a **Notifications** page), written by `App\Support\OrderNotifier` into `user_notifications` (`UserNotification` model, query builder). Call **one** method **after** the event has been saved and its transaction has committed — never inside the transaction:

| Event | Called from | Customer of the order | Watchers |
|---|---|---|---|
| `placed` | `OrderController::placeOrder` | "Order X placed" (+ how to pay) | "Jane placed order X" |
| `paymentVerified` / `paymentRejected` (+ reason) | `PaymentController::decide` | "Payment verified" / "Payment rejected" (reason + the way out) | copy, in staff words |
| `completed` | `OrderController::complete` | "Order X completed" | copy |
| `cancelled` | `OrderController::cancel` | "Order X cancelled" | copy |
| `paymentResent` (new proof / switched to cash) | `OrderController::resubmitProof` / `switchToCash` | — | "Jane sent a new proof" / "Jane will pay X in cash" |
| `soldOut` | `OrderController::placeOrder` | — | "Adobo is sold out" (once per dish per 2 minutes) |

**Watchers** are the users picked in System Configuration → Notifications (`Setting::notificationWatchers()`, JSON in the `settings` table): they get a copy of every event of every customer, except the one they caused themselves, and the staff-only events above. A customer who is also a watcher gets one notification, in their own words.

**Queued, so a rush cannot hurt:** the request only pushes a small job (`App\Jobs\DeliverNotification`, one row in `jobs`) on the `notifications` queue (`config/notifications.php`, env `NOTIFICATIONS_QUEUE`) inside `rescue()`, so a customer never waits and a notification that cannot even be queued never fails an order. A **queue worker does the rest** at its own pace: 3 tries with 5 s / 30 s backoff, a `failed()` log line when it gives up, failed jobs pruned after a week (`queue:prune-failed`). The sold-out check is done in the request with one indexed read, and its job is pushed only when a dish really hit zero. **Retry-safe:** the ONE bulk insert is a single statement and comes after everything that can fail, so a retry can neither lose nor duplicate rows.

**Plain AJAX, no websocket:** the bell polls `GET /notifications/feed?after=N` about every 8 s (a count + an indexed range read, almost always empty; paused while the tab is hidden), so a notification appears within seconds without a refresh. The bell sits in the **top right corner** of every page (a sticky bar from `lg`, the phone top bar below it) with the dark / light switch and the account menu; opening it lists the latest 6 (`?recent=1`, read only then), and a new arrival swings it and toasts. Reverb / websockets were tried and removed on purpose: one less long-running process to run and watch. One event = the order (one join query), the people (one query), **one bulk INSERT**; the text is stored ready to print; read rows are pruned daily by `notifications:prune` (`Schedule`, needs `schedule:work` / the server cron).

**Email copy (optional, System Configuration → Notifications → *Send notifications by email*, default off):** when on, `OrderNotifier` pushes ONE more job after the in-app rows are saved — `App\Jobs\SendNotificationEmails` on the same queue, carrying one entry per person told (same title, text and link as the bell) — and the worker sends a `App\Mail\NotificationMail` (markdown view `emails/notification`) to each person's account address. One job per event, not per person. It is **not retried** (`tries = 1`, a retry would email everyone twice); each address is tried on its own and a failure is logged and skipped, and the push is inside `rescue()` so a mail problem can never fail the delivery job (whose retry would write the bell rows twice). The mail server comes from `.env` (`MAIL_*`; the default `log` mailer only writes to the log).

**Running it (one long-lived process besides the web server):** `php artisan queue:work --queue=notifications,default --tries=3 --max-time=3600` under Supervisor / systemd, restarted on every deploy (`php artisan queue:restart`), plus the scheduler cron. `composer dev` starts the worker locally. Without the worker nobody is notified (orders still work; the jobs wait in `jobs`).

## Directory map

```
app/Http/Controllers/   one controller per resource + Auth/ + Controller.php (shared badges/responses)
app/Models/             query-builder models (+ Role, User Eloquent)
app/Support/            OrderNotifier (who is told what)   app/Console/Commands/  notifications:prune
app/Jobs/               DeliverNotification, SendNotificationEmails (queued)   app/Mail/  NotificationMail
config/notifications.php  the notifications queue name
app/Exceptions/         StockRanOutException
database/migrations/    schema            database/seeders/   starter data      database/factories/  test data
resources/js/pages/     one module per page   resources/views/   Blade
routes/web.php          all app routes    routes/auth.php    Breeze routes
tests/Feature/          feature tests     tests/TestCase.php helpers superAdmin() / userWith()
docs/                   project docs      scripts/harness.php docs-drift + test harness
```

## Related docs

[api.md](api.md) · [database.md](database.md) · [security.md](security.md) · [frontend.md](frontend.md) · [testing.md](testing.md) · [harness.md](harness.md)
