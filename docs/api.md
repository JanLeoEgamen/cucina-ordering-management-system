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
| `` `PUT /order-sessions/{id}` `` | `edit order-sessions` | As create. |
| `` `POST /order-sessions/{id}/end` `` | `end order-sessions` | Closes the session by hand. **403** unless the caller is the creator (`created_by`); **422** if already ended. |
| `` `DELETE /order-sessions/{id}` `` | `delete order-sessions` | **422** while the session has orders. |

## Ordering (customer side)

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /menu` `` | `view menu` | Page: dishes of the single *live* session, stock left, payment options (cash + bank/e-wallet). |
| `` `POST /orders` `` | `create orders` | `multipart`. Body: `session_id`, `payment_method_id`, `items[session_dish_id]=qty` (≥1 line), `reference_number?`, `proof_of_payment` (image ≤2 MB; **required unless the method is Cash on Pick up**, else 422 on `proof_of_payment`). |
| `` `POST /orders/{id}/complete` `` | `complete orders` | Hand-over: `pending` → `completed`. **Cash on Pick up**: the payment is verified by the completion itself (stamped with whoever completed it). **Any other method**: the payment must already be `verified` (it can be checked as soon as the order exists) — otherwise **422**. Also **422** for an order that is not pending or whose payment was rejected; **404** if missing. One transaction. |
| `` `POST /orders/{id}/cancel` `` | `cancel orders` | Call a **pending** order off: `pending` → `cancelled`. Gives the reserved stock back (`SessionDish::release`, per line) and, if the payment is still pending, rejects it with the reason "The order was cancelled." (stamped with whoever cancelled). **422** for an order that is not pending, and **422** when the payment is already **verified** (undo that first, so received money is never lost track of). **404** if missing. One transaction. |
| `` `GET /orders` `` | `view orders` | Page: every order of every session. On an AJAX request, the table feed: one row per live order with `customer_name`, `session_id` / `session_label` / `session_url` (null without `view order-sessions`), payment fields, `items[]`, `status_badge`, `payment_badge`, `proof_url` and the action URLs `complete_url` / `cancel_url` / `verify_url` / `reject_url` (each null unless it applies and the user may). Same row shape as the session page feed. |
| `` `GET /my-orders` `` | `view my orders` | Page: the caller's own orders, newest first, with status/payment badges and proof URL. |

`POST /orders` behaviour:

1. Session must exist (404) and be `live` (422).
2. Each line must still be on the menu and within `available_stock` (422 with the dish name).
3. Inside one DB transaction: stock is reserved with a conditional `UPDATE ... WHERE available_stock >= qty`
   (no overselling under concurrency), then order + items + payment row are written.
4. Success: `{"status":"success","message":"Order ORD-YYYYMMDD-0001 placed successfully.","order_number":"...","total_amount":0.0,"stock":{...}}`.
5. Order starts `order_status=pending`, `payment_status=pending`.


## System configuration — permission `view settings` / `edit settings`

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /settings` `` | `view settings` | Page. Read only (no Save button) without `edit settings`. |
| `` `PUT /settings` `` | `edit settings` | Body: `session_start_time`, `session_end_time` (both `H:i`, end after start). The default window the create order session drawer starts from. |

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
| `` `POST /users` `` | `create users` | `first_name`, `last_name`, `email` (unique), `password` (≥8, `confirmed`), `roles[]` (≥1). |
| `` `GET /users/{id}/edit` `` | `edit users` | JSON of one user (`first_name`, `last_name`, and `name` = full name) + role ids. The table feed rows carry the same three. |
| `` `PUT /users/{id}` `` | `edit users` | Password optional on update. |
| `` `DELETE /users/{id}` `` | `delete users` | Soft delete; **422** when deleting your own account. |

## Account & auth (Laravel Breeze)

| Endpoint | Access | Notes |
|---|---|---|
| `` `GET /` `` | public | Welcome page. |
| `` `GET /dashboard` `` | `auth`, `verified` | Dashboard: greeting, the live session, the pending-payment count (with `view payments`) and "Jump to" tiles for what the user may open. |
| `` `GET /profile` `` | auth | Profile form. |
| `` `PATCH /profile` `` | auth | Update `first_name`, `last_name`, `email`. |
| `` `DELETE /profile` `` | auth | Delete own account (needs current password). |
| `` `PUT /password` `` | auth | Change password. |
| `` `GET /register` `` | guest | Public self-registration (new users get **no role** — see security.md). |
| `` `POST /register` `` | guest | `first_name`, `last_name`, `email`, `password`. |
| `` `GET /login` `` | guest | |
| `` `POST /login` `` | guest | |
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
