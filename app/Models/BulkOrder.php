<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

// Orders placed inside a bulk session. Unlike Order, the payment is part of the
// row (a bulk order has exactly one), so there is no Payment model to keep in
// step: the order and its payment status move under the same row lock.
// Same shape as Order: query builder reads and writes, with the soft delete
// filter written in every method.
class BulkOrder extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'bulk_orders';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'bulk_order_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'bulk_session_id',
        'user_id',
        'order_number',
        'order_date',
        'total_amount',
        'order_status',
        'payment_method_id',
        'payment_status',
        'reference_number',
        'proof_of_payment',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'verified_at' => 'datetime',
            'total_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The orders of one bulk session, with the customer, the payment and the
    // lines, for the orders list of the bulk session page.
    public static function ofSession(int $bulkSessionId): array
    {
        $orders = DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->join('bulk_sessions', 'bulk_sessions.bulk_session_id', '=', self::TABLE . '.bulk_session_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', self::TABLE . '.verified_by')
            ->where(self::TABLE . '.bulk_session_id', $bulkSessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.bulk_order_id')
            ->get([
                self::TABLE . '.bulk_order_id',
                self::TABLE . '.bulk_session_id',
                'bulk_sessions.title as session_title',
                'bulk_sessions.order_date as session_date',
                'bulk_sessions.start_time as session_start',
                'bulk_sessions.end_time as session_end',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.total_amount',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                self::TABLE . '.payment_method_id',
                self::TABLE . '.reference_number',
                self::TABLE . '.proof_of_payment',
                self::TABLE . '.rejection_reason',
                self::TABLE . '.verified_at',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'payment_methods.method_name',
                DB::raw(User::fullNameSql('verifiers') . ' as verified_by_name'),
            ]);

        // One query for the lines of every order in the list.
        $items = BulkOrderItem::itemsByOrder($orders->pluck('bulk_order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->bulk_order_id] ?? [];

            return $order;
        })->values()->all();
    }

    // The orders one customer placed, newest first, with the session they were
    // placed in, their lines and their payment - for the customer's own list.
    public static function ofUser(int $userId): array
    {
        $orders = DB::table(self::TABLE)
            ->join('bulk_sessions', 'bulk_sessions.bulk_session_id', '=', self::TABLE . '.bulk_session_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->where(self::TABLE . '.user_id', $userId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.bulk_order_id')
            ->get([
                self::TABLE . '.bulk_order_id',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.total_amount',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                self::TABLE . '.reference_number',
                self::TABLE . '.proof_of_payment',
                self::TABLE . '.rejection_reason',
                'bulk_sessions.title as session_title',
                'bulk_sessions.order_date as session_date',
                'bulk_sessions.start_time',
                'bulk_sessions.end_time',
                'payment_methods.method_name',
            ]);

        $items = BulkOrderItem::itemsByOrder($orders->pluck('bulk_order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->bulk_order_id] ?? [];

            return $order;
        })->values()->all();
    }

    // Get one live order without its lines (the controllers need its owner,
    // its states and its method).
    public static function findById(int $bulkOrderId): ?object
    {
        return DB::table(self::TABLE)
            ->where('bulk_order_id', $bulkOrderId)
            ->whereNull('deleted_at')
            ->first();
    }

    // Check whether a live order exists.
    public static function existsById(int $bulkOrderId): bool
    {
        return DB::table(self::TABLE)
            ->where('bulk_order_id', $bulkOrderId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The order and payment status of a live order, read WITH a row lock. Call it
    // first inside a transaction: two staff members acting on the same order then
    // queue up, and the second sees what the first left. Returns
    // {order_status, payment_status, payment_method_id} or null. SQLite has no row
    // locks and ignores the hint; it serialises writers anyway.
    public static function lockedState(int $bulkOrderId): ?object
    {
        return DB::table(self::TABLE)
            ->where('bulk_order_id', $bulkOrderId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['order_status', 'payment_status', 'payment_method_id']);
    }

    // Check whether a bulk session already has orders (then it cannot be deleted).
    public static function existsForSession(int $bulkSessionId): bool
    {
        return DB::table(self::TABLE)
            ->where('bulk_session_id', $bulkSessionId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The next order number: the day it was placed and a counter, e.g.
    // BLK-20261110-0004. A separate prefix, so it never competes with ORD-.
    public static function nextOrderNumber(): string
    {
        $prefix = 'BLK-' . now()->format('Ymd') . '-';

        $last = DB::table(self::TABLE)
            ->where('order_number', 'like', $prefix . '%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    // The bulk orders awaiting a payment decision, for the dashboard.
    public static function pendingPaymentCount(): int
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('order_status', 'pending')
            ->where('payment_status', 'pending')
            ->count();
    }

    /* -------- Writes -------- */

    // Create an order (with its payment fields) and return its id.
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
    public static function updateStatus(int $bulkOrderId, string $status): int
    {
        return DB::table(self::TABLE)
            ->where('bulk_order_id', $bulkOrderId)
            ->whereNull('deleted_at')
            ->update([
                'order_status' => $status,
                // A cancelled order is closed, so it leaves the live lists.
                'is_active' => $status === 'cancelled' ? 0 : 1,
                'updated_at' => now(),
            ]);
    }

    // Verify, reject or put the payment back to pending. The reviewer and the
    // moment are stamped (and cleared again for pending, when $verifiedBy is
    // null); the reason belongs to a rejection only. Returns the number of
    // changed rows.
    public static function setPaymentStatus(int $bulkOrderId, string $status, ?int $verifiedBy, ?string $reason = null): int
    {
        return DB::table(self::TABLE)
            ->where('bulk_order_id', $bulkOrderId)
            ->whereNull('deleted_at')
            ->update([
                'payment_status' => $status,
                'verified_by' => $verifiedBy,
                'verified_at' => $verifiedBy ? now() : null,
                'rejection_reason' => $status === 'rejected' ? $reason : null,
                'updated_at' => now(),
            ]);
    }

    // The customer fixes a rejected payment: a new proof (same or another
    // method) and the payment is pending again, for staff to check. The old
    // reviewer, moment and reason are cleared. Returns the number of changed rows.
    public static function resubmitPayment(int $bulkOrderId, int $methodId, ?string $referenceNumber, ?string $proofOfPayment): int
    {
        return DB::table(self::TABLE)
            ->where('bulk_order_id', $bulkOrderId)
            ->whereNull('deleted_at')
            ->update([
                'payment_method_id' => $methodId,
                'reference_number' => $referenceNumber,
                'proof_of_payment' => $proofOfPayment,
                'payment_status' => 'pending',
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
                'updated_at' => now(),
            ]);
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
