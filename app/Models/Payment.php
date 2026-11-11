<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
                'verifiers.name as verified_by_name',
            ]);
    }

    /* -------- Writes -------- */

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

    // Verify or reject a payment; the verifier and the moment are stamped.
    // Returns the number of changed rows.
    public static function setStatus(int $orderId, string $status, ?int $verifiedBy): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'payment_status' => $status,
                'verified_by' => $verifiedBy,
                'verified_at' => $verifiedBy ? now() : null,
                'updated_at' => now(),
            ]);
    }
}
