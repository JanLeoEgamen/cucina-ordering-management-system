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

## Companies

The list behind the **Company** dropdown of register, complete-profile, profile and the Users drawers. Page: *Companies* (Access group of the sidebar). Same `{status,message}` replies as the other CRUD pages.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /companies` `` | `view companies` | Page / table feed: `company_id`, `company_name` (as typed, the page escapes it), `users_count` (live users in it), `is_active` (rendered badge), `action`. |
| `` `POST /companies` `` | `create companies` | `company_name` (required, ≤255, unique among live companies; a deleted company's name can be reused), `is_active`. |
| `` `GET /companies/{id}/edit` `` | `view companies` | JSON of one company: the fields above, `users_count` and the rendered `status` badge (the edit and the read-only *View* drawers both load it). **404** when deleted / unknown. |
| `` `PUT /companies/{id}` `` | `edit companies` | As create. **404** when deleted / unknown; **422** for a protected company (`Company::PROTECTED`: *Guest*). |
| `` `DELETE /companies/{id}` `` | `delete companies` | Soft delete. **422** for a protected company, and **422** while any live user still belongs to it (switch it off instead: an inactive company is no longer offered, its people keep it). |

## Discounts

What a customer can pick at checkout. Page: *Discounts* (Catalog group of the sidebar). Where discounts are offered at all (regular orders, pre-orders, both, nowhere) is System Configuration → Discounts (`discount_mode`, below). Same `{status,message}` replies as the other CRUD pages.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /discounts` `` | `view discounts` | Page / table feed: `discount_id`, `discount_name` (as typed, the page escapes it), `percent`, `requires_proof`, `valid_from`, `valid_until`, `is_active`, `orders_count` + `pre_orders_count`, and the rendered columns `status` (badge: *Active* / *Inactive* / *Scheduled* / *Expired*), `proof`, `window`, `used` (orders + pre-orders that used it), `action`. |
| `` `POST /discounts` `` | `create discounts` | `discount_name` (required, ≤255, unique among live discounts; a deleted one's name can be reused), `percent` (required, `0.01`–`100`, at most 2 decimals; 100 makes the order free), `requires_proof` (bool: the customer must upload an ID and staff approve it), `valid_from` / `valid_until` (optional `Y-m-d`, both days included; `valid_until` not before `valid_from`; empty = no limit on that side), `is_active`. |
| `` `GET /discounts/{id}/edit` `` | `view discounts` | JSON of one discount: the fields above, `used` and the rendered `status` / `window` / `proof` (the edit and the read-only *View* drawers both load it). **404** when deleted / unknown. |
| `` `PUT /discounts/{id}` `` | `edit discounts` | As create. **404** when deleted / unknown. Orders already placed keep their own copy of the name and percent. |
| `` `DELETE /discounts/{id}` `` | `delete discounts` | Soft delete, always allowed (orders keep their copy). **404** when deleted / unknown. |

**A discount on an order** (`POST /orders`, `POST /pre-orders`, multipart; the shared rules are `App\Support\OrderDiscount`). Extra fields: `discount_id?` (integer), `expected_discount_percent?` (0–100: the percent the cart showed) and `discount_proof?` (a **raster** picture, `jpeg` / `png` / `jpg` / `webp`, ≤ 2 MB: SVG, PDF and the rest are refused). The server decides everything else:

1. It is judged on the discount **as it is now**: System Configuration must offer discounts for this kind of order (`Setting::discountsFor('orders'|'pre_orders')`) and the discount must be live, switched on and inside its dates (`Discount::usable()`), else **422** `That discount is not available any more.`. When `expected_discount_percent` is sent and differs (compared in cents) the order is refused with `The discount changed: X is now N%.` Both answers carry the discounts on offer now (`discounts`: `[{id, name, percent, requires_proof}]`, next to the `stock` / `prices` of the stock refusal on orders, and the `prices` / limits of the review refusal on pre-orders) and store nothing; the checkout drops a discount that went away and shows a moved percent.
2. A discount with `requires_proof` and no `discount_proof` is a validation **422** on `discount_proof`, before any stock is taken.
3. The math is `discount = round(subtotal × percent / 100, 2)`, `total = subtotal − discount` (cents-safe; `Discount::amounts()`). The order stores `subtotal_amount`, `discount_amount`, `total_amount`, a **copy** of `discount_name` / `discount_percent`, and `discount_status`: `pending` (needs an ID: staff decide), or `approved` at once for a discount that needs none. The payment row's `amount` is the **discounted** total.
4. The ID is stored on the **private** `local` disk (`discount-proofs/`, `pre-order-discount-proofs/`), never the public one; it is deleted again when the order is not written.
5. The success body gains `subtotal_amount`, `discount_name`, `discount_amount` and `discount_status` (`none` without a discount).

While `discount_status = pending` an order cannot be verified (`payments.verify` **422** `Approve or reject the discount ...`), completed or announced as ready; the order popup says so and offers only the discount's decision.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `POST /orders/{id}/discount/approve` `` | `review discounts` | `{id}` is the **order** id. `pending` → `approved`; stamps `discount_decided_by` / `discount_decided_at`; the total stays. **404** unknown order; **422** unless the order is `pending` and its discount is `pending` (also when someone decided it a moment earlier: the order row is locked and judged again). Notifies the customer (`discount_approved`). |
| `` `POST /orders/{id}/discount/reject` `` | `review discounts` | Body `reason` (required, ≤500: the customer reads it). `pending` → `rejected`: no discount any more, `total_amount` goes back to `subtotal_amount`, `discount_amount` 0, and **the payment's amount follows**. A bank / e-wallet payment that was still `pending` was paid for the smaller amount, so it is **rejected** with a reason that names the new total (the customer sends a new proof for the full amount or switches to cash, the usual way out of a rejected payment; the plates stay held); a Cash on Pick up payment simply owes the new total at the counter. Same 404 / 422 rules as approve. Notifies the customer (`discount_rejected`: reason, old and new total, what to do). |
| `` `GET /orders/{id}/discount-proof` `` | the order's own customer, or `review discounts` / `view orders` | Streams the ID from the private disk (`Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`). **404** for everyone else and for an order with no ID, so an id cannot be probed. |

## Payments

Every payment of every order. The payment row is created with the order, and its **order id is its key**, so `{id}` is an order id.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /payments` `` | `view payments` | Page / table feed (order, customer, method + `payment_method_id`, reference, amount, proof URL, status, lines, `created_at`, and the order's `session_id`, `session_date`, `start_time`, `end_time` — what the page's filters read). Status badge and row buttons are rendered by the server. The row has **View** (a popup filled from the row, so no `show` endpoint) and **Delete**; **Verify** and **Reject** live inside the popup. For a pending payment the row also carries `verify_url` / `reject_url` (null unless the payment is pending and the user holds the matching permission) and, for a decided payment, `undo_url` (null unless the user may undo); `rejection_reason` is included. **Paged (what the page uses):** a request that carries `draw` (DataTables `serverSide`) is answered with ONE page, `{draw, recordsTotal, recordsFiltered, data[], filters: {methods: [{id, name}], sessions: [{id, date, start, end}]}}` (`filters` fills the page's method / session dropdowns). It reads `start`, `length` (at most 500; -1 / "all" is not honoured), `search[value]` (every word must be found: customer, number, session date, method, reference, status, amount), `order[0][column|dir]` (column 1 number, 2 customer, 3 method, 4 reference, 5 amount, 7 status, 8 date) and the page's filters `status` (the payment's), `method`, `session`, `from`, `to` (`from` / `to` are `Y-m-d`; an unknown value filters nothing). Without `draw` the whole list is answered, as before (scripts, tests). See `App\Support\ServerTable`. |
| `` `POST /payments/{id}/verify` `` | `verify payments` | Pending → verified; stamps `verified_by` / `verified_at` (the reviewer) and mirrors `orders.payment_status`, in one transaction. **404** if missing, **422** unless the payment is pending, and **422** for a **Cash on Pick up** payment (it is verified when its order is completed) and for the payment of a **cancelled** order. |
| `` `POST /payments/{id}/reject` `` | `reject payments` | Body: **`reason`** (required, trimmed, max 500; otherwise **422** on `reason`). Pending → rejected, same reviewer stamp and order mirror as verify; the reason is stored and shown to the customer on **My Orders**. **404** if missing, **422** unless the payment is pending, and **422** for a **Cash on Pick up** payment. |
| `` `POST /payments/{id}/undo` `` | `undo payments` | Verified or rejected → **pending**: clears `verified_by`, `verified_at` and `rejection_reason`, and the order mirrors pending again, in one transaction. **404** if missing, **422** if the payment is already pending. Undoing a verified payment makes it deletable again. **422** unless its order is still pending (a completed or cancelled order keeps its payment decision). |
| `` `DELETE /payments/{id}` `` | `delete payments` | Soft delete. **404** if missing, **422** for a **verified** payment (the record of money received is kept). |

## Order sessions (a "selling window" for a set of dishes)

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /order-sessions` `` | `view order-sessions` | Page / table feed (includes computed `status`: `scheduled` / `live` / `ended`). |
| `` `POST /order-sessions` `` | `create order-sessions` | `order_date`, `start_time` `H:i`, `end_time` `H:i` (after start), `dish_ids[]` (≥1), `stocks[dish_id]` (int ≥0). **422** (error on `start_time`: "Another order session is already open in this time window.") when the window overlaps another order session that is still open: an ended or deleted one does not count and back-to-back windows (12:00 then 12:00) are fine. |
| `` `GET /order-sessions/{id}` `` | `view order-sessions` | **Session page** (replaces the old View drawer): facts, sales totals, dishes and stock, and the orders as a table / cards. With an AJAX request the same URL returns the orders feed (DataTables JSON: `order_number`, `customer_name`, `items[]`, `items_count`, `total_amount`, `method_name`, `reference_number`, `proof_url`, `status_badge`, `payment_badge`, `order_date`, plus `ready_at` and `ready_url` (set while the order is pending, not announced yet, its payment is settled like for `complete_url`, and the user holds `complete orders`), plus `verify_url` / `reject_url`: set only while the order's payment is pending and only for a user who holds `verify payments` / `reject payments`, otherwise null (and always null for Cash on Pick up) — the order details show Verify / Reject buttons from them; also `is_cash` and `complete_url` (set while the order is pending, its payment is settled — cash: pending or verified, others: verified — and the user holds `complete orders`) and `cancel_url` (set while the order is pending, its payment is **not** verified, and the user holds `cancel orders`), and they call `POST /payments/{id}/verify` and `/reject`). **404** if the session does not exist. |
| `` `GET /order-sessions/{id}/edit` `` | `edit order-sessions` | JSON of the session with its dishes and stock (edit drawer). |
| `` `PUT /order-sessions/{id}` `` | `edit order-sessions` | As create, plus `loaded_stocks[dish_id]` (int ≥0, optional): the stock each box showed when the edit drawer was opened. For a dish that is live in the session and has a loaded value, the stock becomes `max(0, current + (stocks − loaded_stocks))` in one UPDATE — orders placed while the drawer was open are kept, and `reserved_stock` is untouched. Without a loaded value (new dish, dish put back, old client) `stocks[dish_id]` is the stock as before. `POST` ignores `loaded_stocks`. The overlap check (422 on `start_time`) ignores the session itself and runs **only when the date or times changed** and the session is not ended: a stock / dish edit of a session that already overlapped another before the check existed still saves. |
| `` `POST /order-sessions/{id}/end` `` | `end order-sessions` | Closes the session by hand. **403** unless the caller is the creator (`created_by`); **422** if already ended. |
| `` `POST /order-sessions/{id}/announce-ready` `` | `complete orders` | The session page's **Ready for Pick up** button: announces every order of the session that qualifies (pending, not announced, payment verified or cash) in one go; the rest are skipped. Returns `{status,message}` with how many customers were told. **422** when none qualifies, **404** if the session is missing. Works on ended sessions too. |
| `` `DELETE /order-sessions/{id}` `` | `delete order-sessions` | **422** while the session has orders. |

## Pre-order sessions (staff; the window in which customers place big orders)

Separate from order sessions: no stock, a minimum number of plates per order, and at most one open window at a time. See [architecture.md](architecture.md#pre-orders-separate-from-the-menu).

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /pre-order-sessions` `` | `view pre-order-sessions` | Page / table feed (AJAX: one row per session with `title`, `order_date`, `start_time`, `end_time`, `min_quantity`, `dishes_count`, `orders_count`, `created_by_name`, the computed `status` badge `scheduled` / `live` / `ended`, and `action` buttons; `Edit` / `End` / `Delete` appear only with their permission, `End` only for the creator). |
| `` `POST /pre-order-sessions` `` | `create pre-order-sessions` | `title` (optional, ≤120), `order_date`, `start_time` `H:i`, `end_time` `H:i` (after start), `min_quantity` (int, **inside the System Configuration limits**: ≥ the system minimum and ≤ the system maximum when one is set; else 422 on `min_quantity`), `dish_ids[]` (≥1, existing dishes). **No stock fields.** **422** (error on `start_time`) when the window overlaps another pre-order session that is still open (an ended one does not count). |
| `` `GET /pre-order-sessions/{id}` `` | `view pre-order-sessions` | **Pre-order session page**: facts (date, time, minimum, creator, orders count) and the dishes with their price; End Session for the creator. **404** if missing. (Its orders table joins this page with the order lifecycle.) |
| `` `GET /pre-order-sessions/{id}/edit` `` | `edit pre-order-sessions` | JSON of the session with its dishes (edit drawer). **404** if missing. |
| `` `PUT /pre-order-sessions/{id}` `` | `edit pre-order-sessions` | As create. The overlap check ignores the session itself, and is skipped when the session has already ended. The dish list is replaced: an unticked dish leaves the session (its row is soft-deleted, order lines keep pointing at it). **404** if missing. |
| `` `POST /pre-order-sessions/{id}/end` `` | `end pre-order-sessions` | Closes the session by hand. **403** unless the caller is the creator; **422** if already ended; **404** if missing. |
| `` `DELETE /pre-order-sessions/{id}` `` | `delete pre-order-sessions` | **422** while the session has orders; **404** if missing. |

## Pre-ordering (customer side)

The customer half of [pre-orders](architecture.md#pre-orders-separate-from-the-menu): its own menu, its own order endpoint, no stock. Nothing here touches `orders`, `payments` or `session_dishes`.

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /pre-order-menu` `` | `view pre-order-menu` | Page: the dishes of the **live pre-order session** (`PreOrderSession::live()`), its title, window and minimum plates; the cash method and the bank / e-wallet methods for checkout. A friendly message when no pre-order session is running or it offers no dish. No stock, no polling. |
| `` `POST /pre-orders` `` | `create pre-orders` | Place an order. **Throttled `10/min`.** Multipart: `pre_order_session_id`, `payment_method_id` (an active method), `items[pre_order_session_dish_id]` = plates (int 1–10000 each), `expected_prices[pre_order_session_dish_id]` (optional, the unit price the page showed), `idempotency_key` (optional, ≤64), `reference_number` (optional), `proof_of_payment` (image ≤2 MB, **required unless the method is Cash on Pick up**). Success `200`: `{status, message, order_number, total_amount, plates}`. **404** unknown session; **422** when the session is not `live` (`This pre-order session is not running.`); **409** while the same customer's other pre-order is being placed. **422 "review your cart"** — `{status:'error', message, prices:{pre_order_session_dish_id: price}, min_quantity, max_quantity}` (`max_quantity` is `null` for no limit) — when a price changed, a dish is no longer on the pre-order menu (it is missing from `prices`), or the total plates are outside the limits **as they are now**: below the minimum (the larger of the session's `min_quantity` and the System Configuration minimum, brought down to the maximum if that is lower) or above the System Configuration maximum. Standard Laravel 422 (`errors`) for the field rules. Lines are priced at the dish price of now and copied into `pre_order_items`; the payment (`pending`) is stored on the order row. A retry with the same `idempotency_key` returns the first receipt (10 min) instead of a second order; the lock and receipt cache keys (`place-pre-order:`, `pre-order-receipt:`) are separate from the regular checkout's. |
| `` `GET /my-pre-orders` `` | `view my pre-orders` | Page: the signed-in customer's pre-orders, newest first, **10 a page** (`?page=N`; a page past the end redirects to the last one; the numbered pager is drawn only when there is more than one page). Cards with `id="pre-order-N"`: lines, plates, method, reference, proof link, the rejection reason; each card carries its popup data in `data-order`. Only their own rows. **`?order=ID`** redirects to the page that holds that order, at its card (`…?page=N#pre-order-ID`); an id that is not theirs or does not exist is ignored (the list opens as usual, nothing is revealed). |

## Ordering (customer side)

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /menu` `` | `view menu` | Page: dishes of the single *live* session, stock left, payment options (cash + bank/e-wallet). |
| `` `GET /menu/stock` `` | `view menu` | **Live stock feed** (query: `session_id`), polled by the menu every ~1 s (AJAX, JSON, `Cache-Control: no-store`). `{"status":"success","live":true,"stock":{"<session_dish_id>":left,...},"prices":{"<session_dish_id>":100.0,...}}`. `prices` is the current unit price of each offered dish, so a price an admin changes shows up on the menu within seconds. `live` is `false` (and `stock` and `prices` empty) when the session does not exist, has not started or has ended — the menu then stops polling and tells the customer. Dishes that were turned off or left the session are absent from `stock` (the menu shows them sold out). |
| `` `POST /orders` `` | `create orders` | `multipart`. Body: `session_id`, `payment_method_id` (must be **active** right now, else 422 on `payment_method_id`), `items[session_dish_id]=qty` (≥1 line), `reference_number?`, `idempotency_key?` (string ≤64, one per checkout), `expected_prices[session_dish_id]?` (number ≥0: the unit price each line showed; see "Price changes" below), `proof_of_payment` (image ≤2 MB; **required unless the method is Cash on Pick up**, else 422 on `proof_of_payment`). **Throttled 10 / minute per user** (429). |
| `` `POST /orders/{id}/complete` `` | `complete orders` | Hand-over: `pending` → `completed`. **Cash on Pick up**: the payment is verified by the completion itself (stamped with whoever completed it). **Any other method**: the payment must already be `verified` (it can be checked as soon as the order exists) — otherwise **422**. Also **422** for an order that is not pending or whose payment was rejected; **404** if missing. One transaction. |
| `` `POST /orders/{id}/ready` `` | `complete orders` | Announce **one** order is ready for pick up: sets `orders.ready_at` and notifies its customer (and the watchers). The order **stays `pending`**. Same payment rule as completing (cash: pending or verified; others: verified). **422** if not pending, already announced, or the payment is rejected / not verified; **404** if missing. |
| `` `POST /orders/{id}/cancel` `` | `cancel orders` | Call a **pending** order off: `pending` → `cancelled`. Gives the reserved stock back (`SessionDish::release`, per line) and, if the payment is still pending, rejects it with the reason "The order was cancelled." (stamped with whoever cancelled). **422** for an order that is not pending, and **422** when the payment is already **verified** (undo that first, so received money is never lost track of). **404** if missing. One transaction; the order row is locked (`lockForUpdate`) and re-checked inside it, so two people pressing Cancel together give the stock back **once** (the second gets 422). Complete and Verify / Reject take the same lock. |
| `` `GET /orders` `` | `view orders` | Page: every order of every session. On an AJAX request, the table feed: one row per live order with `customer_name`, `session_id` / `session_label` / `session_url` (null without `view order-sessions`), payment fields, `items[]`, `status_badge`, `payment_badge`, `proof_url` and the action URLs `complete_url` / `cancel_url` / `verify_url` / `reject_url` (each null unless it applies and the user may). Same row shape as the session page feed. **Paged (what the page uses):** a request that carries `draw` (DataTables `serverSide`) is answered with ONE page, `{draw, recordsTotal, recordsFiltered, data[], filters: {methods: [{id, name}], sessions: [{id, date, start, end}]}}` (`filters` fills the page's method / session dropdowns). It reads `start`, `length` (at most 500; -1 / "all" is not honoured), `search[value]` (every word must be found: customer, number, session date, method, reference, status, amount and a dish of the order), `order[0][column|dir]` (column 1 number, 2 customer, 3 session, 5 total, 7 status, 8 date) and the page's filters `status` (order), `payment` (payment status), `method`, `session`, `from`, `to` (`from` / `to` are `Y-m-d`; an unknown value filters nothing). Without `draw` the whole list is answered, as before (scripts, tests). See `App\Support\ServerTable`. |
| `` `GET /my-orders` `` | `view my orders` | Page: the caller's own orders, newest first, **10 a page** (`?page=N`; a page past the end redirects to the last one; **search and filters** as query parameters of a plain GET form, kept by the pager: `q` (max 100 chars; matches the order number, a dish name, the payment method or the reference number; `%` / `_` are literal), `status` (`pending|completed|cancelled`), `payment` (`pending|verified|rejected`), `from` / `to` (`Y-m-d`, the day the order was placed, both ends included); a value that is not one of these is ignored, never an error, and a filter can only narrow the caller's OWN list; with a filter on and no match the page says "No orders match"; `?order=ID` ignores the filters; the numbered pager only when there is more than one page), with status/payment badges and proof URL. Each card carries what the order popup shows in `data-order` (JSON: number, date, session label, method, reference, total, badges, proof URL, lines; **never** another customer's data, staff fields or action URLs). **`?order=ID`** redirects to the page that holds that order, at its card (`/my-orders?page=N#order-ID`; page 1 has no `page`): this is what a notification links to, because an `#anchor` alone cannot reach a card on another page. An id that is not the caller's or does not exist is ignored. A **pending** order whose payment was **rejected** also gets `reupload_url` (not for Cash on Pick up) and `cash_url` (only when an active Cash on Pick up exists), each set only for a user holding `create orders`; the page shows the "Fix your payment" panel from them. |
| `` `POST /my-orders/{id}/payment/proof` `` | `create orders` | The customer sends a **new proof** for a rejected payment (same method). `multipart`: `proof_of_payment` (image ≤2 MB, required), `reference_number?` (omit to keep the old one). The payment goes back to `pending` (reviewer, moment and rejection reason cleared, the replaced picture deleted) and the order's `payment_status` mirrors it; staff verify or reject it again. **404** when the order is not the caller's (or missing); **422** when the order is not `pending`, the payment is not `rejected`, or the method is Cash on Pick up; 422 on `proof_of_payment` when missing / not an image. Throttled 10 / minute. |
| `` `POST /my-orders/{id}/cancel` `` | `create orders`, `throttle:10,1` | The customer **cancels their own pending order**, only while System Configuration → *Customers can cancel their own order* is on (off by default). **404** for an order that is not theirs; **403** `{status:failed}` while the switch is off (read on every request); **422** when the order is not pending (completed, cancelled, a double tap) or its payment is **verified** (only staff cancel then, after undoing it). Same code path as `POST /orders/{id}/cancel`: the plates go back (`SessionDish::release`) and a pending payment is rejected (reason "The customer cancelled the order.", no reviewer stamped); a rejected payment is left as it is. Notifies the customer ("You cancelled your order…") and the watchers ("X cancelled their order"). |
| `` `POST /my-orders/{id}/discount/proof` `` | `create orders`, `throttle:10,1` | The customer **sends a new ID for a rejected discount** (`multipart`: `discount_proof`, required, raster picture ≤ 2 MB). Owner-only (**404** for another customer's order). Allowed only while System Configuration still offers discounts on orders (else **422**), the order is `pending`, its discount is `rejected` and its payment is not `verified` (**422** otherwise, "ask the staff"). The order row is locked and judged again inside. The discount (the percent the order was placed with, kept on the order) is applied again: `discount_status` → `pending`, `discount_amount` and `total_amount` go back to the discounted figures, the old decision (reason, reviewer, moment) is cleared, the new ID replaces the old file (deleted) and the payment's `amount` follows. A bank / e-wallet payment still `pending` was for the full price, so it is **rejected** with a reason naming the new total (a new proof or cash, as usual; the plates stay held); cash just owes the smaller amount. Notifies staff only (`discount_resent`: watchers). Then staff approve or reject it again. |
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
8. **Discount** — optional `discount_id` / `expected_discount_percent` / `discount_proof`, judged after the price check
   and before any stock is taken (rules, refusals and the stored copy: see *Discounts*). `total_amount` in the answer
   and the payment's `amount` are the discounted total. The staff feed rows (`GET /orders`, the session page) carry
   `subtotal_amount`, `discount_name`, `discount_percent`, `discount_amount`, `discount_status`, `discount_badge`,
   `discount_reject_reason`, `discount_proof_url` (the guarded route, never the file path) and
   `discount_approve_url` / `discount_reject_url` (only while the discount is pending, the order is pending and the
   user holds `review discounts`); `verify_url`, `complete_url` and `ready_url` are `null` while it is pending. A
   customer's own order popup (`GET /my-orders`, `GET /my-pre-orders`) gets the discount name / percent / amount /
   status / badge / reason and **no** proof URL, reviewer or action URL. The pre-order menu (`GET /pre-order-menu`) and the
   menu (`GET /menu`) are given the discounts on offer (`Discount::offered()`, none while the switch is off).


## System configuration — permission `view settings` / `edit settings`

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /settings` `` | `view settings` | Page. Read only (no Save button) without `edit settings`. |
| `` `PUT /settings` `` | `edit settings` | **Multipart** (the form carries a file). Body: `discount_mode` (optional; `off` / `orders` / `pre_orders` / `both`, anything else is 422; where customers are offered a discount, default `off`; read fresh on every checkout, so switching it off stops the very next order; a caller that leaves it out changes nothing), `app_name` (optional string, trimmed, 1–60 chars: the name shown in the menu, titles, sign in pages, error pages and emails; empty / too long = 422 on `app_name`; a caller that leaves it out changes nothing), `app_logo` (optional file: **JPG / PNG / WebP only, ≤ 2 MB**, 422 on `app_logo` for anything else, **SVG and GIF included**; stored on the `public` disk under `branding/` with a generated name; the old file is deleted only after the new one is saved), `remove_app_logo` (optional `1`: back to the built-in mark; ignored when a new file is sent). A refused save stores no file. `session_start_time`, `session_end_time` (both `H:i`, end after start). The default window the create order session drawer starts from. `notification_watchers[]` (optional): the ids of the users who receive a copy of **every** notification; each must be an existing, non-deleted user (else 422 on `notification_watchers.N`). The form always sends an empty entry, so un-picking everybody saves "nobody"; a caller that leaves the key out changes nothing. `customer_cancel` (optional, `0` / `1`): let customers cancel their own pending order from My Orders (the form always sends it; a caller that leaves it out changes nothing; 422 on anything else). `email_notifications` (optional, `0` / `1`): also send every notification by email (the form always sends it; a caller that leaves it out changes nothing; 422 on anything else), `google_chat_notifications` (optional, `0` / `1`, same rules): also send every notification as a Google Chat direct message. `pre_order_min_quantity` (int 1–100000) and `pre_order_max_quantity` (optional int, ≥ the minimum, ≤100000; **empty = no maximum**): the fewest / most plates one **pre-order** may hold, all dishes together. Sent together by the form; a caller that leaves `pre_order_min_quantity` out changes neither. 422 on `pre_order_min_quantity` / `pre_order_max_quantity`, and a refused save changes nothing (the whole save is validated first). `privacy_policy_content` / `terms_of_service_content` (optional, nullable string, **at most 15,000 characters**, else 422): the text of the public **privacy policy** and of the public **terms of service** in **Markdown** (CRLF is stored as LF; empty, or exactly the built-in text, is stored as an empty value = follow the built-in text in `resources/legal/privacy-policy.md` / `terms-of-service.md`). `privacy_policy_contact_email` / `terms_of_service_contact_email` (optional, nullable email, at most 120): the address shown under that page. A caller that leaves a field out changes nothing; a value that did not change is not rewritten, so a page's "Last updated" date only moves with a real edit of that page. `support_content` (optional, nullable string, at most 15,000 characters, else 422): the Markdown help text of `GET /support` (empty, or exactly the built-in text, = follow the built-in one in `resources/support/default.md`). `support_email` (optional, nullable email, at most 120), `support_phone` (optional, nullable, at most 40, only digits, spaces and `+ ( ) - .`, else 422) and `support_hours` (optional, nullable, at most 120): the contact details shown on that page. A caller that leaves a field out changes nothing; a value that did not change is not rewritten. |

## Notifications — no permission (every query is scoped to the signed-in user)


A person's own in-app notifications; see [architecture.md](architecture.md) for who is told what. There is deliberately no permission: like the profile, a user can only ever read and change their own rows, and a notification they cannot act on simply has no link. Guests are redirected to login (401 for JSON).

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /notifications` `` | — (own) | Page: the user's notifications, newest first, 20 a page (numbered pagination, `?page=N`: "Showing 21–40 of 87", Previous / Next and page numbers, a short "Page 2 of 5" on phones; one `COUNT(*)` over the caller's own rows; a page past the end redirects to the last page), unread ones highlighted, *Mark all as read*. |
| `` `GET /notifications/feed` `` | — (own) | What the bell polls (about every second, only while the tab is visible; `Cache-Control: no-store`). Without `after`: `{"status":"success","unread":N,"latest_id":N,"items":[]}` — count and newest id only. With `?after=ID`: also `items` — what arrived after ID, oldest first, at most 10: `{id, type, title, body, open_url}` (almost always empty). With `?recent=1` (the bell's panel was opened, or something new arrived while it is open): also `recent` — the person's latest 6, newest first: `{id, title, body, open_url, unread, time}` (`time` is a human "5 minutes ago"); the poll never sends `recent`, so the list is only read when the panel is shown. |
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
| `` `POST /users` `` | `create users` | `first_name`, `last_name`, `company_id` (**required**: an active company, see *Companies*), `email` (unique), `password` (≥8, `confirmed`), `roles[]` (≥1). |
| `` `GET /users/{id}/edit` `` | `edit users` | JSON of one user (`first_name`, `last_name`, `company_id`, `company` = the company's name (null when none, e.g. an account that predates companies), and `name` = full name) + role ids. The table feed rows carry the same fields. |
| `` `PUT /users/{id}` `` | `edit users` | Password optional on update. `company_id` is required here too; the user's **own** company stays valid even if it was switched off since (so renaming such a user does not fail), but nobody can be moved *into* an inactive or deleted one. |
| `` `DELETE /users/{id}` `` | `delete users` | Soft delete; **422** when deleting your own account or any Super Admin account. |

## Audit trail — permission `view audit-logs` (read only)

No route creates, edits or deletes an entry (`POST` / `PUT` / `DELETE` on these paths answer 405). Filters, shared by the page and the export, are validated (a bad one is a 422 / redirect with the error): `q` (≤100; matches description, record, person, email, action, message, IP), `event` (`auth` / `action` / `view` / `system`), `outcome` (`success` / `failed` / `denied` / `error`), `user` (id), `from`, `to` (`Y-m-d`).

| Endpoint | Permission | Notes |
|---|---|---|
| `` `GET /audit-logs` `` | `view audit-logs` | Page: newest first, a page at a time with a numbered pager and a "Showing x–y of z" line (always drawn, a single page too; nothing when the list is empty). Query: the filters `q`, `event`, `outcome`, `user`, `from`, `to`, plus `page` and **`per_page`** (one of **10 / 25 / 50 / 100**, default 25; anything else is a 422 / session error on `per_page`; the page links and the "Rows per page" links keep every filter, and the size links go back to page 1). A page past the end redirects to the last page. The CSV export below ignores `per_page`. |
| `` `GET /audit-logs/export` `` | `view audit-logs` | CSV of the filtered list, newest first, **at most 10 000 rows** (`text/csv`, `audit-log-YYYYMMDD-HHMMSS.csv`); cells starting with `= + - @` get a leading `'`. The export is itself logged (`audit-logs.export`). |
| `` `GET /audit-logs/{id}` `` | `view audit-logs` | One entry: who, when, result, the app's message, the record, what changed (before / after) and what was sent. **404** for an unknown id. |

## Account & auth (Laravel Breeze)

| Endpoint | Access | Notes |
|---|---|---|
| `` `GET /` `` | public | Welcome page. While an order session is live (and/or a pre-order session is running) it previews their dishes (name, photo, price, "Sold out"; never stock numbers), up to 8 each. A visitor's "Log in to order" button is a plain link to `GET /menu` / `GET /pre-order-menu`, so the `auth` middleware sends them to login and they come back to it. |
| `` `GET /support` `` | public | **Help and Support** page (no login, no permission; visitors and a user who has not finished their profile can read it). Shows the contact details an admin filled in (email as `mailto:`, phone as `tel:` with digits and `+` only, opening hours; each only when set) over the help text saved in System Configuration → Support, else the built-in FAQ (`resources/support/default.md`). Markdown rendered with raw HTML escaped and unsafe links dropped, `{app_name}` becomes the app name. Linked from the welcome footer, the sign in / sign up pages, the account menu and the footer of the other public pages. |
| `` `GET /privacy-policy` `` | public | **Privacy policy** page (no login, no permission; a user who has not finished their profile can read it too). Shows the text saved in System Configuration → Privacy policy, else the built-in one (`resources/legal/privacy-policy.md`). The Markdown is rendered with raw HTML escaped and unsafe links dropped, `{app_name}` becomes the app name, "Last updated" shows once the page was saved and a *Write to us* block shows when its contact email is set. Linked from the welcome footer, the sign in / sign up pages, the register form and the account menu. |
| `` `GET /terms-of-service` `` | public | **Terms of service** page: the rules of ordering (payment, pick up, cancelling). Same behaviour as `GET /privacy-policy` with its own text (System Configuration → Terms of service, else `resources/legal/terms-of-service.md`), its own contact email and its own "Last updated". Both are served by `LegalPageController::show()` (route default `page`). |
| `` `GET /dashboard` `` | `auth`, `verified` | Dashboard: greeting, the live session, the pending-payment count (with `view payments`) and "Jump to" tiles for what the user may open. |
| `` `GET /profile` `` | auth | Profile form. |
| `` `PATCH /profile` `` | auth | Update `first_name`, `last_name`, `company_id` (**required**; the dropdown lists the active companies plus the person's own), `email`. |
| `` `GET /profile/google-chat/status` `` | auth | The Profile card's **"synced with Google Chat"** indicator, asked by the page after it has loaded. No permission (only about the caller); throttled 20 / minute. One **live** Google lookup of the caller's chat with the Chat app (not the remembered one). The first time it finds the chat (`connected`) it also sends the person the app's **Welcome message** (`GoogleChat::welcome`, once, tracked in `users.google_chat_welcomed_at`); after that nothing is sent. Also marks the login session as checked (`google_chat_checked`), so the sign-in prompt asks only once per login. Returns `{status:'success', message, state, welcomed}` (`welcomed` true only when the Welcome message went out just now) with `state` = `connected` (their chat exists) / `not_added` (Google says it does not: they have not opened the app) / `error` (key, token or Google failed; never says why) / `no_google` (never signed in with Google, so the app cannot find them; no Google call). **404** while *Send notifications to Google Chat* is off or the key is not set up. |
| `` `DELETE /profile` `` | auth | Delete own account (needs current password). |
| `` `PUT /password` `` | auth | Change password. |
| `` `GET /register` `` | guest | Public self-registration (new users get the **Customer** role — see security.md). |
| `` `POST /register` `` | guest, `throttle:register` (5 / minute and 30 / hour per IP, **429** past it; invalid attempts count) | `first_name`, `last_name`, `company_id` (**required**, an active company chosen from the form's dropdown; the register page lists `Company::options()` and says so when there are none), `email`, `password`. Redirects to the page the visitor was sent away from (`url.intended`, e.g. the menu), else the dashboard. |
| `` `GET /login` `` | guest | |
| `` `POST /login` `` | guest | |
| `` `GET /complete-profile` `` | auth (outside `profile.complete`) | "One last step" form for a Google sign-up: `first_name`, `last_name` (prefilled) and the `company_id` dropdown. Redirects to `/dashboard` when the profile is already complete. |
| `` `POST /complete-profile` `` | auth (outside `profile.complete`) | `first_name`, `last_name`, `company_id`, all **required** (the company must be an active one). Sets `profile_completed_at`, then goes to the intended URL / `/dashboard`. A complete user is redirected, their details untouched. **Gate:** every other signed-in page (everything in `web.php` behind `auth`, including `/dashboard`) answers an incomplete user with a redirect here, or **403** JSON `Complete your profile first.` for AJAX; logout is not gated. |
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
