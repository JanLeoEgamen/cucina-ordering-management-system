<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// The discounts a customer can pick at checkout. Same shape as Company: query builder reads and
// writes below, with the soft delete filter written in every method. An order never points at
// this row for its numbers: it keeps its own copy of the name and percent (see
// Discount::forOrder()), so changing or deleting a discount only affects orders placed later.
class Discount extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'discounts';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'discount_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'discount_name',
        'percent',
        'requires_proof',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'requires_proof' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // Every live discount with how many live orders used it, for the table feed.
    public static function getAll(): Collection
    {
        $orders = DB::table('orders')
            ->selectRaw('count(*)')
            ->whereColumn('orders.discount_id', self::TABLE . '.discount_id')
            ->whereNull('orders.deleted_at');

        $preOrders = DB::table('pre_orders')
            ->selectRaw('count(*)')
            ->whereColumn('pre_orders.discount_id', self::TABLE . '.discount_id')
            ->whereNull('pre_orders.deleted_at');

        return DB::table(self::TABLE)
            ->whereNull(self::TABLE . '.deleted_at')
            ->select([
                self::TABLE . '.discount_id',
                self::TABLE . '.discount_name',
                self::TABLE . '.percent',
                self::TABLE . '.requires_proof',
                self::TABLE . '.valid_from',
                self::TABLE . '.valid_until',
                self::TABLE . '.is_active',
            ])
            ->selectSub($orders, 'orders_count')
            ->selectSub($preOrders, 'pre_orders_count')
            ->orderBy(self::TABLE . '.discount_name')
            ->get();
    }

    // One discount's row, for the edit and view drawers.
    public static function findById(int $discountId): ?object
    {
        $discount = DB::table(self::TABLE)
            ->where('discount_id', $discountId)
            ->whereNull('deleted_at')
            ->first();

        if ($discount) {
            // How many live orders and pre-orders used it (the view drawer shows it).
            $discount->used = DB::table('orders')->where('discount_id', $discountId)->whereNull('deleted_at')->count()
                + DB::table('pre_orders')->where('discount_id', $discountId)->whereNull('deleted_at')->count();
        }

        return $discount;
    }

    // Check whether a live discount exists (before updating or deleting it).
    public static function existsById(int $discountId): bool
    {
        return DB::table(self::TABLE)
            ->where('discount_id', $discountId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // Where the discount stands today: inactive (switched off), scheduled (not started),
    // expired (past its last day) or active. Only "active" is offered at checkout.
    public static function stateOf(object $discount, ?string $today = null): string
    {
        $today ??= now()->toDateString();

        if (! $discount->is_active) {
            return 'inactive';
        }

        if ($discount->valid_from !== null && substr((string) $discount->valid_from, 0, 10) > $today) {
            return 'scheduled';
        }

        if ($discount->valid_until !== null && substr((string) $discount->valid_until, 0, 10) < $today) {
            return 'expired';
        }

        return 'active';
    }

    // The switched-on discounts that have an end date, with that date (the setup checklist picks
    // the ones ending soon: which those are depends on the day, so the filter is the caller's).
    public static function withEndDate(): Collection
    {
        return \App\Support\TableCache::remember([self::TABLE], 'dashboard.discount_ends', fn () => DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('valid_until')
            ->get(['discount_id', 'discount_name', 'valid_until']));
    }

    // Every switched-on discount with its window, for the customer's dashboard (which of them is
    // inside its window depends on the day, so the caller works that out).
    public static function switchedOn(): Collection
    {
        return \App\Support\TableCache::remember([self::TABLE], 'dashboard.discounts_on', fn () => DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('discount_name')
            ->get(['discount_id', 'discount_name', 'percent', 'requires_proof', 'valid_from', 'valid_until']));
    }

    // The discounts a customer can pick right now: live, switched on and inside their window.
    // Only what the checkout needs (no counts, no dates).
    public static function offered(): array
    {
        $today = now()->toDateString();

        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->orderBy('discount_name')
            ->get(['discount_id', 'discount_name', 'percent', 'requires_proof'])
            ->map(fn (object $discount) => [
                'id' => (int) $discount->discount_id,
                'name' => $discount->discount_name,
                'percent' => (float) $discount->percent,
                'requires_proof' => (bool) $discount->requires_proof,
            ])
            ->all();
    }

    // One discount, only when it can be used right now (the same test as offered()). The
    // server re-checks the customer's choice with this: a hand-made request cannot use a
    // switched-off, expired or deleted discount.
    public static function usable(int $discountId): ?object
    {
        $today = now()->toDateString();

        return DB::table(self::TABLE)
            ->where('discount_id', $discountId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            ->first();
    }

    // The money of a discount on an order: what the order gets copied, in cents-safe rounding.
    // [discount_amount, total]. 100 percent makes the total 0.
    public static function amounts(float $subtotal, float $percent): array
    {
        $discount = round($subtotal * $percent / 100, 2);

        return [$discount, round($subtotal - $discount, 2)];
    }

    /* -------- Writes -------- */

    // Create a discount and return its id.
    public static function createDiscount(array $attributes): int
    {
        $now = now();

        return (int) DB::table(self::TABLE)->insertGetId(
            self::values($attributes) + ['created_at' => $now, 'updated_at' => $now]
        );
    }

    // Update a discount; returns the number of changed rows (0 when it is gone).
    public static function updateDiscount(int $discountId, array $attributes): int
    {
        return DB::table(self::TABLE)
            ->where('discount_id', $discountId)
            ->whereNull('deleted_at')
            ->update(self::values($attributes) + ['updated_at' => now()]);
    }

    // Soft delete a discount; false when it was already deleted.
    public static function deleteDiscount(int $discountId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('discount_id', $discountId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;
    }

    /* -------- Internals -------- */

    // Keep only the writable columns, and store the switches as 0 or 1.
    protected static function values(array $attributes): array
    {
        $values = Arr::only($attributes, self::COLUMNS);

        foreach (['is_active', 'requires_proof'] as $switch) {
            if (array_key_exists($switch, $values)) {
                $values[$switch] = (int) (bool) $values[$switch];
            }
        }

        return $values;
    }
}
