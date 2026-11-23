<?php

namespace App\Models;

use App\Support\TableCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Orders placed inside an order session. Same shape as DishType: query builder
// reads and writes below, with the soft delete filter written in every method.
class Order extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'orders';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'order_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'session_id',
        'user_id',
        'order_number',
        'order_date',
        'total_amount',
        'order_status',
        'payment_status',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'total_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The orders of one session, with the customer, the payment and the lines,
    // for the orders list of the session page.
    public static function ofSession(int $sessionId): array
    {
        return self::list($sessionId);
    }

    // Every live order of every session, newest first, for the Orders page.
    public static function getAll(): array
    {
        return self::list();
    }

    // The orders of one session, or of all of them, with the customer, the session
    // they were placed in, the payment and the lines.
    protected static function list(?int $sessionId = null): array
    {
        return TableCache::remember(['orders', 'users', 'order_sessions', 'payments', 'payment_methods', 'order_items', 'session_dishes', 'dishes'], 'order.list.' . ($sessionId ?? 'all'), fn () => self::loadList($sessionId));
    }

    // The queries behind list().
    protected static function loadList(?int $sessionId): array
    {
        $orders = DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('order_sessions', 'order_sessions.session_id', '=', self::TABLE . '.session_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', 'payments.verified_by')
            ->when($sessionId !== null, fn ($query) => $query->where(self::TABLE . '.session_id', $sessionId))
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.order_id')
            ->get([
                self::TABLE . '.order_id',
                self::TABLE . '.session_id',
                'order_sessions.order_date as session_date',
                'order_sessions.start_time as session_start',
                'order_sessions.end_time as session_end',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.total_amount',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                self::TABLE . '.ready_at',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'payments.payment_method_id',
                'payments.reference_number',
                'payments.proof_of_payment',
                'payments.payment_status as payment_state',
                'payments.verified_at',
                'payment_methods.method_name',
                DB::raw(User::fullNameSql('verifiers') . ' as verified_by_name'),
            ]);

        // One query for the lines of every order in the list.
        $items = OrderItem::itemsByOrder($orders->pluck('order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->order_id] ?? [];

            return $order;
        })->values()->all();
    }

    // Orders on one page of a customer's own list (My Orders).
    public const USER_PAGE_SIZE = 10;

    // The customer's own list as a query: newest first, with the session they were placed
    // in and their payment. Shared by ofUser() and pageOfUser().
    protected static function userListQuery(int $userId): Builder
    {
        return DB::table(self::TABLE)
            ->join('order_sessions', 'order_sessions.session_id', '=', self::TABLE . '.session_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->where(self::TABLE . '.user_id', $userId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.order_id')
            ->select([
                self::TABLE . '.order_id',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.total_amount',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                self::TABLE . '.ready_at',
                'order_sessions.order_date as session_date',
                'order_sessions.start_time',
                'order_sessions.end_time',
                'payments.reference_number',
                'payments.proof_of_payment',
                'payments.rejection_reason',
                'payments.payment_status as payment_state',
                'payment_methods.method_name',
            ]);
    }

    // One query for the lines of every order of the list, put on each order as `items`.
    protected static function withItems(Collection $orders): Collection
    {
        $items = OrderItem::itemsByOrder($orders->pluck('order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->order_id] ?? [];

            return $order;
        })->values();
    }

    // The orders one customer placed, newest first, with the session they were
    // placed in, their lines and their payment - the whole list.
    public static function ofUser(int $userId): array
    {
        return self::withItems(self::userListQuery($userId)->get())->all();
    }

    // The same list, one page at a time, for the customer's own page: only the orders
    // of the page get their lines read. $filters narrow it (see applyUserFilters()).
    public static function pageOfUser(int $userId, array $filters = [], int $perPage = self::USER_PAGE_SIZE): LengthAwarePaginator
    {
        $status = (string) ($filters['status'] ?? '');
        $payment = (string) ($filters['payment'] ?? '');
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage();

        // Only the plain views are cached (no search text, no dates, a known status, an early page):
        // anything a customer can type freely would make a cache file per keystroke.
        $cacheable = trim((string) ($filters['q'] ?? '')) === '' && empty($filters['from']) && empty($filters['to'])
            && ($status === '' || in_array($status, self::USER_ORDER_STATUSES, true))
            && ($payment === '' || in_array($payment, self::USER_PAYMENT_STATUSES, true))
            && $page <= 10;

        if (! $cacheable) {
            return self::loadPageOfUser($userId, $filters, $perPage);
        }

        return TableCache::remember(
            ['orders', 'order_sessions', 'payments', 'payment_methods', 'order_items', 'session_dishes', 'dishes'],
            'order.user.' . $userId . '.' . $status . '.' . $payment . '.' . $perPage . '.' . $page,
            fn () => self::loadPageOfUser($userId, $filters, $perPage)
        );
    }

    // The queries behind pageOfUser().
    protected static function loadPageOfUser(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = self::userListQuery($userId);
        self::applyUserFilters($query, $filters);

        $page = $query->paginate($perPage);

        return $page->setCollection(self::withItems($page->getCollection()));
    }

    // What the My Orders filter bar can ask for. Anything else in a filter is ignored, so a
    // hand-edited URL can only ever narrow the customer's OWN list (the user_id clause stays).
    //   q        text in the order number, a dish name, the payment method or the reference number
    //   status   an order status       payment   a payment status (the one on the order)
    //   from/to  the day the order was placed (Y-m-d, both ends included)
    public const USER_ORDER_STATUSES = ['pending', 'completed', 'cancelled'];
    public const USER_PAYMENT_STATUSES = ['pending', 'verified', 'rejected'];

    protected static function applyUserFilters(Builder $query, array $filters): void
    {
        $text = trim((string) ($filters['q'] ?? ''));

        if ($text !== '') {
            // % and _ typed by the customer are plain characters, not wildcards. "!" is the escape
            // character because a backslash inside a string literal means different things in
            // SQLite and MySQL.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';

            $query->where(function (Builder $match) use ($like) {
                $match->whereRaw(self::TABLE . ".order_number LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("payments.reference_number LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("payment_methods.method_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereExists(function (Builder $dish) use ($like) {
                        $dish->selectRaw('1')
                            ->from('order_items')
                            ->join('session_dishes', 'session_dishes.session_dish_id', '=', 'order_items.session_dish_id')
                            ->join('dishes', 'dishes.dish_id', '=', 'session_dishes.dish_id')
                            ->whereColumn('order_items.order_id', self::TABLE . '.order_id')
                            ->whereNull('order_items.deleted_at')
                            ->whereRaw("dishes.dish_name LIKE ? ESCAPE '!'", [$like]);
                    });
            });
        }

        $status = (string) ($filters['status'] ?? '');

        if (in_array($status, self::USER_ORDER_STATUSES, true)) {
            $query->where(self::TABLE . '.order_status', $status);
        }

        $payment = (string) ($filters['payment'] ?? '');

        if (in_array($payment, self::USER_PAYMENT_STATUSES, true)) {
            $query->where(self::TABLE . '.payment_status', $payment);
        }

        // A date that is not a real Y-m-d day is dropped rather than failing the page.
        $from = self::dayOrNull($filters['from'] ?? null);
        $to = self::dayOrNull($filters['to'] ?? null);

        if ($from !== null) {
            $query->where(self::TABLE . '.order_date', '>=', $from . ' 00:00:00');
        }

        if ($to !== null) {
            $query->where(self::TABLE . '.order_date', '<=', $to . ' 23:59:59');
        }
    }

    // "2026-11-19" as given, or null when it is empty or not a real day.
    protected static function dayOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    // The page of the customer's list that holds this order (1 = the newest), or null when it
    // is not theirs / does not exist. A notification links to "?order=ID" and this finds
    // the page, because an anchor alone cannot reach a card that is on another page.
    public static function pageNumberOf(int $userId, int $orderId, int $perPage = self::USER_PAGE_SIZE): ?int
    {
        $mine = DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $mine) {
            return null;
        }

        // Newest first by id: the orders above this one are the newer ones.
        $newer = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('order_id', '>', $orderId)
            ->count();

        return intdiv($newer, $perPage) + 1;
    }
    // Get one order, for the drawer that shows it.
    public static function findById(int $orderId): ?object
    {
        $order = DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->first();

        if (! $order) {
            return null;
        }

        $order->items = OrderItem::ofOrder($orderId);
        $order->payment = Payment::ofOrder($orderId);

        return $order;
    }

    // The status of a live order, or null when there is none.
    public static function statusOf(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->value('order_status');
    }

    // What a notification about an order needs, in one query: its number and
    // total, the customer, and the payment method.
    public static function notificationSummary(int $orderId): ?object
    {
        return DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->where(self::TABLE . '.order_id', $orderId)
            ->first([
                self::TABLE . '.order_id',
                self::TABLE . '.session_id',
                self::TABLE . '.user_id',
                self::TABLE . '.order_number',
                self::TABLE . '.total_amount',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'payment_methods.method_name',
            ]);
    }

    // The same summary for several orders at once (one query), keyed by order id: what the
    // "ready for pick up" notification of a whole session needs.
    public static function notificationSummaries(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->whereIn(self::TABLE . '.order_id', $orderIds)
            ->get([
                self::TABLE . '.order_id',
                self::TABLE . '.session_id',
                self::TABLE . '.user_id',
                self::TABLE . '.order_number',
                self::TABLE . '.total_amount',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'payment_methods.method_name',
            ])
            ->keyBy('order_id');
    }

    // When the order was announced as ready for pick up (null = not yet, or no such order).
    public static function readyAtOf(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->value('ready_at');
    }

    // The status of a live order, read WITH a row lock. Call it first inside a
    // transaction: two staff members acting on the same order at the same time
    // then queue up, and the second one sees the status the first one left
    // (so a double Cancel cannot give the stock back twice). SQLite has no row
    // locks and ignores the hint; it serialises writers anyway.
    public static function lockedStatus(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->value('order_status');
    }

    // Check whether a live order exists.
    public static function existsById(int $orderId): bool
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // Check whether a session already has orders (then it cannot be deleted).
    public static function existsForSession(int $sessionId): bool
    {
        return DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The next order number: the day it was placed and a counter, e.g.
    // ORD-20261110-0004.
    public static function nextOrderNumber(): string
    {
        $prefix = 'ORD-' . now()->format('Ymd') . '-';

        $last = DB::table(self::TABLE)
            ->where('order_number', 'like', $prefix . '%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /* -------- Writes -------- */

    // Create an order and return its id.
    public static function createOrder(array $attributes): int
    {
        $now = now();

        return (int) DB::table(self::TABLE)->insertGetId(self::values($attributes) + [
            'order_date' => $attributes['order_date'] ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Set the order status; returns the number of changed rows.
    public static function updateStatus(int $orderId, string $status): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'order_status' => $status,
                // A cancelled order is closed, so it leaves the live lists.
                'is_active' => $status === 'cancelled' ? 0 : 1,
                'updated_at' => now(),
            ]);
    }

    // Set the payment status of the order (it mirrors the payments row).
    public static function updatePaymentStatus(int $orderId, string $status): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update(['payment_status' => $status, 'updated_at' => now()]);
    }

    // Announce orders as ready for pick up; returns the ids that were announced just now.
    // An order qualifies when it is pending, was not announced before and its payment is
    // settled the way completing needs it (verified, or cash that is still to be paid at the
    // counter) - the same rule as Controller::orderRow()'s complete_url. The rest are skipped,
    // so a whole session can be passed in. The qualifying rows are locked first, so two
    // people pressing the button together announce (and notify) each order once.
    public static function announceReady(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            return [];
        }

        return DB::transaction(function () use ($orderIds) {
            $ids = DB::table(self::TABLE)
                ->join('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
                ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
                ->whereIn(self::TABLE . '.order_id', $orderIds)
                ->where(self::TABLE . '.order_status', 'pending')
                ->whereNull(self::TABLE . '.ready_at')
                ->whereNull(self::TABLE . '.deleted_at')
                ->whereNull('payments.deleted_at')
                ->where(function (Builder $settled) {
                    $settled->where('payments.payment_status', 'verified')
                        ->orWhere(fn (Builder $cash) => $cash
                            ->where('payments.payment_status', 'pending')
                            ->where('payment_methods.method_name', PaymentMethod::CASH_ON_PICKUP));
                })
                ->lockForUpdate()
                ->pluck(self::TABLE . '.order_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ids !== []) {
                DB::table(self::TABLE)
                    ->whereIn('order_id', $ids)
                    ->update(['ready_at' => now(), 'updated_at' => now()]);
            }

            return $ids;
        });
    }

    /* -------- Internals -------- */

    // Keep only the writable columns, and store is_active as 0 or 1.
    protected static function values(array $attributes): array
    {
        $values = Arr::only($attributes, self::COLUMNS);

        if (array_key_exists('is_active', $values)) {
            $values['is_active'] = (int) (bool) $values['is_active'];
        }

        return $values;
    }
}
