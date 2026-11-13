# HTTP / AJAX API

> There is **no public REST/JSON API** (no `routes/api.php`, no tokens). Every endpoint is a session-authenticated
> web route. Pages are Blade views; the "API" is the set of AJAX calls those pages make (DataTables feeds, drawer
> loads, form submits). Source of truth: `routes/web.php`, `routes/auth.php`, run `php artisan route:list`.
>
> `scripts/harness.php` fails if a route is missing from this file. Each route must appear as `` `METHOD /uri` ``.

## Conventions

| Topic | Rule |
|---|---|
| Auth | Session cookie (`web` guard). All routes below are under `auth` unless marked *guest* / *public*. |
| CSRF | Required on every non-GET. `axios` (resources/js/bootstrap.js) sends `X-XSRF-TOKEN` automatically. |
| Authorisation | `permission:<name>` middleware declared in each controller's `middleware()` (Spatie). Missing permission → **403**. See [security.md](security.md). |
| Page vs feed | `GET /<resource>` returns the Blade page; the same URL with an AJAX request (`X-Requested-With`) returns the DataTables JSON feed (`skipPaging`, client-side paging). |
| Success | `200 {"status":"success","message":"..."}` (`Controller::successResponse`). |
| Failure | `{"status":"failed","message":"..."}` with `404` (not found), `422` (business rule), `403` (not allowed) (`Controller::errorResponse`). |
| Validation | Laravel default `422 {"message":"...","errors":{"field":["..."]}}`. |
| IDs | Path `{id}` is numeric-only (`whereNumber`). Primary keys are named per table (`dish_id`, `method_id`, ...). |
| Deletes | Soft deletes (`deleted_at`). |
| Uploads | `multipart/form-data`; images `jpeg,png,jpg,gif,webp`, max **2 MB**, stored on the `public` disk. |

## Dish types — permission `*-dish-types`

Managed from the **Manage Types** drawer on the Dish Masterlist page (`GET /dishes`); there is no page of its own.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /dish-types` `` | `view dish-types` | JSON list for the drawer (AJAX request only, shape: DataTables `{data:[{dish_type_id, type_name, description, is_active (badge), action (Edit / Delete buttons per permission)}]}`). A plain browser visit redirects to `/dishes`. |
| `` `POST /dish-types` `` | `create dish-types` | Body: `type_name` (required, unique among live rows), `description?`, `is_active`. |
| `` `GET /dish-types/{id}/edit` `` | `edit dish-types` | JSON of one type. 404 if missing. |
| `` `PUT /dish-types/{id}` `` | `edit dish-types` | Same body as create. |
| `` `DELETE /dish-types/{id}` `` | `delete dish-types` | Soft delete; refused (422) while a dish still uses the type. |

## Dishes

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /dishes` `` | `view dishes` | Page / table feed. |
| `` `POST /dishes` `` | `create dishes` | `dish_name`, `dish_types[]` (≥1, must exist), `description?`, `image?` (file), `price` (≥0), `calories?`, `main_ingredients?`, `is_active`. |
| `` `GET /dishes/{id}/edit` `` | `edit dishes` | JSON incl. `image` as public URL, `dish_types`, rendered `status` badge. (Also used by the read-only *View* drawer.) |
| `` `PUT /dishes/{id}` `` | `edit dishes` | As create, plus `remove_image` (bool). New file replaces the old; old file deleted after the row updates. |
| `` `DELETE /dishes/{id}` `` | `delete dishes` | Soft delete + removes the image file. |

## Payment methods

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /payment-methods` `` | `view payment-methods` | Page / table feed. |
| `` `POST /payment-methods` `` | `create payment-methods` | `method_name`, `account_name?`, `account_number?`, `qr_image?`, `is_active`. |
| `` `GET /payment-methods/{id}/edit` `` | `edit payment-methods` | JSON of one method. |
| `` `PUT /payment-methods/{id}` `` | `edit payment-methods` | As create + `remove_image`. **422** for protected methods (`PaymentMethod::PROTECTED`: *Cash on Pick up*). |
| `` `DELETE /payment-methods/{id}` `` | `delete payment-methods` | **422** for protected methods. |

## Payments

Every payment of every order. The payment row is created with the order, and its **order id is its key**, so `{id}` is an order id.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /payments` `` | `view payments` | Page / table feed (order, customer, method + `payment_method_id`, reference, amount, proof URL, status, lines, `created_at`, and the order's `session_id`, `session_date`, `start_time`, `end_time` — what the page's filters read). Status badge and row buttons are rendered by the server. The row has **View** (a popup filled from the row, so no `show` endpoint) and **Delete**; **Verify** and **Reject** live inside the popup. For a pending payment the row also carries `verify_url` / `reject_url` (null unless the payment is pending and the user holds the matching permission) and, for a decided payment, `undo_url` (null unless the user may undo); `rejection_reason` is included. |
| `` `POST /payments/{id}/verify` `` | `verify payments` | Pending → verified; stamps `verified_by` / `verified_at` (the reviewer) and mirrors `orders.payment_status`, in one transaction. **404** if missing, **422** unless the payment is pending, and **422** for a **Cash on Pick up** payment (it is verified when its order is completed) and for the payment of a **cancelled** order. |
| `` `POST /payments/{id}/reject` `` | `reject payments` | Body: **`reason`** (required, trimmed, max 500; otherwise **422** on `reason`). Pending → rejected, same reviewer stamp and order mirror as verify; the reason is stored and shown to the customer on **My Orders**. **404** if missing, **422** unless the payment is pending, and **422** for a **Cash on Pick up** payment. |
| `` `POST /payments/{id}/undo` `` | `undo payments` | Verified or rejected → **pending**: clears `verified_by`, `verified_at` and `rejection_reason`, and the order mirrors pending again, in one transaction. **404** if missing, **422** if the payment is already pending. Undoing a verified payment makes it deletable again. **422** unless its order is still pending (a completed or cancelled order keeps its payment decision). |
| `` `DELETE /payments/{id}` `` | `delete payments` | Soft delete. **404** if missing, **422** for a **verified** payment (the record of money received is kept). |

## Order sessions (a "selling window" for a set of dishes)

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /order-sessions` `` | `view order-sessions` | Page / table feed (includes computed `status`: `scheduled` / `live` / `ended`). |
| `` `POST /order-sessions` `` | `create order-sessions` | `order_date`, `start_time` `H:i`, `end_time` `H:i` (after start), `dish_ids[]` (≥1), `stocks[dish_id]` (int ≥0). |
| `` `GET /order-sessions/{id}` `` | `view order-sessions` | **Session page** (replaces the old View drawer): facts, sales totals, dishes and stock, and the orders as a table / cards. With an AJAX request the same URL returns the orders feed (DataTables JSON: `order_number`, `customer_name`, `items[]`, `items_count`, `total_amount`, `method_name`, `reference_number`, `proof_url`, `status_badge`, `payment_badge`, `order_date`, plus `verify_url` / `reject_url`: set only while the order's payment is pending and only for a user who holds `verify payments` / `reject payments`, otherwise null (and always null for Cash on Pick up) — the order details show Verify / Reject buttons from them; also `is_cash` and `complete_url` (set while the order is pending, its payment is settled — cash: pending or verified, others: verified — and the user holds `complete orders`) and `cancel_url` (set while the order is pending, its payment is **not** verified, and the user holds `cancel orders`), and they call `POST /payments/{id}/verify` and `/reject`). **404** if the session does not exist. |
| `` `GET /order-sessions/{id}/edit` `` | `edit order-sessions` | JSON of the session with its dishes and stock (edit drawer). |
| `` `PUT /order-sessions/{id}` `` | `edit order-sessions` | As create, plus `loaded_stocks[dish_id]` (int ≥0, optional): the stock each box showed when the edit drawer was opened. For a dish that is live in the session and has a loaded value, the stock becomes `max(0, current + (stocks − loaded_stocks))` in one UPDATE — orders placed while the drawer was open are kept, and `reserved_stock` is untouched. Without a loaded value (new dish, dish put back, old client) `stocks[dish_id]` is the stock as before. `POST` ignores `loaded_stocks`. |
| `` `POST /order-sessions/{id}/end` `` | `end order-sessions` | Closes the session by hand. **403** unless the caller is the creator (`created_by`); **422** if already ended. |
| `` `DELETE /order-sessions/{id}` `` | `delete order-sessions` | **422** while the session has orders. |

## Bulk sessions (staff; the window in which customers place big orders)

Separate from order sessions: no stock, a minimum number of plates per order, and at most one open window at a time. See [architecture.md](architecture.md#bulk-orders-separate-from-the-menu).

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /bulk-sessions` `` | `view bulk-sessions` | Page / table feed (AJAX: one row per session with `title`, `order_date`, `start_time`, `end_time`, `min_quantity`, `dishes_count`, `orders_count`, `created_by_name`, the computed `status` badge `scheduled` / `live` / `ended`, and `action` buttons; `Edit` / `End` / `Delete` appear only with their permission, `End` only for the creator). |
| `` `POST /bulk-sessions` `` | `create bulk-sessions` | `title` (optional, ≤120), `order_date`, `start_time` `H:i`, `end_time` `H:i` (after start), `min_quantity` (int, **inside the System Configuration limits**: ≥ the system minimum and ≤ the system maximum when one is set; else 422 on `min_quantity`), `dish_ids[]` (≥1, existing dishes). **No stock fields.** **422** (error on `start_time`) when the window overlaps another bulk session that is still open (an ended one does not count). |
| `` `GET /bulk-sessions/{id}` `` | `view bulk-sessions` | **Bulk session page**: facts (date, time, minimum, creator, orders count) and the dishes with their price; End Session for the creator. **404** if missing. (Its orders table joins this page with the order lifecycle.) |
| `` `GET /bulk-sessions/{id}/edit` `` | `edit bulk-sessions` | JSON of the session with its dishes (edit drawer). **404** if missing. |
| `` `PUT /bulk-sessions/{id}` `` | `edit bulk-sessions` | As create. The overlap check ignores the session itself, and is skipped when the session has already ended. The dish list is replaced: an unticked dish leaves the session (its row is soft-deleted, order lines keep pointing at it). **404** if missing. |
| `` `POST /bulk-sessions/{id}/end` `` | `end bulk-sessions` | Closes the session by hand. **403** unless the caller is the creator; **422** if already ended; **404** if missing. |
| `` `DELETE /bulk-sessions/{id}` `` | `delete bulk-sessions` | **422** while the session has orders; **404** if missing. |

## Bulk ordering (customer side)

The customer half of [bulk orders](architecture.md#bulk-orders-separate-from-the-menu): its own menu, its own order endpoint, no stock. Nothing here touches `orders`, `payments` or `session_dishes`.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /bulk-menu` `` | `view bulk-menu` | Page: the dishes of the **live bulk session** (`BulkSession::live()`), its title, window and minimum plates; the cash method and the bank / e-wallet methods for checkout. A friendly message when no bulk session is running or it offers no dish. No stock, no polling. |
| `` `POST /bulk-orders` `` | `create bulk-orders` | Place an order. **Throttled `10/min`.** Multipart: `bulk_session_id`, `payment_method_id` (an active method), `items[bulk_session_dish_id]` = plates (int 1–10000 each), `expected_prices[bulk_session_dish_id]` (optional, the unit price the page showed), `idempotency_key` (optional, ≤64), `reference_number` (optional), `proof_of_payment` (image ≤2 MB, **required unless the method is Cash on Pick up**). Success `200`: `{status, message, order_number, total_amount, plates}`. **404** unknown session; **422** when the session is not `live` (`This bulk session is not running.`); **409** while the same customer's other bulk order is being placed. **422 "review your cart"** — `{status:'error', message, prices:{bulk_session_dish_id: price}, min_quantity, max_quantity}` (`max_quantity` is `null` for no limit) — when a price changed, a dish is no longer on the bulk menu (it is missing from `prices`), or the total plates are outside the limits **as they are now**: below the minimum (the larger of the session's `min_quantity` and the System Configuration minimum, brought down to the maximum if that is lower) or above the System Configuration maximum. Standard Laravel 422 (`errors`) for the field rules. Lines are priced at the dish price of now and copied into `bulk_order_items`; the payment (`pending`) is stored on the order row. A retry with the same `idempotency_key` returns the first receipt (10 min) instead of a second order; the lock and receipt cache keys (`place-bulk-order:`, `bulk-order-receipt:`) are separate from the regular checkout's. |
| `` `GET /bulk-my-orders` `` | `view my bulk-orders` | Page: the signed-in customer's bulk orders, newest first (cards with `id="bulk-order-N"`): lines, plates, method, reference, proof link, the rejection reason. Only their own rows. |

## Ordering (customer side)

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /menu` `` | `view menu` | Page: dishes of the single *live* session, stock left, payment options (cash + bank/e-wallet). |
| `` `GET /menu/stock` `` | `view menu` | **Live stock feed** (query: `session_id`), polled by the menu every ~5 s (AJAX, JSON, `Cache-Control: no-store`). `{"status":"success","live":true,"stock":{"<session_dish_id>":left,...},"prices":{"<session_dish_id>":100.0,...}}`. `prices` is the current unit price of each offered dish, so a price an admin changes shows up on the menu within seconds. `live` is `false` (and `stock` and `prices` empty) when the session does not exist, has not started or has ended — the menu then stops polling and tells the customer. Dishes that were turned off or left the session are absent from `stock` (the menu shows them sold out). |
| `` `POST /orders` `` | `create orders` | `multipart`. Body: `session_id`, `payment_method_id` (must be **active** right now, else 422 on `payment_method_id`), `items[session_dish_id]=qty` (≥1 line), `reference_number?`, `idempotency_key?` (string ≤64, one per checkout), `expected_prices[session_dish_id]?` (number ≥0: the unit price each line showed; see "Price changes" below), `proof_of_payment` (image ≤2 MB; **required unless the method is Cash on Pick up**, else 422 on `proof_of_payment`). **Throttled 10 / minute per user** (429). |
| `` `POST /orders/{id}/complete` `` | `complete orders` | Hand-over: `pending` → `completed`. **Cash on Pick up**: the payment is verified by the completion itself (stamped with whoever completed it). **Any other method**: the payment must already be `verified` (it can be checked as soon as the order exists) — otherwise **422**. Also **422** for an order that is not pending or whose payment was rejected; **404** if missing. One transaction. |
| `` `POST /orders/{id}/cancel` `` | `cancel orders` | Call a **pending** order off: `pending` → `cancelled`. Gives the reserved stock back (`SessionDish::release`, per line) and, if the payment is still pending, rejects it with the reason "The order was cancelled." (stamped with whoever cancelled). **422** for an order that is not pending, and **422** when the payment is already **verified** (undo that first, so received money is never lost track of). **404** if missing. One transaction; the order row is locked (`lockForUpdate`) and re-checked inside it, so two people pressing Cancel together give the stock back **once** (the second gets 422). Complete and Verify / Reject take the same lock. |
| `` `GET /orders` `` | `view orders` | Page: every order of every session. On an AJAX request, the table feed: one row per live order with `customer_name`, `session_id` / `session_label` / `session_url` (null without `view order-sessions`), payment fields, `items[]`, `status_badge`, `payment_badge`, `proof_url` and the action URLs `complete_url` / `cancel_url` / `verify_url` / `reject_url` (each null unless it applies and the user may). Same row shape as the session page feed. |
| `` `GET /my-orders` `` | `view my orders` | Page: the caller's own orders, newest first, with status/payment badges and proof URL. A **pending** order whose payment was **rejected** also gets `reupload_url` (not for Cash on Pick up) and `cash_url` (only when an active Cash on Pick up exists), each set only for a user holding `create orders`; the page shows the "Fix your payment" panel from them. |
| `` `POST /my-orders/{id}/payment/proof` `` | `create orders` | The customer sends a **new proof** for a rejected payment (same method). `multipart`: `proof_of_payment` (image ≤2 MB, required), `reference_number?` (omit to keep the old one). The payment goes back to `pending` (reviewer, moment and rejection reason cleared, the replaced picture deleted) and the order's `payment_status` mirrors it; staff verify or reject it again. **404** when the order is not the caller's (or missing); **422** when the order is not `pending`, the payment is not `rejected`, or the method is Cash on Pick up; 422 on `proof_of_payment` when missing / not an image. Throttled 10 / minute. |
| `` `POST /my-orders/{id}/payment/cash` `` | `create orders` | The customer **pays with cash on pickup instead**: the payment's method becomes Cash on Pick up, proof and reference are cleared (the old picture is deleted), status `pending`; staff then complete the order as for any cash order (the completion verifies the payment). Same 404 / 422 rules as above; **422** also when no active Cash on Pick up method exists. Throttled 10 / minute. |

Both "fix your payment" routes lock the order row and re-check (`pending` order, `rejected` payment) inside one transaction, so a Cancel or a staff Undo that lands at the same moment — or a double tap — changes things once. The reserved plates are never touched: a rejection does not release them, which is why the customer can still fix it.

`POST /orders` behaviour:

0. **One order at a time per customer:** a cache lock (`place-order:{user id}`) wraps the request. A second request
   from the same customer while the first is running (double tap, second tab) gets **409** "Your order is already
   being placed" and takes nothing.
0. **Idempotency:** with the same `idempotency_key` a repeated request returns the first request's success body
   (same `order_number`) for 10 minutes instead of placing a second order. Failures are not remembered, so a
   refused order can be retried with the same key. The menu sends one key per cart + payment method.
1. Session must exist (404) and be `live` (422).
2. Each line must still be on the menu and within `available_stock` (422 "Only N left of X." / "X is sold out.").
3. Inside one DB transaction (retried up to 3 times on a deadlock): lines are reserved **lowest `session_dish_id`
   first** with a conditional `UPDATE ... WHERE available_stock >= qty AND is_available AND is_active`
   (no overselling under concurrency, no crosswise lock order), then the order number is read, and order + items +
   payment row are written. A duplicate order number (two orders in the same instant) restarts the transaction
   with the next number (up to 5 times).
4. Success: `{"status":"success","message":"Order ORD-YYYYMMDD-0001 placed successfully.","order_number":"...","total_amount":0.0,"stock":{...}}`.
   Money is rounded to cents per line.
5. Order starts `order_status=pending`, `payment_status=pending`.
6. **Stock refusals (422)** — a dish too short on the pre-check or one that ran out inside the transaction — answer
   `{"status":"error","message":"... Please review your cart.","stock":{...},"prices":{...}}`: the **current stock and
   prices** travel with the error, and the menu applies them, fixes the cart and returns to the cart step. A failed
   order stores nothing (the uploaded proof is deleted).
7. **Price changes (422)** — the order is charged at the dish price of *now*, but the customer saw (and, for a bank
   or e-wallet order, already paid) the price on their page. When `expected_prices` is sent and any line's current
   price differs (compared in cents), the order is refused before any stock is taken or proof stored, with the same
   body as above and a message like `The price changed: Adobo is now ₱120.00 (was ₱100.00). Please review your cart.`
   The menu updates the cards and the cart and the customer confirms the new total. A client that sends no
   `expected_prices` is not checked. A refusal is not remembered by the idempotency key, so the retry with the new
   prices goes through.


## System configuration — permission `view settings` / `edit settings`

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /settings` `` | `view settings` | Page. Read only (no Save button) without `edit settings`. |
| `` `PUT /settings` `` | `edit settings` | Body: `session_start_time`, `session_end_time` (both `H:i`, end after start). The default window the create order session drawer starts from. `notification_watchers[]` (optional): the ids of the users who receive a copy of **every** notification; each must be an existing, non-deleted user (else 422 on `notification_watchers.N`). The form always sends an empty entry, so un-picking everybody saves "nobody"; a caller that leaves the key out changes nothing. `email_notifications` (optional, `0` / `1`): also send every notification by email (the form always sends it; a caller that leaves it out changes nothing; 422 on anything else). `bulk_min_quantity` (int 1–100000) and `bulk_max_quantity` (optional int, ≥ the minimum, ≤100000; **empty = no maximum**): the fewest / most plates one **bulk order** may hold, all dishes together. Sent together by the form; a caller that leaves `bulk_min_quantity` out changes neither. 422 on `bulk_min_quantity` / `bulk_max_quantity`, and a refused save changes nothing (the whole save is validated first). |

## Notifications — no permission (every query is scoped to the signed-in user)


A person's own in-app notifications; see [architecture.md](architecture.md) for who is told what. There is deliberately no permission: like the profile, a user can only ever read and change their own rows, and a notification they cannot act on simply has no link. Guests are redirected to login (401 for JSON).

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /notifications` `` | — (own) | Page: the user's notifications, newest first, 20 a page (numbered pagination, `?page=N`: "Showing 21–40 of 87", Previous / Next and page numbers, a short "Page 2 of 5" on phones; one `COUNT(*)` over the caller's own rows; a page past the end redirects to the last page), unread ones highlighted, *Mark all as read*. |
| `` `GET /notifications/feed` `` | — (own) | What the bell polls (about every 8 s, only while the tab is visible; `Cache-Control: no-store`). Without `after`: `{"status":"success","unread":N,"latest_id":N,"items":[]}` — count and newest id only. With `?after=ID`: also `items` — what arrived after ID, oldest first, at most 10: `{id, type, title, body, open_url}` (almost always empty). With `?recent=1` (the bell's panel was opened, or something new arrived while it is open): also `recent` — the person's latest 6, newest first: `{id, title, body, open_url, unread, time}` (`time` is a human "5 minutes ago"); the poll never sends `recent`, so the list is only read when the panel is shown. |
| `` `GET /notifications/{id}/open` `` | — (own) | Marks the notification read (only the first time) and **redirects** to its `url`; a notification without a link, or whose url is not a local path (anything not starting with a single `/`), goes back to the list. **404** for an id that is not the caller's. |
| `` `POST /notifications/read-all` `` | — (own) | Marks all of the caller's unread notifications read with one UPDATE. Redirects to the list; `{status,message}` for an AJAX request. |

## Roles

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /roles` `` | `view roles` | Page / table feed. |
| `` `POST /roles` `` | `create roles` | `name` (unique), `permissions[]` (ids that exist). |
| `` `GET /roles/{id}/edit` `` | `edit roles` | JSON incl. assigned permissions. |
| `` `PUT /roles/{id}` `` | `edit roles` | **422** for protected roles (`Role::PROTECTED` = *Super Admin*). |
| `` `DELETE /roles/{id}` `` | `delete roles` | **422** if protected, or still assigned to users. |

## Users

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /users` `` | `view users` | Page / table feed (with role chips). |
| `` `POST /users` `` | `create users` | `first_name`, `last_name`, `company` (optional, ≤255), `email` (unique), `password` (≥8, `confirmed`), `roles[]` (≥1). |
| `` `GET /users/{id}/edit` `` | `edit users` | JSON of one user (`first_name`, `last_name`, `company` (null when none), and `name` = full name) + role ids. The table feed rows carry the same four. |
| `` `PUT /users/{id}` `` | `edit users` | Password optional on update; a blank `company` clears it. |
| `` `DELETE /users/{id}` `` | `delete users` | Soft delete; **422** when deleting your own account. |

## Account & auth (Laravel Breeze)

| Endpoint | Access | Notes |
|---|---|---|
| `` `GET /` `` | public | Welcome page. |
| `` `GET /dashboard` `` | `auth`, `verified` | Dashboard: greeting, the live session, the pending-payment count (with `view payments`) and "Jump to" tiles for what the user may open. |
| `` `GET /profile` `` | auth | Profile form. |
| `` `PATCH /profile` `` | auth | Update `first_name`, `last_name`, `company` (optional), `email`. |
| `` `DELETE /profile` `` | auth | Delete own account (needs current password). |
| `` `PUT /password` `` | auth | Change password. |
| `` `GET /register` `` | guest | Public self-registration (new users get the **Customer** role — see security.md). |
| `` `POST /register` `` | guest | `first_name`, `last_name`, `company` (optional, ≤255), `email`, `password`. |
| `` `GET /login` `` | guest | |
| `` `POST /login` `` | guest | |
| `` `GET /complete-profile` `` | auth (outside `profile.complete`) | "One last step" form for a Google sign-up: `first_name`, `last_name` (prefilled) and `company`. Redirects to `/dashboard` when the profile is already complete. |
| `` `POST /complete-profile` `` | auth (outside `profile.complete`) | `first_name`, `last_name`, `company`, all **required**, ≤255. Sets `profile_completed_at`, then goes to the intended URL / `/dashboard`. A complete user is redirected, their details untouched. **Gate:** every other signed-in page (everything in `web.php` behind `auth`, including `/dashboard`) answers an incomplete user with a redirect here, or **403** JSON `Complete your profile first.` for AJAX; logout is not gated. |
| `` `GET /auth/google` `` | guest, `throttle:20,1` | "Continue with Google": 302 to Google's consent screen. **404** until `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` are set. |
| `` `GET /auth/google/callback` `` | guest, `throttle:20,1` | Google's return (`code`, `state`). Signs in the account with that `google_id`, else the account with the same verified email (and links it), else creates one with the **Customer** role and an **incomplete profile** (see `/complete-profile`). Refuses (back to `/login` with an `email` error): failed exchange, unverified Google email, deactivated (soft deleted) account, email linked to another Google account. Success: `/dashboard` (or the intended URL). |
| `` `POST /logout` `` | auth | |
| `` `GET /forgot-password` `` | guest | |
| `` `POST /forgot-password` `` | guest | |
| `` `GET /reset-password/{token}` `` | guest | |
| `` `POST /reset-password` `` | guest | |
| `` `GET /verify-email` `` | auth | |
| `` `GET /verify-email/{id}/{hash}` `` | auth, `signed`, `throttle:6,1` | |
| `` `POST /email/verification-notification` `` | auth, `throttle:6,1` | |
| `` `GET /confirm-password` `` | auth | |
| `` `POST /confirm-password` `` | auth | |
