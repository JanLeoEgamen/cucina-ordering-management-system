<?php

namespace App\Models;

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
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

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
                self::TABLE_DISH . '.dish_name',
            ])
            ->map(fn (object $item) => [
                'order_id' => (int) $item->order_id,
                'session_dish_id' => (int) $item->session_dish_id,
                'dish_name' => $item->dish_name,
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
                self::TABLE_DISH . '.dish_name',
            ])
            ->map(fn (object $item) => [
                'session_dish_id' => (int) $item->session_dish_id,
                'dish_name' => $item->dish_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'sub_total' => (float) $item->sub_total,
            ])
            ->values()
            ->all();
    }

    // How much of each session dish an order takes, keyed by session_dish_id
    // (a cancelled order gives it all back).
    public static function takenByOrder(int $orderId): array
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->get(['session_dish_id', 'quantity'])
            ->mapWithKeys(fn (object $item) => [(int) $item->session_dish_id => (int) $item->quantity])
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
            $rows[] = Arr::only($line, self::COLUMNS) + [
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
