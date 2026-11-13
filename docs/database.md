# Database

SQLite by default (`DB_CONNECTION=sqlite`); the schema is portable to MySQL (the code avoids SQLite-only SQL).
Source of truth: `database/migrations/`. `scripts/harness.php` fails if a created table is missing here (write each as `` `table` ``).

## Conventions

- Custom primary key names: `dish_id`, `dish_type_id`, `method_id`, `session_id`, `session_dish_id`, `order_id`, `order_item_id`.
  (`payments` uses `order_id` as its primary key: one payment per order.)
- Almost every domain table has `is_active` (bool) + `deleted_at` (soft delete) + timestamps. Always filter `whereNull('deleted_at')`.
- Models are thin: reads/writes are **static query-builder methods** (`Dish::getAll()`, `Order::createOrder()`), returning `stdClass`/arrays, not Eloquent chains.
- Migration filenames use a `2026_11_*` prefix; keep new ones later than the latest existing file.

## Tables

### Auth & permissions
| Table | Notes |
|---|---|
| `users` | Breeze users. `first_name`, `last_name` (both required; there is **no `name` column** since `2026_11_12_200000`, which split the old one on its last space — a single word became the first name with an empty last name). The full name is computed: `User::name` accessor in PHP, `User::fullNameSql($table)` in query builder joins (`TRIM(CONCAT(first, ' ', last))`, `||` on SQLite). `company` (nullable string, after `last_name`; `2026_11_13_200000` — optional, NULL for accounts that predate it or leave it blank). `google_id` (nullable, **unique**, after `email`; `2026_11_13_300000`): Google's `sub`, saved on the first "Continue with Google"; NULL for everyone else. `profile_completed_at` (nullable timestamp, after `email_verified_at`; `2026_11_13_400000`): when the person gave the details the app needs; NULL only for a Google sign-up that has not yet submitted `/complete-profile` (the `profile.complete` middleware holds them there). The migration set it on every existing row. `email`, `password`, soft deletes (`2026_11_10_100000`). |
| `password_reset_tokens`, `sessions` | Created with `users` (sessions are DB-backed). |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Laravel infrastructure (cache + queue = database). |
| `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` | Spatie. Role/permission rows are written via the query builder; `PermissionRegistrar` cache must be forgotten after direct writes. |

### Menu
| Table | Columns / notes |
|---|---|
| `dish_types` | `dish_type_id`, `type_name`, `description?`, `is_active`. |
| `dishes` | `dish_id`, `dish_name`, `description?`, `image?` (relative path on `public` disk), `price` decimal(10,2), `calories?`, `main_ingredients?`, `is_active`. The old `dish_type_id` column was dropped. |
| `dish_dish_types` | Pivot (many-to-many). `dish_id`, `dish_type_id`, unique pair, cascade on delete. |

### Ordering
| Table | Columns / notes |
|---|---|
| `payment_methods` | `method_id`, `method_name`, `account_name?`, `account_number?`, `qr_image?`, `is_active`. Protected name: *Cash on Pick up* (the only cash method; checkout needs no proof for it). |
| `order_sessions` | `session_id`, `created_by` → users, `order_date`, `start_time`, `end_time`, `ended_at?`, `is_active`. **Status is computed**, not stored: `scheduled` / `live` / `ended` (`OrderSession::statusOf`). |
| `session_dishes` | Dishes offered in a session: `session_id`, `dish_id` (unique pair), `available_stock`, `reserved_stock`, `is_available`, `is_active`. Cascade with session/dish. |
| `orders` | `order_id`, `session_id`, `user_id`, `order_number` unique (`ORD-YYYYMMDD-NNNN`), `order_date`, `total_amount`, `order_status` (`pending`/`completed`/`cancelled`), `payment_status` (`pending`/`verified`/`rejected`). |
| `order_items` | `order_item_id`, `order_id`, `session_dish_id`, `quantity`, `unit_price`, `sub_total` (price is copied at order time). |
| `settings` | System configuration, one row per key: `key` (primary), `value?` (text), timestamps. **No soft delete**: a setting is replaced, and an unsaved key falls back to `Setting::DEFAULTS`. Keys: `order_session.default_start_time` (09:00), `order_session.default_end_time` (17:00), `notifications.watchers` (JSON list of user ids who receive every notification, default `[]`; read by `Setting::notificationWatchers()`, a damaged value reads as nobody). |
| `user_notifications` | In-app notifications, one row per person told (migration `2026_11_13_100000`): `id`, `user_id` → users, `type` (`order_placed`, `payment_verified`, `payment_rejected`, `order_completed`, `order_cancelled`, `payment_resent`, `dish_sold_out`), `title` (160), `body?` (500), `url?` (a local path), `order_id?` (no foreign key on purpose: a notification never blocks an order), `read_at?`, `created_at`. The text is stored ready to print, so lists and counts join nothing. **No soft delete and no `updated_at`** (an exception to the soft-delete rule: it is not a record to keep; `php artisan notifications:prune` deletes read rows after 30 days and any after 90). Not called `notifications`: that is Laravel's own (uuid + morph) table, which the app does not use. Indexes: `(user_id, read_at, id)` unread count, `(user_id, id)` list / poll, `created_at` prune. Run `php artisan migrate`. |
| `payments` | PK `order_id`; `payment_method_id` → payment_methods, `verified_by?` → users, `verified_at?`, `amount`, `reference_number?`, `proof_of_payment?` (path on `public` disk), `payment_status`, `rejection_reason?` (max 500, set by a rejection, cleared by undo; added in `2026_11_12_100000`). `orders.payment_status` mirrors it. |

### Bulk orders
A parallel set of tables, **not** a type flag on `order_sessions` / `orders` (those assume one menu, stock and a separate `payments` table). Migrations `2026_11_13_2001..2004`; run `php artisan migrate`. All four are soft-deleted like the rest.

| Table | Columns / notes |
|---|---|
| `bulk_sessions` | `bulk_session_id`, `created_by` → users, `title?`, `order_date`, `start_time`, `end_time`, `min_quantity` (fewest plates, all dishes together, one order must hold; default 10), `ended_at?`, `is_active`. **Status is computed** like an order session (`BulkSession::statusOf`). Unlike order sessions, two open windows may not overlap (`BulkSession::overlaps`, enforced by the controller). |
| `bulk_session_dishes` | Dishes offered in a bulk session: `bulk_session_id`, `dish_id` (unique pair), `is_available`, `is_active`. **No stock columns**: a bulk session has no limit. A dish removed from the session is soft-deleted, not dropped (order lines point at it). |
| `bulk_orders` | `bulk_order_id`, `bulk_session_id`, `user_id`, `order_number` unique (`BLK-YYYYMMDD-NNNN`, own counter), `order_date`, `total_amount`, `order_status` (`pending`/`completed`/`cancelled`), and the **payment inline** (a bulk order has exactly one): `payment_method_id` → payment_methods, `payment_status` (`pending`/`verified`/`rejected`), `reference_number?`, `proof_of_payment?`, `rejection_reason?` (500), `verified_by?` → users, `verified_at?`. |
| `bulk_order_items` | `bulk_order_item_id`, `bulk_order_id`, `bulk_session_dish_id`, `quantity`, `unit_price`, `sub_total` (price copied at order time). |

## Relationships (summary)

```
users 1─* order_sessions 1─* session_dishes *─1 dishes *─* dish_types
                  │                  │
                  └─* orders 1─* order_items ──┘ (via session_dish_id)
                        │
                        └─1 payments *─1 payment_methods

users 1─* bulk_sessions 1─* bulk_session_dishes *─1 dishes
                  │                  │
                  └─* bulk_orders 1─* bulk_order_items ──┘ (via bulk_session_dish_id)
                        └─* payment_methods (payment is inline)
```

## Seeding

`php artisan migrate:fresh --seed` (or `db:seed`) runs `DatabaseSeeder`: test user + admin account → `UserSeeder` (Jan Leo Egamen, `jleoegamen6@gmail.com`; password `Password123!` unless `SEED_USER_PASSWORD` is set in `.env`) → `PermissionSeeder` (permissions, the Super Admin and Customer roles, and Super Admin attached to every account in `PermissionSeeder::SUPER_ADMIN_EMAILS`, so a new owner account is added there **and** created before this step)
→ payment methods (Cash on Pick up, GCash, Bank Transfer) → `MenuSeeder`.

`MenuSeeder` loads the real menu from two files in `database/seeders/data/`: `dish_types.csv` (every dish type + its description, 17 of them, all seeded even if no dish uses one yet) and `menu.csv` (one row per dish: **Dish Type Names**, name, description, price, calories, ingredients). A dish can be in many types, so "Dish Type Names" lists all of them separated by `;` (e.g. `Breakfast; Silog`); never write two types as `A / B`. A type named in `menu.csv` but missing from `dish_types.csv` is still created (without a description). A database seeded earlier with a combined type such as `Breakfast / Silog` is converted on the next run: its dishes move to `Breakfast` and `Silog` and the combined type is soft deleted (only when every part is a known type, so an admin-made `Kids / Party` stays). Types and dishes are matched by name and only created when missing, so re-running keeps prices and edits made in the app; the CSV prices are **0.00 placeholders** to be set in the app. A picture named after the dish slug in `database/seeders/data/images/` (e.g. `chicken-adobo.jpg`; jpg, jpeg, png, webp) is copied to the `public` disk (`dishes/`) and attached to a dish that has no image. `DishTypeSeeder` / `DishSeeder` are the older demo menu, now only run with `--class`. Load the real menu into an existing database with `php artisan db:seed --class=MenuSeeder`.
Seeders use `firstOrCreate` / `insertOrIgnore`, so they can be re-run.

## When you change something

| You change... | Also update |
|---|---|
| Add a migration | this file (table/column), `Model` `COLUMNS`/`$fillable`, factory, seeder if it needs starter data |
| Add a status value | enum in migration (a new migration — never edit an applied one), badge helper in `Controller.php`, api.md |
