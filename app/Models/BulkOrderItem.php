<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// The lines of a bulk order. Same shape as OrderItem, pointing at
// bulk_session_dishes instead of session_dishes.
class BulkOrderItem extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'bulk_order_items';

    protected const TABLE_SESSION_DISH = 'bulk_session_dishes';

    protected const TABLE_DISH = 'dishes';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'bulk_order_item_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'bulk_order_id',
        'bulk_session_dish_id',
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

    // The lines of every given order, keyed by bulk order id: the orders feed
    // needs them all in one query. A dish that left the session is still named,
    // so an old order keeps reading the way it was placed.
    public static function itemsByOrder(array $bulkOrderIds): Collection
    {
        if ($bulkOrderIds === []) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->join(self::TABLE_SESSION_DISH, self::TABLE_SESSION_DISH . '.bulk_session_dish_id', '=', self::TABLE . '.bulk_session_dish_id')
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE_SESSION_DISH . '.dish_id')
            ->whereIn(self::TABLE . '.bulk_order_id', $bulkOrderIds)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderBy(self::TABLE . '.bulk_order_item_id')
            ->get([
                self::TABLE . '.bulk_order_id',
                self::TABLE . '.bulk_session_dish_id',
                self::TABLE . '.quantity',
                self::TABLE . '.unit_price',
                self::TABLE . '.sub_total',
                self::TABLE_DISH . '.dish_name',
            ])
            ->map(fn (object $item) => [
                'bulk_order_id' => (int) $item->bulk_order_id,
                'bulk_session_dish_id' => (int) $item->bulk_session_dish_id,
                'dish_name' => $item->dish_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'sub_total' => (float) $item->sub_total,
            ])
            ->groupBy('bulk_order_id')
            ->map(fn (Collection $rows) => $rows->values()->all());
    }

    /* -------- Writes -------- */

    // Write the lines of an order. Each line is
    // [bulk_session_dish_id, quantity, unit_price, sub_total].
    public static function createItems(int $bulkOrderId, array $lines): void
    {
        $now = now();
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = Arr::only($line, self::COLUMNS) + [
                'bulk_order_id' => $bulkOrderId,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            DB::table(self::TABLE)->insert($rows);
        }
    }
}
