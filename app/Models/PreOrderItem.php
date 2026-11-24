<?php

namespace App\Models;

use App\Support\AlaCarte;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// The lines of a pre-order. Same shape as OrderItem, pointing at
// pre_order_session_dishes instead of session_dishes.
class PreOrderItem extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'pre_order_items';

    protected const TABLE_SESSION_DISH = 'pre_order_session_dishes';

    protected const TABLE_DISH = 'dishes';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'pre_order_item_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'pre_order_id',
        'pre_order_session_dish_id',
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

    // The lines of every given order, keyed by pre-order id: the orders feed
    // needs them all in one query. A dish that left the session is still named,
    // so an old order keeps reading the way it was placed.
    public static function itemsByOrder(array $preOrderOrderIds): Collection
    {
        if ($preOrderOrderIds === []) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->join(self::TABLE_SESSION_DISH, self::TABLE_SESSION_DISH . '.pre_order_session_dish_id', '=', self::TABLE . '.pre_order_session_dish_id')
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE_SESSION_DISH . '.dish_id')
            ->whereIn(self::TABLE . '.pre_order_id', $preOrderOrderIds)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderBy(self::TABLE . '.pre_order_item_id')
            ->get([
                self::TABLE . '.pre_order_id',
                self::TABLE . '.pre_order_session_dish_id',
                self::TABLE . '.quantity',
                self::TABLE . '.unit_price',
                self::TABLE . '.sub_total',
                self::TABLE . '.no_rice',
                self::TABLE_DISH . '.dish_name',
            ])
            ->map(fn (object $item) => [
                'pre_order_id' => (int) $item->pre_order_id,
                'pre_order_session_dish_id' => (int) $item->pre_order_session_dish_id,
                // "Adobo (no rice)": see OrderItem::itemsByOrder.
                'dish_name' => $item->dish_name . ($item->no_rice ? AlaCarte::suffix() : ''),
                'no_rice' => (bool) $item->no_rice,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'sub_total' => (float) $item->sub_total,
            ])
            ->groupBy('pre_order_id')
            ->map(fn (Collection $rows) => $rows->values()->all());
    }

    /* -------- Writes -------- */

    // Write the lines of an order. Each line is
    // [pre_order_session_dish_id, quantity, unit_price, sub_total].
    public static function createItems(int $preOrderOrderId, array $lines): void
    {
        $now = now();
        $rows = [];

        foreach ($lines as $line) {
            // Every row of the one INSERT needs the same columns, so no_rice is always written.
            $rows[] = array_merge(Arr::only($line, self::COLUMNS), ['no_rice' => (int) ($line['no_rice'] ?? 0)]) + [
                'pre_order_id' => $preOrderOrderId,
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
