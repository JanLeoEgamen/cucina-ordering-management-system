<?php

namespace App\Models;

use App\Exceptions\DishGoneException;
use App\Support\TableCache;
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
        'includes_rice',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'calories' => 'integer',
            'includes_rice' => 'boolean',
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
        return TableCache::remember(['dishes', 'dish_dish_types', 'dish_types'], 'dish.all', fn () => self::loadAll());
    }

    // The query behind the cached method above.
    protected static function loadAll(): Collection
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

    // Every live dish with what the import file carries, keyed by dish id (two queries, whatever the size):
    // the filled-in template is written from it, and an upload is compared with it to find the dishes
    // that exist, the ones that really changed, and the names that are taken. `types` is the dish's
    // type ids keyed by lower case name, `type_names` the names in the order the template prints them.
    public static function forImport(): array
    {
        $types = self::typesByDish();

        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->orderBy('dish_name')
            ->get(['dish_id', 'dish_name', 'description', 'price', 'calories', 'main_ingredients', 'includes_rice', 'is_active'])
            ->mapWithKeys(function (object $row) use ($types) {
                $own = $types[$row->dish_id] ?? collect();

                return [(int) $row->dish_id => [
                    'dish_id' => (int) $row->dish_id,
                    'dish_name' => (string) $row->dish_name,
                    'description' => $row->description,
                    'price' => $row->price,
                    'calories' => $row->calories === null ? null : (int) $row->calories,
                    'main_ingredients' => $row->main_ingredients,
                    'includes_rice' => (bool) $row->includes_rice,
                    'is_active' => (bool) $row->is_active,
                    'types' => $own->mapWithKeys(fn (object $type) => [mb_strtolower(trim((string) $type->type_name)) => (int) $type->dish_type_id])->all(),
                    'type_names' => $own->pluck('type_name')->all(),
                ]];
            })
            ->all();
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

    // Create many dishes with their types in ONE transaction and a handful of statements (the import:
    // a row-by-row loop would be hundreds of round trips on a remote database). Each entry is
    // `createDish()`'s attributes, with `dish_types` = ids. The names must be unique among live dishes
    // (the import checks), because the new ids are found again by name. Returns how many were stored.
    public static function createMany(array $dishes): int
    {
        if ($dishes === []) {
            return 0;
        }

        return DB::transaction(function () use ($dishes) {
            $now = now();

            // Every id above this one was created by the inserts below (soft deleted rows count: ids are never reused).
            $after = (int) DB::table(self::TABLE)->max('dish_id');

            foreach (array_chunk($dishes, 100) as $chunk) {
                DB::table(self::TABLE)->insert(array_map(
                    fn (array $dish) => self::values($dish) + ['created_at' => $now, 'updated_at' => $now],
                    $chunk
                ));
            }

            $ids = DB::table(self::TABLE)
                ->where('dish_id', '>', $after)
                ->whereNull('deleted_at')
                ->pluck('dish_id', 'dish_name');

            $links = [];

            foreach ($dishes as $dish) {
                $dishId = $ids[$dish['dish_name']] ?? null;

                foreach (array_unique(array_map('intval', $dish['dish_types'] ?? [])) as $dishTypeId) {
                    if ($dishId) {
                        $links[] = ['dish_id' => (int) $dishId, 'dish_type_id' => $dishTypeId];
                    }
                }
            }

            foreach (array_chunk($links, 500) as $chunk) {
                DB::table(self::PIVOT)->insert($chunk);
            }

            return count($dishes);
        });
    }

    // Update many dishes in ONE transaction with a handful of statements (the import: an UPDATE per dish
    // would be a round trip each on a remote database). Each entry carries `dish_id`, every data column of
    // `createDish()` except the picture, and `dish_types` = the new type ids, or null to leave the types
    // alone. The picture and `created_at` are never touched. A dish deleted since the file was checked
    // stops the whole thing (DishGoneException): an upsert would otherwise write into a deleted row.
    // Returns how many dishes were updated.
    public static function updateMany(array $updates): int
    {
        if ($updates === []) {
            return 0;
        }

        return DB::transaction(function () use ($updates) {
            $ids = array_map(fn (array $update) => (int) $update['dish_id'], $updates);

            $alive = 0;

            foreach (array_chunk($ids, 500) as $chunk) {
                $alive += DB::table(self::TABLE)->whereIn('dish_id', $chunk)->whereNull('deleted_at')->lockForUpdate()->count();
            }

            if ($alive !== count(array_unique($ids))) {
                throw new DishGoneException();
            }

            $now = now();

            foreach (array_chunk($updates, 100) as $chunk) {
                DB::table(self::TABLE)->upsert(
                    array_map(fn (array $update) => ['dish_id' => (int) $update['dish_id']]
                        + Arr::except(self::values($update), ['image'])
                        + ['created_at' => $now, 'updated_at' => $now], $chunk),
                    ['dish_id'],
                    [...array_diff(self::COLUMNS, ['image']), 'updated_at'],
                );
            }

            // The type links of the dishes whose types changed: one delete, one bulk insert.
            $retyped = array_values(array_filter($updates, fn (array $update) => $update['dish_types'] !== null));

            foreach (array_chunk($retyped, 500) as $chunk) {
                DB::table(self::PIVOT)->whereIn('dish_id', array_map(fn (array $update) => (int) $update['dish_id'], $chunk))->delete();
            }

            $links = [];

            foreach ($retyped as $update) {
                foreach (array_unique(array_map('intval', $update['dish_types'])) as $dishTypeId) {
                    $links[] = ['dish_id' => (int) $update['dish_id'], 'dish_type_id' => $dishTypeId];
                }
            }

            foreach (array_chunk($links, 500) as $chunk) {
                DB::table(self::PIVOT)->insert($chunk);
            }

            return count($updates);
        });
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

    // Soft delete many dishes with ONE statement per 500 (the import's replace mode); returns how many
    // were removed. Like the Delete button it keeps the row and the picture's file, so nothing is lost
    // for good.
    public static function deleteMany(array $dishIds): int
    {
        $now = now();
        $removed = 0;

        foreach (array_chunk(array_map('intval', $dishIds), 500) as $chunk) {
            $removed += DB::table(self::TABLE)
                ->whereIn('dish_id', $chunk)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $now, 'updated_at' => $now]);
        }

        return $removed;
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

    // Keep only the writable columns, and store the two flags (is_active, includes_rice) as 0 or 1.
    protected static function values(array $attributes): array
    {
        $values = Arr::only($attributes, self::COLUMNS);

        foreach (['is_active', 'includes_rice'] as $flag) {
            if (array_key_exists($flag, $values)) {
                $values[$flag] = (int) (bool) $values[$flag];
            }
        }

        return $values;
    }

    // Dish types of this dish (Eloquent side of the model).
    public function dishTypes()
    {
        return $this->belongsToMany(DishType::class, self::PIVOT, 'dish_id', 'dish_type_id');
    }
}
