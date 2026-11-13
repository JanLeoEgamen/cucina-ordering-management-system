<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// A dish offered in a bulk session. There is NO stock here (no reserve / release):
// a bulk session has no limit, the kitchen decides its own capacity. What the
// model gives is the list the bulk menu prints and the prices the order
// is checked against.
class BulkSessionDish extends Model
{
    use HasFactory, SoftDeletes;

    // Table names, used by the query builder methods and by Eloquent.
    protected const TABLE = 'bulk_session_dishes';

    protected const TABLE_DISH = 'dishes';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'bulk_session_dish_id';

    // Insertable and updatable columns.
    protected $fillable = [
        'bulk_session_id',
        'dish_id',
        'is_available',
        'is_active',
    ];

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The dishes the bulk menu prints for a session: only dishes that are still
    // on offer and still live in the masterlist.
    public static function offeredForSession(int $bulkSessionId): Collection
    {
        $types = Dish::typesByDish();

        return DB::table(self::TABLE)
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE . '.dish_id')
            ->where(self::TABLE . '.bulk_session_id', $bulkSessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.is_available', true)
            ->where(self::TABLE . '.is_active', true)
            ->whereNull(self::TABLE_DISH . '.deleted_at')
            ->where(self::TABLE_DISH . '.is_active', true)
            ->orderBy(self::TABLE_DISH . '.dish_name')
            ->get([
                self::TABLE . '.bulk_session_dish_id',
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

                $dish->bulk_session_dish_id = (int) $dish->bulk_session_dish_id;
                $dish->dish_id = (int) $dish->dish_id;
                $dish->price = round((float) $dish->price, 2);
                $dish->dish_types = ($types[$dish->dish_id] ?? collect())
                    ->map(fn (object $type) => [
                        'dish_type_id' => (int) $type->dish_type_id,
                        'type_name' => $type->type_name,
                    ])
                    ->values()
                    ->all();

                return $dish;
            })
            ->values();
    }

    // The current price of every offered dish, keyed by bulk_session_dish_id: the
    // order is priced from this, never from what the browser posted.
    public static function pricesOfSession(int $bulkSessionId): array
    {
        return DB::table(self::TABLE)
            ->join(self::TABLE_DISH, self::TABLE_DISH . '.dish_id', '=', self::TABLE . '.dish_id')
            ->where(self::TABLE . '.bulk_session_id', $bulkSessionId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.is_available', true)
            ->where(self::TABLE . '.is_active', true)
            ->whereNull(self::TABLE_DISH . '.deleted_at')
            ->where(self::TABLE_DISH . '.is_active', true)
            ->get([self::TABLE . '.bulk_session_dish_id', self::TABLE_DISH . '.price'])
            ->mapWithKeys(fn (object $dish) => [(int) $dish->bulk_session_dish_id => round((float) $dish->price, 2)])
            ->all();
    }
}
