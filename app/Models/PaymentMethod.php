<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Payment methods. Same shape as DishType: query builder reads and writes below,
// with the soft delete filter written in every method.
class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    // The method the app always offers for an order picked up at the counter.
    public const CASH_ON_PICKUP = 'Cash on Pick up';

    // Locked methods: they cannot be edited or deleted from the app, only
    // chosen at checkout. Add a name here to lock another one, and remove it to
    // unlock it again. Nothing in the UI can change this list.
    public const PROTECTED = [
        self::CASH_ON_PICKUP,
    ];

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'payment_methods';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'method_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'method_name',
        'account_name',
        'account_number',
        'qr_image',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // Get all live methods, for the table feed.
    public static function getAll(): Collection
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->select(['method_id', 'method_name', 'account_name', 'account_number', 'qr_image', 'is_active'])
            ->orderBy('method_name')
            ->get()
            ->map(function (object $method) {
                $method->qr_image = self::qrUrl($method->qr_image);

                // The feed and the drawers mark a locked method, so the table can
                // show it and the buttons can stay away.
                $method->is_protected = self::isProtectedName($method->method_name);

                return $method;
            });
    }

    // Get one method's row, for the edit drawer. The stored path is handed back
    // as it is: the controller turns it into a URL for the drawer, and needs the
    // raw one to replace or delete the file.
    public static function findById(int $methodId): ?object
    {
        $method = DB::table(self::TABLE)
            ->where('method_id', $methodId)
            ->whereNull('deleted_at')
            ->first();

        if (! $method) {
            return null;
        }

        $method->is_protected = self::isProtectedName($method->method_name);

        return $method;
    }

    // Check whether a live method exists (before updating or deleting it).
    public static function existsById(int $methodId): bool
    {
        return DB::table(self::TABLE)
            ->where('method_id', $methodId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // Is this method locked from editing and deleting?
    public static function isProtected(int $methodId): bool
    {
        $name = DB::table(self::TABLE)->where('method_id', $methodId)->value('method_name');

        return $name !== null && self::isProtectedName($name);
    }

    // Locked by name, so the lock does not depend on which row it is.
    public static function isProtectedName(string $name): bool
    {
        return in_array($name, self::PROTECTED, true);
    }

    // The live and available methods, for the checkout picker.
    public static function activeOptions(): Collection
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('method_name')
            ->get(['method_id', 'method_name', 'account_name', 'account_number', 'qr_image'])
            ->map(function (object $method) {
                $method->qr_image = self::qrUrl($method->qr_image);

                return $method;
            });
    }

    // Is this the method for orders collected at the counter? It is paid when
    // the customer picks the order up, so checkout asks for no reference and no
    // proof of payment.
    public static function isCashOnPickup(object $method): bool
    {
        return $method->method_name === self::CASH_ON_PICKUP;
    }

    // The pay-on-pickup method, or null when it is turned off or gone.
    public static function cashOption(): ?object
    {
        return self::activeOptions()->first(fn (object $method) => self::isCashOnPickup($method));
    }

    // The methods paid before pickup: the checkout lists them and asks for a
    // reference number and a proof of payment.
    public static function bankOptions(): Collection
    {
        return self::activeOptions()
            ->reject(fn (object $method) => self::isCashOnPickup($method))
            ->values();
    }

    /* -------- Writes -------- */

    // Create a method and return its id.
    public static function createPaymentMethod(array $attributes): int
    {
        $now = now();

        return (int) DB::table(self::TABLE)->insertGetId(
            self::values($attributes) + ['created_at' => $now, 'updated_at' => $now]
        );
    }

    // Update a method; returns the number of changed rows (0 when it is gone).
    public static function updatePaymentMethod(int $methodId, array $attributes): int
    {
        return DB::table(self::TABLE)
            ->where('method_id', $methodId)
            ->whereNull('deleted_at')
            ->update(self::values($attributes) + ['updated_at' => now()]);
    }

    // Soft delete a method; false when it was already deleted.
    public static function deletePaymentMethod(int $methodId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('method_id', $methodId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;
    }

    /* -------- Internals -------- */

    // A stored path becomes a public URL the table, the cards and the checkout
    // can use; absolute URLs (fixtures, factory data) are left alone.
    public static function qrUrl(?string $path): ?string
    {
        if (! $path || str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

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
