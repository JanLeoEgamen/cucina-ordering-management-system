<?php

namespace App\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Orders placed inside a pre-order session. Unlike Order, the payment is part of the
// row (a pre-order has exactly one), so there is no Payment model to keep in
// step: the order and its payment status move under the same row lock.
// Same shape as Order: query builder reads and writes, with the soft delete
// filter written in every method.
class PreOrder extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'pre_orders';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'pre_order_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'pre_order_session_id',
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

    // The orders of one pre-order session, with the customer, the payment and the
    // lines, for the orders list of the pre-order session page.
    public static function ofSession(int $preOrderSessionId): array
    {
        $orders = DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->join('pre_order_sessions', 'pre_order_sessions.pre_order_session_id', '=', self::TABLE . '.pre_order_session_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', self::TABLE . '.verified_by')
            ->where(self::TABLE . '.pre_order_session_id', $preOrderSessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.pre_order_id')
            ->get([
                self::TABLE . '.pre_order_id',
                self::TABLE . '.pre_order_session_id',
                'pre_order_sessions.title as session_title',
                'pre_order_sessions.order_date as session_date',
                'pre_order_sessions.start_time as session_start',
                'pre_order_sessions.end_time as session_end',
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
        $items = PreOrderItem::itemsByOrder($orders->pluck('pre_order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->pre_order_id] ?? [];

            return $order;
        })->values()->all();
    }

    // Pre-orders on one page of a customer's own list (My Pre-Orders).
    public const USER_PAGE_SIZE = 10;

    // The customer's own list as a query: newest first, with the session they were placed
    // in and their payment. Shared by ofUser() and pageOfUser().
    protected static function userListQuery(int $userId): Builder
    {
        return DB::table(self::TABLE)
            ->join('pre_order_sessions', 'pre_order_sessions.pre_order_session_id', '=', self::TABLE . '.pre_order_session_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->where(self::TABLE . '.user_id', $userId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.pre_order_id')
            ->select([
                self::TABLE . '.pre_order_id',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.total_amount',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                self::TABLE . '.reference_number',
                self::TABLE . '.proof_of_payment',
                self::TABLE . '.rejection_reason',
                'pre_order_sessions.title as session_title',
                'pre_order_sessions.order_date as session_date',
                'pre_order_sessions.start_time',
                'pre_order_sessions.end_time',
                'payment_methods.method_name',
            ]);
    }

    // One query for the lines of every order of the list, put on each order as `items`.
    protected static function withItems(Collection $orders): Collection
    {
        $items = PreOrderItem::itemsByOrder($orders->pluck('pre_order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->pre_order_id] ?? [];

            return $order;
        })->values();
    }

    // The orders one customer placed, newest first, with the session they were
    // placed in, their lines and their payment - the whole list.
    public static function ofUser(int $userId): array
    {
        return self::withItems(self::userListQuery($userId)->get())->all();
    }

    // The same list, one page at a time, for the customer's own page.
    public static function pageOfUser(int $userId, int $perPage = self::USER_PAGE_SIZE): LengthAwarePaginator
    {
        $page = self::userListQuery($userId)->paginate($perPage);

        return $page->setCollection(self::withItems($page->getCollection()));
    }

    // The page of the customer's list that holds this order (1 = the newest), or null when it
    // is not theirs / does not exist ("?order=ID" on the page jumps to it).
    public static function pageNumberOf(int $userId, int $preOrderOrderId, int $perPage = self::USER_PAGE_SIZE): ?int
    {
        $mine = DB::table(self::TABLE)
            ->where('pre_order_id', $preOrderOrderId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $mine) {
            return null;
        }

        $newer = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('pre_order_id', '>', $preOrderOrderId)
            ->count();

        return intdiv($newer, $perPage) + 1;
    }
    // Get one live order without its lines (the controllers need its owner,
    // its states and its method).
    public static function findById(int $preOrderOrderId): ?object
    {
        return DB::table(self::TABLE)
            ->where('pre_order_id', $preOrderOrderId)
            ->whereNull('deleted_at')
            ->first();
    }

    // Check whether a live order exists.
    public static function existsById(int $preOrderOrderId): bool
    {
        return DB::table(self::TABLE)
            ->where('pre_order_id', $preOrderOrderId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The order and payment status of a live order, read WITH a row lock. Call it
    // first inside a transaction: two staff members acting on the same order then
    // queue up, and the second sees what the first left. Returns
    // {order_status, payment_status, payment_method_id} or null. SQLite has no row
    // locks and ignores the hint; it serialises writers anyway.
    public static function lockedState(int $preOrderOrderId): ?object
    {
        return DB::table(self::TABLE)
            ->where('pre_order_id', $preOrderOrderId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['order_status', 'payment_status', 'payment_method_id']);
    }

    // Check whether a pre-order session already has orders (then it cannot be deleted).
    public static function existsForSession(int $preOrderSessionId): bool
    {
        return DB::table(self::TABLE)
            ->where('pre_order_session_id', $preOrderSessionId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The next order number: the day it was placed and a counter, e.g.
    // PRE-20261110-0004. A separate prefix, so it never competes with ORD-.
    public static function nextOrderNumber(): string
    {
        $prefix = 'PRE-' . now()->format('Ymd') . '-';

        $last = DB::table(self::TABLE)
            ->where('order_number', 'like', $prefix . '%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    // The pre-orders awaiting a payment decision, for the dashboard.
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
    public static function updateStatus(int $preOrderOrderId, string $status): int
    {
        return DB::table(self::TABLE)
            ->where('pre_order_id', $preOrderOrderId)
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
    public static function setPaymentStatus(int $preOrderOrderId, string $status, ?int $verifiedBy, ?string $reason = null): int
    {
        return DB::table(self::TABLE)
            ->where('pre_order_id', $preOrderOrderId)
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
    public static function resubmitPayment(int $preOrderOrderId, int $methodId, ?string $referenceNumber, ?string $proofOfPayment): int
    {
        return DB::table(self::TABLE)
            ->where('pre_order_id', $preOrderOrderId)
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
