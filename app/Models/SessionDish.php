<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// The dishes a session offers, and the stock left of each one. Same shape as
// DishType: query builder reads and writes below, with the soft delete filter
// written in every method.
class SessionDish extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'session_dishes';

    protected const TABLE_DISH = 'dishes';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'session_dish_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'session_id',
        'dish_id',
        'available_stock',
        'reserved_stock',
        'is_available',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'available_stock' => 'integer',
            'reserved_stock' => 'integer',
            'is_available' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The dishes a session offers, for the menu. A dish that was turned off, or
    // is no longer in the session, is left out; one with no stock left stays,
    // so the menu can show it as sold out.
    public static function offeredForSession(int $sessionId): Collection
    {
        $types = Dish::typesByDish();

        return DB::table(self::TABLE)
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE . '.dish_id')
            ->where(self::TABLE . '.session_id', $sessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.is_available', true)
            ->where(self::TABLE . '.is_active', true)
            ->whereNull(self::TABLE_DISH . '.deleted_at')
            ->where(self::TABLE_DISH . '.is_active', true)
            ->orderBy(self::TABLE_DISH . '.dish_name')
            ->get([
                self::TABLE . '.session_dish_id',
                self::TABLE . '.available_stock',
                self::TABLE_DISH . '.dish_id',
                self::TABLE_DISH . '.dish_name',
                self::TABLE_DISH . '.description',
                self::TABLE_DISH . '.image',
                self::TABLE_DISH . '.price',
                self::TABLE_DISH . '.calories',
                self::TABLE_DISH . '.main_ingredients',
            ])
            ->map(function (object $dish) use ($types) {
                // A stored path becomes a public URL the cards can use.
                if ($dish->image && ! str_starts_with($dish->image, 'http')) {
                    $dish->image = Storage::disk('public')->url($dish->image);
                }

                $dish->session_dish_id = (int) $dish->session_dish_id;
                $dish->dish_id = (int) $dish->dish_id;
                $dish->available_stock = (int) $dish->available_stock;
                $dish->dish_types = ($types[$dish->dish_id] ?? collect())
                    ->map(fn (object $type) => [
                        'dish_type_id' => (int) $type->dish_type_id,
                        'type_name' => $type->type_name,
                    ])
                    ->values()
                    ->all();

                return $dish;
            });
    }

    // Get one session dish, for the order that is being placed.
    public static function findById(int $sessionDishId): ?object
    {
        return DB::table(self::TABLE)
            ->where('session_dish_id', $sessionDishId)
            ->whereNull('deleted_at')
            ->first();
    }

    // Stock left for every dish of a session, keyed by session_dish_id. The menu
    // sends it back after an order so the cards show what is left.
    public static function stockOfSession(int $sessionId): array
    {
        return DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->get(['session_dish_id', 'available_stock'])
            ->mapWithKeys(fn (object $dish) => [(int) $dish->session_dish_id => (int) $dish->available_stock])
            ->all();
    }

    /* -------- Writes -------- */

    // Take stock for an order: available_stock down, reserved_stock up. The
    // stock is re-checked in the same statement, so two orders cannot take the
    // last plate at the same time; false means there was not enough left.
    public static function reserve(int $sessionDishId, int $quantity): bool
    {
        return DB::table(self::TABLE)
            ->where('session_dish_id', $sessionDishId)
            ->whereNull('deleted_at')
            ->where('available_stock', '>=', $quantity)
            ->update([
                'available_stock' => DB::raw('available_stock - ' . (int) $quantity),
                'reserved_stock' => DB::raw('reserved_stock + ' . (int) $quantity),
                'updated_at' => now(),
            ]) === 1;
    }

    // Give stock back (an order was cancelled). The reserved side never goes
    // below zero: CASE works on MySQL and SQLite alike, where MAX(a, b) would
    // only be right on one of them.
    public static function release(int $sessionDishId, int $quantity): void
    {
        $taken = (int) $quantity;

        DB::table(self::TABLE)
            ->where('session_dish_id', $sessionDishId)
            ->update([
                'available_stock' => DB::raw('available_stock + ' . $taken),
                'reserved_stock' => DB::raw('CASE WHEN reserved_stock - ' . $taken . ' < 0 THEN 0 ELSE reserved_stock - ' . $taken . ' END'),
                'updated_at' => now(),
            ]);
    }

    /* -------- Internals -------- */

    // Keep only the writable columns.
    protected static function values(array $attributes): array
    {
        return Arr::only($attributes, self::COLUMNS);
    }
}
