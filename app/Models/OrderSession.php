<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Order sessions: a stretch of one day during which a set of dishes is offered.
// Same shape as DishType: query builder reads and writes below, with the soft
// delete filter written in every method.
class OrderSession extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'order_sessions';

    protected const TABLE_SESSION_DISH = 'session_dishes';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'session_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'order_date',
        'start_time',
        'end_time',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ended_at' => 'datetime',
        ];
    }

    /* -------- Reads -------- */

    // Get all live sessions with their dish and order counts and their status,
    // for the table feed.
    public static function getAll(): Collection
    {
        $dishes = DB::table(self::TABLE_SESSION_DISH)
            ->selectRaw('count(*)')
            ->whereColumn('session_id', self::TABLE . '.session_id')
            ->whereNull('deleted_at');

        $orders = DB::table('orders')
            ->selectRaw('count(*)')
            ->whereColumn('session_id', self::TABLE . '.session_id')
            ->whereNull('deleted_at');

        return DB::table(self::TABLE)
            ->leftJoin('users', 'users.id', '=', self::TABLE . '.created_by')
            ->whereNull(self::TABLE . '.deleted_at')
            ->select([
                self::TABLE . '.session_id',
                self::TABLE . '.order_date',
                self::TABLE . '.start_time',
                self::TABLE . '.end_time',
                self::TABLE . '.ended_at',
                self::TABLE . '.is_active',
                self::TABLE . '.created_by',
                DB::raw(User::fullNameSql('users') . ' as created_by_name'),
            ])
            ->selectSub($dishes, 'dishes_count')
            ->selectSub($orders, 'orders_count')
            ->orderByDesc(self::TABLE . '.order_date')
            ->orderByDesc(self::TABLE . '.start_time')
            ->get()
            ->map(function (object $session) {
                $session->status = self::statusOf($session);

                return $session;
            });
    }

    // Get one session with its dishes, for the edit and view drawers.
    public static function findById(int $sessionId): ?object
    {
        $session = DB::table(self::TABLE)
            // The name of the creator travels with the record: the view drawer
            // prints it.
            ->leftJoin('users', 'users.id', '=', self::TABLE . '.created_by')
            ->where(self::TABLE . '.session_id', $sessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->first([
                self::TABLE . '.session_id',
                self::TABLE . '.order_date',
                self::TABLE . '.start_time',
                self::TABLE . '.end_time',
                self::TABLE . '.ended_at',
                self::TABLE . '.is_active',
                self::TABLE . '.created_by',
                DB::raw(User::fullNameSql('users') . ' as created_by_name'),
            ]);

        if (! $session) {
            return null;
        }

        $session->status = self::statusOf($session);
        $session->dishes = self::dishesOfSession($sessionId);

        return $session;
    }

    // Check whether a live session exists (before updating or deleting it).
    public static function existsById(int $sessionId): bool
    {
        return DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The dishes of a session with their stock, for the edit and view drawers.
    // Soft deleted dishes are kept: an order history still prints their name.
    public static function dishesOfSession(int $sessionId): array
    {
        return DB::table(self::TABLE_SESSION_DISH)
            ->join('dishes', 'dishes.dish_id', '=', self::TABLE_SESSION_DISH . '.dish_id')
            ->where(self::TABLE_SESSION_DISH . '.session_id', $sessionId)
            ->whereNull(self::TABLE_SESSION_DISH . '.deleted_at')
            ->orderBy('dishes.dish_name')
            ->get([
                self::TABLE_SESSION_DISH . '.session_dish_id',
                self::TABLE_SESSION_DISH . '.dish_id',
                self::TABLE_SESSION_DISH . '.available_stock',
                self::TABLE_SESSION_DISH . '.reserved_stock',
                'dishes.dish_name',
                'dishes.price',
            ])
            ->map(fn (object $dish) => [
                'session_dish_id' => (int) $dish->session_dish_id,
                'dish_id' => (int) $dish->dish_id,
                'dish_name' => $dish->dish_name,
                'price' => (float) $dish->price,
                'available_stock' => (int) $dish->available_stock,
                'reserved_stock' => (int) $dish->reserved_stock,
            ])
            ->values()
            ->all();
    }

    // The session the menu offers right now, or null when nothing is running.
    public static function live(): ?object
    {
        return self::getAll()->first(fn (object $session) => $session->status === 'live');
    }

    // The session's state: scheduled before it starts, live inside its window,
    // ended once it was closed by hand or its end time went past.
    public static function statusOf(object $session): string
    {
        if ($session->ended_at !== null || ! (bool) $session->is_active) {
            return 'ended';
        }

        $start = Carbon::parse($session->order_date . ' ' . $session->start_time);
        $end = Carbon::parse($session->order_date . ' ' . $session->end_time);

        if (now()->lt($start)) {
            return 'scheduled';
        }

        return now()->gt($end) ? 'ended' : 'live';
    }

    /* -------- Writes -------- */

    // Create a session with its dishes and return its id. $stocks is
    // [dish_id => available_stock].
    public static function createSession(array $attributes, array $stocks): int
    {
        $now = now();

        $sessionId = (int) DB::table(self::TABLE)->insertGetId(self::values($attributes) + [
            'created_by' => (int) $attributes['created_by'],
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::syncStocks($sessionId, $stocks);

        return $sessionId;
    }

    // Update a session and replace its dish list; returns the number of changed rows.
    public static function updateSession(int $sessionId, array $attributes, array $stocks): int
    {
        $changed = DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->update(self::values($attributes) + ['updated_at' => now()]);

        self::syncStocks($sessionId, $stocks);

        return $changed;
    }

    // Soft delete a session and its dish list; false when it was already deleted.
    public static function deleteSession(int $sessionId): bool
    {
        $now = now();

        $deleted = DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;

        if ($deleted) {
            DB::table(self::TABLE_SESSION_DISH)
                ->where('session_id', $sessionId)
                ->whereNull('deleted_at')
                ->update(['is_available' => 0, 'is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
        }

        return $deleted;
    }

    // End a session by hand: stamped as ended, and closed for ordering.
    public static function endSession(int $sessionId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->whereNull('ended_at')
            ->update(['ended_at' => $now, 'is_active' => 0, 'updated_at' => $now]) === 1;
    }

    // Replace the session's dish list: the posted dishes keep their stock, a
    // dish the form no longer lists leaves the session. Its row stays for the
    // order history, which prints the dish name of every sold line.
    public static function syncStocks(int $sessionId, array $stocks): void
    {
        $now = now();
        $posted = [];

        foreach ($stocks as $dishId => $stock) {
            $dishId = (int) $dishId;
            $posted[] = $dishId;

            $existing = DB::table(self::TABLE_SESSION_DISH)
                ->where('session_id', $sessionId)
                ->where('dish_id', $dishId)
                ->first();

            if ($existing) {
                // Re-offering a dish that left the session restores its row.
                DB::table(self::TABLE_SESSION_DISH)
                    ->where('session_dish_id', $existing->session_dish_id)
                    ->update([
                        'available_stock' => (int) $stock,
                        'is_available' => 1,
                        'is_active' => 1,
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table(self::TABLE_SESSION_DISH)->insert([
                'session_id' => $sessionId,
                'dish_id' => $dishId,
                'available_stock' => (int) $stock,
                'reserved_stock' => 0,
                'is_available' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $removed = DB::table(self::TABLE_SESSION_DISH)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at');

        if ($posted) {
            $removed->whereNotIn('dish_id', $posted);
        }

        $removed->update(['is_available' => 0, 'is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
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
