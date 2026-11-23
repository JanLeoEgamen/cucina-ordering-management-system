<?php

namespace App\Models;

use App\Support\TableCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Pre-order sessions: the window in which customers may place big orders (a minimum
// number of plates each) from their own menu. It is deliberately NOT an
// OrderSession with a type flag: the regular menu, the dashboard and the orders
// pages all assume one kind of session, and a pre-order session has no stock.
// Same shape as OrderSession: query builder reads and writes, with the soft
// delete filter written in every method.
class PreOrderSession extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'pre_order_sessions';

    protected const TABLE_DISH = 'pre_order_session_dishes';

    protected const TABLE_ORDER = 'pre_orders';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'pre_order_session_id';

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

    // Every pre-order session with its dish and order counts and its status, for the
    // table feed.
    public static function getAll(): Collection
    {
        // Only the rows are cached with their tables: the status depends on the clock.
        return TableCache::remember(['pre_order_sessions', 'pre_order_session_dishes', 'pre_orders', 'users'], 'pre_order_session.all', fn () => self::loadRows())
            ->map(function (object $session) {
                $session->status = self::statusOf($session);

                return $session;
            });
    }

    // The query behind getAll().
    protected static function loadRows(): Collection
    {
        $dishes = DB::table(self::TABLE_DISH)
            ->selectRaw('count(*)')
            ->whereColumn('pre_order_session_id', self::TABLE . '.pre_order_session_id')
            ->whereNull('deleted_at');

        $orders = DB::table(self::TABLE_ORDER)
            ->selectRaw('count(*)')
            ->whereColumn('pre_order_session_id', self::TABLE . '.pre_order_session_id')
            ->whereNull('deleted_at');

        return DB::table(self::TABLE)
            ->leftJoin('users', 'users.id', '=', self::TABLE . '.created_by')
            ->whereNull(self::TABLE . '.deleted_at')
            ->select(self::selectColumns())
            ->selectSub($dishes, 'dishes_count')
            ->selectSub($orders, 'orders_count')
            ->orderByDesc(self::TABLE . '.order_date')
            ->orderByDesc(self::TABLE . '.start_time')
            ->get();
    }

    // One pre-order session with its dishes, for the edit and view drawers.
    public static function findById(int $preOrderSessionId): ?object
    {
        $session = DB::table(self::TABLE)
            ->leftJoin('users', 'users.id', '=', self::TABLE . '.created_by')
            ->where(self::TABLE . '.pre_order_session_id', $preOrderSessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->first(self::selectColumns());

        if (! $session) {
            return null;
        }

        $session->status = self::statusOf($session);
        $session->dishes = self::dishesOfSession($preOrderSessionId);

        return $session;
    }

    // Check whether a live pre-order session exists (before updating or deleting it).
    public static function existsById(int $preOrderSessionId): bool
    {
        return DB::table(self::TABLE)
            ->where('pre_order_session_id', $preOrderSessionId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The dishes of a pre-order session, for the edit and view drawers. Dishes that
    // left the session are not listed here (the order history reads them through
    // PreOrderItem, which keeps the name).
    public static function dishesOfSession(int $preOrderSessionId): array
    {
        return DB::table(self::TABLE_DISH)
            ->join('dishes', 'dishes.dish_id', '=', self::TABLE_DISH . '.dish_id')
            ->where(self::TABLE_DISH . '.pre_order_session_id', $preOrderSessionId)
            ->whereNull(self::TABLE_DISH . '.deleted_at')
            ->orderBy('dishes.dish_name')
            ->get([
                self::TABLE_DISH . '.pre_order_session_dish_id',
                self::TABLE_DISH . '.dish_id',
                'dishes.dish_name',
                'dishes.price',
            ])
            ->map(fn (object $dish) => [
                'pre_order_session_dish_id' => (int) $dish->pre_order_session_dish_id,
                'dish_id' => (int) $dish->dish_id,
                'dish_name' => $dish->dish_name,
                'price' => (float) $dish->price,
            ])
            ->values()
            ->all();
    }

    // The pre-order session the pre-order menu offers right now, or null.
    public static function live(): ?object
    {
        return self::getAll()->first(fn (object $session) => $session->status === 'live');
    }

    // The fewest / most plates one order of this session may hold: the session's own
    // minimum, raised to the System Configuration minimum, and never above the
    // configured maximum. The pre-order menu, the order check and the public welcome page
    // all read it from here, so they cannot disagree.
    public static function limitsFor(object $session): array
    {
        $limits = Setting::preOrderLimits();
        $min = max((int) $session->min_quantity, $limits['min']);

        if ($limits['max'] !== null) {
            $min = min($min, $limits['max']);
        }

        return ['min' => $min, 'max' => $limits['max']];
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

    // Does another pre-order session that is still open overlap this window? The menu
    // shows ONE live pre-order session, so two windows must never run together (the
    // regular order sessions follow the same rule: OrderSession::overlaps()).
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
            ->when($ignoreId !== null, fn ($query) => $query->where('pre_order_session_id', '!=', $ignoreId))
            ->get(['order_date', 'start_time', 'end_time'])
            ->contains(function (object $other) use ($start, $end) {
                $otherStart = Carbon::parse($other->order_date . ' ' . $other->start_time);
                $otherEnd = Carbon::parse($other->order_date . ' ' . $other->end_time);

                return $start->lt($otherEnd) && $end->gt($otherStart);
            });
    }

    /* -------- Writes -------- */

    // Create a pre-order session with its dishes and return its id. $dishIds is a list
    // of dish ids.
    public static function createSession(array $attributes, array $dishIds): int
    {
        $now = now();

        // One transaction: a session never exists without its dishes, and one commit, not one per dish.
        return DB::transaction(function () use ($attributes, $dishIds, $now) {
            $preOrderSessionId = (int) DB::table(self::TABLE)->insertGetId(self::values($attributes) + [
                'created_by' => (int) $attributes['created_by'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            self::syncDishes($preOrderSessionId, $dishIds);

            return $preOrderSessionId;
        });
    }

    // Update a pre-order session and replace its dish list; returns the number of
    // changed rows.
    public static function updateSession(int $preOrderSessionId, array $attributes, array $dishIds): int
    {
        return DB::transaction(function () use ($preOrderSessionId, $attributes, $dishIds) {
            $changed = DB::table(self::TABLE)
                ->where('pre_order_session_id', $preOrderSessionId)
                ->whereNull('deleted_at')
                ->update(self::values($attributes) + ['updated_at' => now()]);

            self::syncDishes($preOrderSessionId, $dishIds);

            return $changed;
        });
    }

    // Soft delete a pre-order session and its dish list; false when it was already deleted.
    public static function deleteSession(int $preOrderSessionId): bool
    {
        $now = now();

        $deleted = DB::table(self::TABLE)
            ->where('pre_order_session_id', $preOrderSessionId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;

        if ($deleted) {
            DB::table(self::TABLE_DISH)
                ->where('pre_order_session_id', $preOrderSessionId)
                ->whereNull('deleted_at')
                ->update(['is_available' => 0, 'is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
        }

        return $deleted;
    }

    // End a pre-order session by hand: stamped as ended, and closed for ordering.
    public static function endSession(int $preOrderSessionId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('pre_order_session_id', $preOrderSessionId)
            ->whereNull('deleted_at')
            ->whereNull('ended_at')
            ->update(['ended_at' => $now, 'is_active' => 0, 'updated_at' => $now]) === 1;
    }

    // Replace the session's dish list: a dish the form no longer lists leaves the
    // session, but its row stays (soft deleted) because the order lines point at
    // it. Re-offering a dish restores its row. No stock to carry, unlike
    // OrderSession::syncStocks.
    public static function syncDishes(int $preOrderSessionId, array $dishIds): void
    {
        $now = now();
        $posted = array_values(array_unique(array_map('intval', $dishIds)));
        $new = [];

        // One read for every posted dish and one INSERT for the new ones, not a read and a write per dish.
        $rows = DB::table(self::TABLE_DISH)
            ->where('pre_order_session_id', $preOrderSessionId)
            ->whereIn('dish_id', $posted)
            ->get()
            ->keyBy('dish_id');

        foreach ($posted as $dishId) {
            $existing = $rows->get($dishId);

            if ($existing) {
                DB::table(self::TABLE_DISH)
                    ->where('pre_order_session_dish_id', $existing->pre_order_session_dish_id)
                    ->update([
                        'is_available' => 1,
                        'is_active' => 1,
                        'deleted_at' => null,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            $new[] = [
                'pre_order_session_id' => $preOrderSessionId,
                'dish_id' => $dishId,
                'is_available' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($new) {
            DB::table(self::TABLE_DISH)->insert($new);
        }

        $removed = DB::table(self::TABLE_DISH)
            ->where('pre_order_session_id', $preOrderSessionId)
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
            self::TABLE . '.pre_order_session_id',
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
