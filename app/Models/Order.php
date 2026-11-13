<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
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

    // The orders one customer placed, newest first, with the session they were
    // placed in, their lines and their payment - for the customer's own list.
    public static function ofUser(int $userId): array
    {
        $orders = DB::table(self::TABLE)
            ->join('order_sessions', 'order_sessions.session_id', '=', self::TABLE . '.session_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->where(self::TABLE . '.user_id', $userId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.order_id')
            ->get([
                self::TABLE . '.order_id',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.total_amount',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                'order_sessions.order_date as session_date',
                'order_sessions.start_time',
                'order_sessions.end_time',
                'payments.reference_number',
                'payments.proof_of_payment',
                'payments.rejection_reason',
                'payments.payment_status as payment_state',
                'payment_methods.method_name',
            ]);

        // One query for the lines of every order in the list.
        $items = OrderItem::itemsByOrder($orders->pluck('order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->order_id] ?? [];

            return $order;
        })->values()->all();
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
