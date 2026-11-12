<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// The payment of an order. One row per order, so the order id is the primary
// key (see the payments migration). Same shape as the other models: query
// builder reads and writes, with the soft delete filter written in every method.
class Payment extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'payments';

    protected $table = self::TABLE;

    // The order id is the key, and it is not generated here.
    protected $primaryKey = 'order_id';

    public $incrementing = false;

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'order_id',
        'payment_method_id',
        'verified_by',
        'verified_at',
        'amount',
        'reference_number',
        'proof_of_payment',
        'payment_status',
        'rejection_reason',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The payment of an order, with its method, for the drawers.
    public static function ofOrder(int $orderId): ?object
    {
        return DB::table(self::TABLE)
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', self::TABLE . '.verified_by')
            ->where(self::TABLE . '.order_id', $orderId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->first([
                self::TABLE . '.order_id',
                self::TABLE . '.payment_method_id',
                self::TABLE . '.amount',
                self::TABLE . '.reference_number',
                self::TABLE . '.proof_of_payment',
                self::TABLE . '.payment_status',
                self::TABLE . '.verified_at',
                'payment_methods.method_name',
                DB::raw(User::fullNameSql('verifiers') . ' as verified_by_name'),
            ]);
    }

    // Every live payment for the payments page: the order, the customer, the
    // method, who reviewed it, the proof as a URL and the lines of the order.
    // Newest first.
    public static function getAll(): Collection
    {
        return self::hydrate(
            self::listQuery()
                ->orderByDesc(self::TABLE . '.created_at')
                ->orderByDesc(self::TABLE . '.order_id')
                ->get()
        );
    }

    // How many payments are waiting for a decision (for the dashboard).
    public static function pendingCount(): int
    {
        return DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where('orders.order_status', 'pending')
            ->where(self::TABLE . '.payment_status', 'pending')
            ->count();
    }

    // The status of a live payment, or null when there is none.
    public static function statusOf(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->value('payment_status');
    }

    /* -------- Writes -------- */

    // Soft delete a payment; returns whether a live row was removed.
    public static function deletePayment(int $orderId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;
    }

    // Write the payment of an order with the method the customer picked. The
    // row is created with the order, so it is always there to be verified.
    // Cash on pickup sends no reference and no proof; a bank or e-wallet pays
    // send one, and the proof is kept for whoever verifies the payment.
    public static function createForOrder(int $orderId, int $methodId, float $amount, ?string $referenceNumber, ?string $proofOfPayment = null): void
    {
        $now = now();

        DB::table(self::TABLE)->updateOrInsert(
            ['order_id' => $orderId],
            [
                'payment_method_id' => $methodId,
                'amount' => $amount,
                'reference_number' => $referenceNumber,
                'proof_of_payment' => $proofOfPayment,
                'payment_status' => 'pending',
                'is_active' => 1,
                'deleted_at' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    // A stored path becomes a public URL the drawers can use; absolute URLs
    // (fixtures, factory data) are left alone.
    public static function proofUrl(?string $path): ?string
    {
        if (! $path || str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    // Verify, reject or put back to pending. The reviewer and the moment are
    // stamped (and cleared again for pending, when $verifiedBy is null); the
    // reason belongs to a rejection only, so any other status clears it.
    // Returns the number of changed rows.
    public static function setStatus(int $orderId, string $status, ?int $verifiedBy, ?string $reason = null): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'payment_status' => $status,
                'verified_by' => $verifiedBy,
                'verified_at' => $verifiedBy ? now() : null,
                'rejection_reason' => $status === 'rejected' ? $reason : null,
                'updated_at' => now(),
            ]);
    }
    /* -------- Internals -------- */

    // The joins and columns of the list.
    protected static function listQuery()
    {
        return DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('order_sessions', 'order_sessions.session_id', '=', 'orders.session_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', self::TABLE . '.verified_by')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->select([
                self::TABLE . '.order_id',
                self::TABLE . '.amount',
                self::TABLE . '.reference_number',
                self::TABLE . '.proof_of_payment',
                self::TABLE . '.payment_status',
                self::TABLE . '.rejection_reason',
                self::TABLE . '.verified_at',
                self::TABLE . '.created_at',
                'orders.order_number',
                'orders.order_status',
                'orders.session_id',
                self::TABLE . '.payment_method_id',
                'order_sessions.start_time',
                'order_sessions.end_time',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'order_sessions.order_date as session_date',
                'payment_methods.method_name',
                DB::raw(User::fullNameSql('verifiers') . ' as verified_by_name'),
            ]);
    }

    // Add the lines of each order (one query for all) and the proof URL.
    protected static function hydrate(Collection $rows): Collection
    {
        $items = OrderItem::itemsByOrder($rows->pluck('order_id')->all());

        return $rows->map(function (object $payment) use ($items) {
            $payment->items = $items[$payment->order_id] ?? [];
            $payment->proof_url = self::proofUrl($payment->proof_of_payment);

            return $payment;
        })->values();
    }
}