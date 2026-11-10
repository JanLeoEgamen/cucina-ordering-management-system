<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Dish types. Same shape as Dish: query builder reads and writes below, with the
// soft delete filter written in every method. Eloquent stays for the factories
// and the relationship.
class DishType extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'dish_types';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'dish_type_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'type_name',
        'description',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // Get all live dish types, for the table feed.
    public static function getAll(): Collection
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->select(['dish_type_id', 'type_name', 'description', 'is_active'])
            ->orderBy('type_name')
            ->get();
    }

    // Get one dish type, for the edit drawer.
    public static function findById(int $dishTypeId): ?object
    {
        return DB::table(self::TABLE)
            ->where('dish_type_id', $dishTypeId)
            ->whereNull('deleted_at')
            ->first();
    }

    // Check whether a live dish type exists (before updating or deleting it).
    public static function existsById(int $dishTypeId): bool
    {
        return DB::table(self::TABLE)
            ->where('dish_type_id', $dishTypeId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // Get the live and available dish types, for the select inputs.
    public static function activeOptions(): Collection
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('type_name')
            ->get(['dish_type_id', 'type_name']);
    }

    /* -------- Writes -------- */

    // Create a dish type and return its id.
    public static function createDishType(array $attributes): int
    {
        $now = now();

        return (int) DB::table(self::TABLE)->insertGetId(
            self::values($attributes) + ['created_at' => $now, 'updated_at' => $now]
        );
    }

    // Update a dish type; returns the number of changed rows (0 when it is gone).
    public static function updateDishType(int $dishTypeId, array $attributes): int
    {
        return DB::table(self::TABLE)
            ->where('dish_type_id', $dishTypeId)
            ->whereNull('deleted_at')
            ->update(self::values($attributes) + ['updated_at' => now()]);
    }

    // Soft delete a dish type; false when it was already deleted.
    public static function deleteDishType(int $dishTypeId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('dish_type_id', $dishTypeId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;
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

    // Get the dishes of this dish type (Eloquent side of the model).
    public function dishes()
    {
        return $this->belongsToMany(Dish::class, 'dish_dish_types', 'dish_type_id', 'dish_id');
    }
}
