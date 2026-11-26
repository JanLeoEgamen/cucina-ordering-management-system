<?php

namespace App\Models;

use App\Support\Memo;
use App\Support\TableCache;
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
                self::TABLE_DISH . '.includes_rice',
            ])
            ->map(function (object $dish) use ($types) {
                // A stored path becomes a public URL the cards can use.
                if ($dish->image && ! str_starts_with($dish->image, 'http')) {
                    $dish->image = Storage::disk('public')->url($dish->image);
                }

                $dish->includes_rice = (bool) $dish->includes_rice;
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

    // Stock left for every dish the session offers, keyed by session_dish_id.
    // The menu reads it after an order and on every live refresh
    // (GET /menu/stock). A dish that was turned off or left the session is not
    // in the map, and the menu treats a missing dish as sold out.
    public static function stockOfSession(int $sessionId): array
    {
        return DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->where('is_available', true)
            ->where('is_active', true)
            ->get(['session_dish_id', 'available_stock'])
            ->mapWithKeys(fn (object $dish) => [(int) $dish->session_dish_id => (int) $dish->available_stock])
            ->all();
    }

    // A session's dishes with their stock, for the dashboard (the live session widget, and which
    // of a customer's dishes can be had right now). `offered` is the menu's own rule (see
    // offeredForSession). Cached: every write to either table drops it.
    public static function stockForDashboard(int $sessionId): Collection
    {
        return Memo::get('session_dish.stock.' . $sessionId, fn () => TableCache::remember([self::TABLE, self::TABLE_DISH], 'dashboard.stock.' . $sessionId, fn () => DB::table(self::TABLE)
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE . '.dish_id')
            ->where(self::TABLE . '.session_id', $sessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderBy(self::TABLE_DISH . '.dish_name')
            ->get([
                self::TABLE . '.dish_id',
                self::TABLE . '.available_stock',
                self::TABLE . '.reserved_stock',
                self::TABLE . '.is_available',
                self::TABLE . '.is_active',
                self::TABLE_DISH . '.dish_name',
                self::TABLE_DISH . '.is_active as dish_is_active',
                self::TABLE_DISH . '.deleted_at as dish_deleted_at',
            ])
            ->map(fn (object $row) => (object) [
                'dish_id' => (int) $row->dish_id,
                'dish_name' => $row->dish_name,
                'available' => (int) $row->available_stock,
                'reserved' => (int) $row->reserved_stock,
                'offered' => $row->is_available && $row->is_active && $row->dish_is_active && $row->dish_deleted_at === null,
            ])));
    }

    // For the analytics: every dish of every order session held between two days, with its stock
    // and the session's window. `offered` = still on that session's menu (a dish taken off is kept
    // here, because its ordered plates are real). Whether a session has ended depends on the clock,
    // so that is the caller's to work out.
    public static function ofSessionsBetween(string $fromDate, string $toDate): Collection
    {
        return DB::table(self::TABLE)
            ->join('order_sessions', 'order_sessions.session_id', '=', self::TABLE . '.session_id')
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE . '.dish_id')
            ->whereNull('order_sessions.deleted_at')
            ->whereDate('order_sessions.order_date', '>=', $fromDate)
            ->whereDate('order_sessions.order_date', '<=', $toDate)
            ->get([
                self::TABLE . '.session_dish_id',
                self::TABLE . '.session_id',
                self::TABLE . '.dish_id',
                self::TABLE . '.available_stock',
                self::TABLE . '.reserved_stock',
                self::TABLE . '.is_available',
                self::TABLE . '.is_active',
                self::TABLE . '.deleted_at',
                self::TABLE_DISH . '.dish_name',
                self::TABLE_DISH . '.created_at as dish_created_at',
                self::TABLE_DISH . '.is_active as dish_is_active',
                self::TABLE_DISH . '.deleted_at as dish_deleted_at',
                'order_sessions.order_date',
                'order_sessions.start_time',
                'order_sessions.end_time',
                'order_sessions.ended_at',
                'order_sessions.is_active as session_is_active',
            ]);
    }

    // When each dish of those sessions was last ordered (cancelled orders left out), keyed by
    // session_dish_id: for a dish with no stock left, about when it sold out.
    public static function lastOrderedBetween(string $fromDate, string $toDate): Collection
    {
        return DB::table('order_items')
            ->join('orders', 'orders.order_id', '=', 'order_items.order_id')
            ->join('order_sessions', 'order_sessions.session_id', '=', 'orders.session_id')
            ->whereNull('order_items.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereNull('order_sessions.deleted_at')
            ->where('orders.order_status', '!=', 'cancelled')
            ->whereDate('order_sessions.order_date', '>=', $fromDate)
            ->whereDate('order_sessions.order_date', '<=', $toDate)
            ->selectRaw('order_items.session_dish_id, MAX(orders.order_date) as last_at')
            ->groupBy('order_items.session_dish_id')
            ->pluck('last_at', 'session_dish_id');
    }

    // The current price of every dish the session offers, keyed by session_dish_id
    // (the same dishes as offeredForSession). The menu reads it on every live
    // refresh, so a price an admin changed shows up while customers browse, and
    // the order is checked against the prices the customer saw.
    public static function pricesOfSession(int $sessionId): array
    {
        return DB::table(self::TABLE)
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE . '.dish_id')
            ->where(self::TABLE . '.session_id', $sessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.is_available', true)
            ->where(self::TABLE . '.is_active', true)
            ->whereNull(self::TABLE_DISH . '.deleted_at')
            ->where(self::TABLE_DISH . '.is_active', true)
            ->get([self::TABLE . '.session_dish_id', self::TABLE_DISH . '.price'])
            ->mapWithKeys(fn (object $dish) => [(int) $dish->session_dish_id => round((float) $dish->price, 2)])
            ->all();
    }

    /* -------- Writes -------- */

    // Take stock for an order: available_stock down, reserved_stock up. The
    // stock is re-checked in the same statement, so two orders cannot take the
    // last plate at the same time; false means there was not enough left.
    // A dish an admin turned off a moment ago is refused here too, not only
    // by the menu's earlier check.
    public static function reserve(int $sessionDishId, int $quantity): bool
    {
        return DB::table(self::TABLE)
            ->where('session_dish_id', $sessionDishId)
            ->whereNull('deleted_at')
            ->where('is_available', true)
            ->where('is_active', true)
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
