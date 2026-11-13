<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Dishes. The reads and writes below use the query builder, so the soft delete
// filter is written in every method instead of coming from a global scope.
// The model stays Eloquent for the factories and the relationships.
class Dish extends Model
{
    use HasFactory, SoftDeletes;

    // The dishes table, and the pivot that joins a dish to its types.
    protected const TABLE = 'dishes';

    protected const PIVOT = 'dish_dish_types';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'dish_id';

    // Insertable and updatable columns, shared with the write methods.
    protected const COLUMNS = [
        'dish_name',
        'description',
        'image',
        'price',
        'calories',
        'main_ingredients',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'calories' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The collage of the signed-out pages (the sign-in panel and the landing hero), split into
    // scrolling columns; empty when there is no dish yet. A short column is cycled up to
    // `$minPerColumn` with its own tiles, so it never runs dry while it scrolls. Those pages are
    // public and hit by anyone, so the picked dishes are cached for a few minutes.
    public static function collageColumns(int $columns = 3, int $minPerColumn = 8): array
    {
        $tiles = Cache::get('guest.dish-collage');

        if ($tiles === null) {
            $tiles = self::showcase();

            // An empty pick is not kept: the first dish that is added shows up at once.
            if ($tiles !== []) {
                Cache::put('guest.dish-collage', $tiles, 600);
            }
        }

        if ($tiles === []) {
            return [];
        }

        $split = array_fill(0, $columns, []);

        foreach ($tiles as $index => $tile) {
            $split[$index % $columns][] = $tile;
        }

        foreach ($split as $c => $column) {
            if ($column === []) {
                $column = $split[0] ?: $tiles;
            }

            for ($i = 0; count($column) < $minPerColumn; $i++) {
                $column[] = $column[$i % count($column)];
            }

            $split[$c] = $column;
        }

        return $split;
    }

    // A mixed handful of available dishes for the collage on the signed-out pages: name,
    // photo URL (null while a dish has none) and its first type. Dishes WITH a photo are
    // spread evenly among the plain ones, so the collage looks full before every dish has a
    // picture and fills with photos as they are added. Prices and stock are never included.
    public static function showcase(int $limit = 24): array
    {
        $dishes = self::getAll()->filter(fn (object $dish) => (bool) $dish->is_active);

        $photos = $dishes->filter(fn (object $dish) => $dish->image)->shuffle()->take(12)->values();
        $plain = $dishes->reject(fn (object $dish) => $dish->image)->shuffle()->take(max($limit - $photos->count(), 0))->values();

        $total = $photos->count() + $plain->count();

        // The positions the photos take: evenly spaced over the whole run.
        $photoAt = [];

        foreach (range(0, max($photos->count() - 1, 0)) as $i) {
            if ($photos->count() > 0) {
                $photoAt[(int) floor($i * $total / $photos->count())] = true;
            }
        }

        $tiles = [];
        $nextPhoto = 0;
        $nextPlain = 0;

        for ($position = 0; $position < $total; $position++) {
            $dish = isset($photoAt[$position]) && $nextPhoto < $photos->count()
                ? $photos[$nextPhoto++]
                : ($plain[$nextPlain++] ?? $photos[$nextPhoto++] ?? null);

            if ($dish) {
                $tiles[] = [
                    'name' => $dish->dish_name,
                    'image' => $dish->image ?: null,
                    'type' => $dish->dish_types[0]['type_name'] ?? null,
                ];
            }
        }

        return $tiles;
    }

    // Get all live dishes with their dish types, for the table feed.
    public static function getAll(): Collection
    {
        $types = self::typesByDish();

        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->select(['dish_id', 'dish_name', 'image', 'price', 'is_active'])
            ->orderBy('dish_name')
            ->get()
            ->map(function (object $row) use ($types) {
                // A stored path becomes a public URL the table and cards can use;
                // absolute URLs (fixtures, factory data) are left alone.
                if ($row->image && ! str_starts_with($row->image, 'http')) {
                    $row->image = Storage::disk('public')->url($row->image);
                }

                $row->dish_types = self::asList($types[$row->dish_id] ?? collect());

                return $row;
            });
    }

    // Get one dish, for the edit drawer.
    public static function findById(int $dishId): ?object
    {
        return DB::table(self::TABLE)
            ->where('dish_id', $dishId)
            ->whereNull('deleted_at')
            ->first();
    }

    // Check whether a live dish exists (before updating or deleting it).
    public static function existsById(int $dishId): bool
    {
        return DB::table(self::TABLE)
            ->where('dish_id', $dishId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // Check whether a dish type still has live dishes.
    public static function existsForDishType(int $dishTypeId): bool
    {
        return DB::table(self::PIVOT)
            ->join(self::TABLE, self::TABLE . '.dish_id', '=', self::PIVOT . '.dish_id')
            ->where(self::PIVOT . '.dish_type_id', $dishTypeId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->exists();
    }

    // Types of every dish, keyed by dish id: the feed needs them in one query.
    public static function typesByDish(): Collection
    {
        return DB::table(self::PIVOT)
            ->join('dish_types', 'dish_types.dish_type_id', '=', self::PIVOT . '.dish_type_id')
            ->whereNull('dish_types.deleted_at')
            ->orderBy('dish_types.type_name')
            ->get([self::PIVOT . '.dish_id', 'dish_types.dish_type_id', 'dish_types.type_name'])
            ->groupBy('dish_id');
    }

    // Types of one dish, for the edit and view drawers.
    public static function typesOfDish(int $dishId): array
    {
        return self::asList(
            DB::table(self::PIVOT)
                ->join('dish_types', 'dish_types.dish_type_id', '=', self::PIVOT . '.dish_type_id')
                ->where(self::PIVOT . '.dish_id', $dishId)
                ->whereNull('dish_types.deleted_at')
                ->orderBy('dish_types.type_name')
                ->get(['dish_types.dish_type_id', 'dish_types.type_name'])
        );
    }

    /* -------- Writes -------- */

    // Create a dish with its types and return its id.
    public static function createDish(array $attributes): int
    {
        $now = now();

        $dishId = (int) DB::table(self::TABLE)->insertGetId(
            self::values($attributes) + ['created_at' => $now, 'updated_at' => $now]
        );

        self::syncTypes($dishId, $attributes['dish_types'] ?? []);

        return $dishId;
    }

    // Update a dish; returns the number of changed rows (0 when it is gone).
    public static function updateDish(int $dishId, array $attributes): int
    {
        $changed = DB::table(self::TABLE)
            ->where('dish_id', $dishId)
            ->whereNull('deleted_at')
            ->update(self::values($attributes) + ['updated_at' => now()]);

        // Only touch the types when they were part of this update.
        if (array_key_exists('dish_types', $attributes)) {
            self::syncTypes($dishId, $attributes['dish_types']);
        }

        return $changed;
    }

    // Soft delete a dish; false when it was already deleted.
    public static function deleteDish(int $dishId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('dish_id', $dishId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;
    }

    /* -------- Internals -------- */

    // Replace the dish's types with this set.
    protected static function syncTypes(int $dishId, array $dishTypeIds): void
    {
        DB::table(self::PIVOT)->where('dish_id', $dishId)->delete();

        $rows = [];

        foreach (array_unique(array_map('intval', $dishTypeIds)) as $dishTypeId) {
            $rows[] = ['dish_id' => $dishId, 'dish_type_id' => $dishTypeId];
        }

        if ($rows) {
            DB::table(self::PIVOT)->insert($rows);
        }
    }

    // Pivot rows as plain arrays, so every response has the same shape.
    protected static function asList(Collection $rows): array
    {
        return $rows->map(fn (object $row) => [
            'dish_type_id' => (int) $row->dish_type_id,
            'type_name' => $row->type_name,
        ])->values()->all();
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

    // Dish types of this dish (Eloquent side of the model).
    public function dishTypes()
    {
        return $this->belongsToMany(DishType::class, self::PIVOT, 'dish_id', 'dish_type_id');
    }
}
