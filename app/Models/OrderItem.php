<?php

namespace App\Models;

use App\Support\AlaCarte;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// The lines of an order. Same shape as DishType: query builder reads and writes
// below, with the soft delete filter written in every method.
class OrderItem extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'order_items';

    protected const TABLE_SESSION_DISH = 'session_dishes';

    protected const TABLE_DISH = 'dishes';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'order_item_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'order_id',
        'session_dish_id',
        'quantity',
        'unit_price',
        'sub_total',
        'no_rice',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'sub_total' => 'decimal:2',
            'no_rice' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The dishes most ordered since $since (Y-m-d), for the customers' "popular" widget: dish id
    // and name only. A dish counts only when at least $minBuyers DIFFERENT people ordered it, so
    // the list can never show what one person ate. Cancelled orders and deleted dishes are left out.
    public static function popularSince(string $since, int $limit, int $minBuyers): Collection
    {
        $tables = ['orders', self::TABLE, self::TABLE_SESSION_DISH, self::TABLE_DISH];

        return \App\Support\TableCache::remember($tables, 'dashboard.popular.' . $since . '.' . $limit . '.' . $minBuyers, fn () => DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->join(self::TABLE_SESSION_DISH, self::TABLE_SESSION_DISH . '.session_dish_id', '=', self::TABLE . '.session_dish_id')
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE_SESSION_DISH . '.dish_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereNull(self::TABLE_DISH . '.deleted_at')
            ->where('orders.order_status', '!=', 'cancelled')
            ->where('orders.order_date', '>=', $since . ' 00:00:00')
            ->groupBy(self::TABLE_DISH . '.dish_id', self::TABLE_DISH . '.dish_name')
            ->havingRaw('count(distinct orders.user_id) >= ?', [$minBuyers])
            ->orderByRaw('sum(' . self::TABLE . '.quantity) desc')
            ->orderBy(self::TABLE_DISH . '.dish_name')
            ->limit($limit)
            ->get([self::TABLE_DISH . '.dish_id', self::TABLE_DISH . '.dish_name']));
    }

    // For the analytics: plates and money (before any order discount) per dish and per with /
    // without rice, over the orders placed in the range that were not cancelled. A dish deleted
    // since is still counted: it was sold.
    public static function soldBetween(string $startAt, string $endAt): Collection
    {
        // Added up per session dish FIRST (numbers only), and only that small result is joined to
        // the dishes: joining every line to its dish's name before grouping was 4 to 6 times
        // slower on a year of orders.
        $perOffer = DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where('orders.order_status', '!=', 'cancelled')
            ->whereBetween('orders.order_date', [$startAt, $endAt])
            ->selectRaw('order_items.session_dish_id, order_items.no_rice, SUM(order_items.quantity) as plates, SUM(order_items.sub_total) as amount')
            ->groupBy(self::TABLE . '.session_dish_id', self::TABLE . '.no_rice');

        return DB::query()
            ->fromSub($perOffer, 'sold')
            ->join(self::TABLE_SESSION_DISH, self::TABLE_SESSION_DISH . '.session_dish_id', '=', 'sold.session_dish_id')
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE_SESSION_DISH . '.dish_id')
            ->selectRaw('dishes.dish_id, dishes.dish_name, dishes.includes_rice, sold.no_rice, SUM(sold.plates) as plates, SUM(sold.amount) as amount')
            ->groupBy(self::TABLE_DISH . '.dish_id', self::TABLE_DISH . '.dish_name', self::TABLE_DISH . '.includes_rice', 'sold.no_rice')
            ->get();
    }

    // Pairs of different dishes that share at least $minOrders orders of the range, most often
    // first. Each pair is counted once (the lower dish id first), however many lines it has.
    public static function dishPairs(string $startAt, string $endAt, int $minOrders, int $limit): Collection
    {
        // The pairs are counted on dish ids alone and the names joined to that small result:
        // carrying both names through the grouping was about twice as slow on a year of orders.
        $counted = DB::table('orders')
            ->join(self::TABLE . ' as first_line', 'first_line.order_id', '=', 'orders.order_id')
            ->join(self::TABLE . ' as other_line', 'other_line.order_id', '=', 'orders.order_id')
            ->join(self::TABLE_SESSION_DISH . ' as first_offer', 'first_offer.session_dish_id', '=', 'first_line.session_dish_id')
            ->join(self::TABLE_SESSION_DISH . ' as other_offer', 'other_offer.session_dish_id', '=', 'other_line.session_dish_id')
            ->whereColumn('first_offer.dish_id', '<', 'other_offer.dish_id')
            ->whereNull('first_line.deleted_at')
            ->whereNull('other_line.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where('orders.order_status', '!=', 'cancelled')
            ->whereBetween('orders.order_date', [$startAt, $endAt])
            ->selectRaw('first_offer.dish_id as first_id, other_offer.dish_id as other_id, COUNT(DISTINCT orders.order_id) as order_count')
            ->groupBy('first_offer.dish_id', 'other_offer.dish_id')
            ->havingRaw('COUNT(DISTINCT orders.order_id) >= ?', [$minOrders]);

        return DB::query()
            ->fromSub($counted, 'pairs')
            ->join(self::TABLE_DISH . ' as first_dish', 'first_dish.dish_id', '=', 'pairs.first_id')
            ->join(self::TABLE_DISH . ' as other_dish', 'other_dish.dish_id', '=', 'pairs.other_id')
            ->select(['pairs.first_id', 'first_dish.dish_name as first_name', 'pairs.other_id', 'other_dish.dish_name as other_name', 'pairs.order_count'])
            ->orderByDesc('order_count')
            ->orderBy('first_name')
            ->orderBy('other_name')
            ->limit($limit)
            ->get();
    }

    // In how many orders of the range each dish appears: dish id => orders.
    public static function ordersPerDish(string $startAt, string $endAt): Collection
    {
        return DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->join(self::TABLE_SESSION_DISH, self::TABLE_SESSION_DISH . '.session_dish_id', '=', self::TABLE . '.session_dish_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where('orders.order_status', '!=', 'cancelled')
            ->whereBetween('orders.order_date', [$startAt, $endAt])
            ->groupBy(self::TABLE_SESSION_DISH . '.dish_id')
            ->selectRaw('session_dishes.dish_id, COUNT(DISTINCT order_items.order_id) as order_count')
            ->pluck('order_count', 'dish_id');
    }

    // The lines of every given order, keyed by order id: the orders feed needs
    // them all in one query. A dish that left the session is still named, so an
    // old order keeps reading the way it was placed.
    public static function itemsByOrder(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->join(self::TABLE_SESSION_DISH, self::TABLE_SESSION_DISH . '.session_dish_id', '=', self::TABLE . '.session_dish_id')
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE_SESSION_DISH . '.dish_id')
            ->whereIn(self::TABLE . '.order_id', $orderIds)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderBy(self::TABLE . '.order_item_id')
            ->get([
                self::TABLE . '.order_item_id',
                self::TABLE . '.order_id',
                self::TABLE . '.session_dish_id',
                self::TABLE . '.quantity',
                self::TABLE . '.unit_price',
                self::TABLE . '.sub_total',
                self::TABLE . '.no_rice',
                self::TABLE_DISH . '.dish_name',
            ])
            ->map(fn (object $item) => [
                'order_id' => (int) $item->order_id,
                'session_dish_id' => (int) $item->session_dish_id,
                // A plate sold without rice reads "Adobo (no rice)" everywhere a line is printed
                // (popups, exports, notifications, Chat), so no screen needs to know about it.
                'dish_name' => $item->dish_name . ($item->no_rice ? AlaCarte::suffix() : ''),
                'no_rice' => (bool) $item->no_rice,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'sub_total' => (float) $item->sub_total,
            ])
            ->groupBy('order_id')
            ->map(fn (Collection $rows) => $rows->values()->all());
    }

    // The lines of one order.
    public static function ofOrder(int $orderId): array
    {
        return DB::table(self::TABLE)
            ->join(self::TABLE_SESSION_DISH, self::TABLE_SESSION_DISH . '.session_dish_id', '=', self::TABLE . '.session_dish_id')
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE_SESSION_DISH . '.dish_id')
            ->where(self::TABLE . '.order_id', $orderId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderBy(self::TABLE . '.order_item_id')
            ->get([
                self::TABLE . '.order_item_id',
                self::TABLE . '.session_dish_id',
                self::TABLE . '.quantity',
                self::TABLE . '.unit_price',
                self::TABLE . '.sub_total',
                self::TABLE . '.no_rice',
                self::TABLE_DISH . '.dish_name',
            ])
            ->map(fn (object $item) => [
                'session_dish_id' => (int) $item->session_dish_id,
                'dish_name' => $item->dish_name . ($item->no_rice ? AlaCarte::suffix() : ''),
                'no_rice' => (bool) $item->no_rice,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'sub_total' => (float) $item->sub_total,
            ])
            ->values()
            ->all();
    }

    // How much of each session dish an order takes, keyed by session_dish_id
    // (a cancelled order gives it all back). A dish can have two lines (with and without rice):
    // they are added up, never overwritten.
    public static function takenByOrder(int $orderId): array
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->get(['session_dish_id', 'quantity'])
            ->groupBy('session_dish_id')
            ->map(fn (Collection $rows) => (int) $rows->sum('quantity'))
            ->mapWithKeys(fn (int $quantity, $sessionDishId) => [(int) $sessionDishId => $quantity])
            ->all();
    }

    /* -------- Writes -------- */

    // Write the lines of an order. Each line is
    // [session_dish_id, quantity, unit_price, sub_total].
    public static function createItems(int $orderId, array $lines): void
    {
        $now = now();
        $rows = [];

        foreach ($lines as $line) {
            // Every row of the one INSERT needs the same columns, so no_rice is always written.
            $rows[] = array_merge(Arr::only($line, self::COLUMNS), ['no_rice' => (int) ($line['no_rice'] ?? 0)]) + [
                'order_id' => $orderId,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            DB::table(self::TABLE)->insert($rows);
        }
    }

    // Soft delete the lines of an order (it was cancelled).
    public static function deleteForOrder(int $orderId): void
    {
        $now = now();

        DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update(['is_active' => 0, 'deleted_at' => $now, 'updated_at' => $now]);
    }
}
