<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Bulk sessions: the window in which customers may place big orders (a minimum
// number of plates each) from their own menu. It is deliberately NOT an
// OrderSession with a type flag: the regular menu, the dashboard and the orders
// pages all assume one kind of session, and a bulk session has no stock.
// Same shape as OrderSession: query builder reads and writes, with the soft
// delete filter written in every method.
class BulkSession extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'bulk_sessions';

    protected const TABLE_DISH = 'bulk_session_dishes';

    protected const TABLE_ORDER = 'bulk_orders';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'bulk_session_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'title',
        'order_date',
        'start_time',
        'end_time',
        'min_quantity',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'is_active' => 'boolean',
            'ended_at' => 'datetime',
        ];
    }

    /* -------- Reads -------- */

    // Every bulk session with its dish and order counts and its status, for the
    // table feed.
    public static function getAll(): Collection
    {
        $dishes = DB::table(self::TABLE_DISH)
            ->selectRaw('count(*)')
            ->whereColumn('bulk_session_id', self::TABLE . '.bulk_session_id')
            ->whereNull('deleted_at');

        $orders = DB::table(self::TABLE_ORDER)
            ->selectRaw('count(*)')
            ->whereColumn('bulk_session_id', self::TABLE . '.bulk_session_id')
            ->whereNull('deleted_at');

        return DB::table(self::TABLE)
            ->leftJoin('users', 'users.id', '=', self::TABLE . '.created_by')
            ->whereNull(self::TABLE . '.deleted_at')
            ->select(self::selectColumns())
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

    // One bulk session with its dishes, for the edit and view drawers.
    public static function findById(int $bulkSessionId): ?object
    {
        $session = DB::table(self::TABLE)
            ->leftJoin('users', 'users.id', '=', self::TABLE . '.created_by')
            ->where(self::TABLE . '.bulk_session_id', $bulkSessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->first(self::selectColumns());

        if (! $session) {
            return null;
        }

        $session->status = self::statusOf($session);
        $session->dishes = self::dishesOfSession($bulkSessionId);

        return $session;
    }

    // Check whether a live bulk session exists (before updating or deleting it).
    public static function existsById(int $bulkSessionId): bool
    {
        return DB::table(self::TABLE)
            ->where('bulk_session_id', $bulkSessionId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The dishes of a bulk session, for the edit and view drawers. Dishes that
    // left the session are not listed here (the order history reads them through
    // BulkOrderItem, which keeps the name).
    public static function dishesOfSession(int $bulkSessionId): array
    {
        return DB::table(self::TABLE_DISH)
            ->join('dishes', 'dishes.dish_id', '=', self::TABLE_DISH . '.dish_id')
            ->where(self::TABLE_DISH . '.bulk_session_id', $bulkSessionId)
            ->whereNull(self::TABLE_DISH . '.deleted_at')
            ->orderBy('dishes.dish_name')
            ->get([
                self::TABLE_DISH . '.bulk_session_dish_id',
                self::TABLE_DISH . '.dish_id',
                'dishes.dish_name',
                'dishes.price',
            ])
            ->map(fn (object $dish) => [
                'bulk_session_dish_id' => (int) $dish->bulk_session_dish_id,
                'dish_id' => (int) $dish->dish_id,
                'dish_name' => $dish->dish_name,
                'price' => (float) $dish->price,
            ])
            ->values()
            ->all();
    }

    // The bulk session the bulk menu offers right now, or null.
    public static function live(): ?object
    {
        return self::getAll()->first(fn (object $session) => $session->status === 'live');
    }

    // The session's state: scheduled before it starts, live inside its window,
    // ended once it was closed by hand or its end time went past. Same rule as
    // OrderSession::statusOf, so nothing needs a scheduler.
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

    // Does another bulk session that is still open overlap this window? The menu
    // shows ONE live bulk session, so two windows must never run together (the
    // regular order sessions have no such guard: known-issues F3).
    // $ignoreId is the session being edited.
    public static function overlaps(string $orderDate, string $startTime, string $endTime, ?int $ignoreId = null): bool
    {
        $start = Carbon::parse($orderDate . ' ' . $startTime);
        $end = Carbon::parse($orderDate . ' ' . $endTime);

        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->whereNull('ended_at')
            ->where('is_active', 1)
            ->whereDate('order_date', $orderDate)
            ->when($ignoreId !== null, fn ($query) => $query->where('bulk_session_id', '!=', $ignoreId))
            ->get(['order_date', 'start_time', 'end_time'])
            ->contains(function (object $other) use ($start, $end) {
                $otherStart = Carbon::parse($other->order_date . ' ' . $other->start_time);
                $otherEnd = Carbon::parse($other->order_date . ' ' . $other->end_time);

                return $start->lt($otherEnd) && $end->gt($otherStart);
            });
    }

    /* -------- Writes -------- */

    // Create a bulk session with its dishes and return its id. $dishIds is a list
    // of dish ids.
    public static function createSession(array $attributes, array $dishIds): int
    {
        $now = now();

        $bulkSessionId = (int) DB::table(self::TABLE)->insertGetId(self::values($attributes) + [
            'created_by' => (int) $attributes['created_by'],
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::syncDishes($bulkSessionId, $dishIds);

        return $bulkSessionId;
    }

    // Update a bulk session and replace its dish list; returns the number of
    // changed rows.
    public static function updateSession(int $bulkSessionId, array $attributes, array $dishIds): int
    {
        $changed = DB::table(self::TABLE)
            ->where('bulk_session_id', $bulkSessionId)
            ->whereNull('deleted_at')
            ->update(self::values($attributes) + ['updated_at' => now()]);

        self::syncDishes($bulkSessionId, $dishIds);

        return $changed;
    }

    // Soft delete a bulk session and its dish list; false when it was already deleted.
    public static function deleteSession(int $bulkSessionId): bool
    {
        $now = now();

        $deleted = DB::table(self::TABLE)
            ->where('bulk_session_id', $bulkSessionId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;

        if ($deleted) {
            DB::table(self::TABLE_DISH)
                ->where('bulk_session_id', $bulkSessionId)
                ->whereNull('deleted_at')
                ->update(['is_available' => 0, 'is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
        }

        return $deleted;
    }

    // End a bulk session by hand: stamped as ended, and closed for ordering.
    public static function endSession(int $bulkSessionId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('bulk_session_id', $bulkSessionId)
            ->whereNull('deleted_at')
            ->whereNull('ended_at')
            ->update(['ended_at' => $now, 'is_active' => 0, 'updated_at' => $now]) === 1;
    }

    // Replace the session's dish list: a dish the form no longer lists leaves the
    // session, but its row stays (soft deleted) because the order lines point at
    // it. Re-offering a dish restores its row. No stock to carry, unlike
    // OrderSession::syncStocks.
    public static function syncDishes(int $bulkSessionId, array $dishIds): void
    {
        $now = now();
        $posted = [];

        foreach ($dishIds as $dishId) {
            $dishId = (int) $dishId;

            if (in_array($dishId, $posted, true)) {
                continue;
            }

            $posted[] = $dishId;

            $existing = DB::table(self::TABLE_DISH)
                ->where('bulk_session_id', $bulkSessionId)
                ->where('dish_id', $dishId)
                ->first();

            if ($existing) {
                DB::table(self::TABLE_DISH)
                    ->where('bulk_session_dish_id', $existing->bulk_session_dish_id)
                    ->update([
                        'is_available' => 1,
                        'is_active' => 1,
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table(self::TABLE_DISH)->insert([
                'bulk_session_id' => $bulkSessionId,
                'dish_id' => $dishId,
                'is_available' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $removed = DB::table(self::TABLE_DISH)
            ->where('bulk_session_id', $bulkSessionId)
            ->whereNull('deleted_at');

        if ($posted) {
            $removed->whereNotIn('dish_id', $posted);
        }

        $removed->update(['is_available' => 0, 'is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
    }

    /* -------- Internals -------- */

    // The columns the feed and the drawers read, with the creator's name.
    protected static function selectColumns(): array
    {
        return [
            self::TABLE . '.bulk_session_id',
            self::TABLE . '.title',
            self::TABLE . '.order_date',
            self::TABLE . '.start_time',
            self::TABLE . '.end_time',
            self::TABLE . '.min_quantity',
            self::TABLE . '.ended_at',
            self::TABLE . '.is_active',
            self::TABLE . '.created_by',
            DB::raw(User::fullNameSql('users') . ' as created_by_name'),
        ];
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
