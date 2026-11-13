<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishType;
use Illuminate\Http\File;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

// The real menu, read from two files in database/seeders/data/:
//   dish_types.csv  every dish type with its description (all of them are seeded,
//                   also a type no dish uses yet)
//   menu.csv        one row per dish; "Dish Type Names" lists ALL the types of the
//                   dish, separated by ";" (e.g. "Breakfast; Silog"), because a
//                   dish can sit in many types. Never write two types as "A / B".
// Edit the CSVs, not this class, to change the menu.
//
// Re-running keeps what the app already holds: a type and a dish are looked up by
// name and only created when missing, so prices and edits made in the app are not
// overwritten (the CSV prices are 0.00 placeholders to be set there).
//
// Photos: put a picture named after the dish slug in database/seeders/data/images/
// (e.g. chicken-adobo.jpg, beef-bulgogi.webp) and it is copied to the public disk
// and attached to the dish. A dish that already has an image keeps it.
class MenuSeeder extends Seeder
{
    // Columns of dish_types.csv, by header text.
    private const TYPE = 'Dish Type Name';
    private const TYPE_DESCRIPTION = 'Dish Type Description';

    // Columns of menu.csv, by header text.
    private const TYPES = 'Dish Type Names';
    private const NAME = 'Dish Name';
    private const DESCRIPTION = 'Description';
    private const PRICE = 'Price';
    private const CALORIES = 'Calories';
    private const INGREDIENTS = 'Main Ingredients';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function run(): void
    {
        // Every type first, so each one exists even when no dish uses it yet.
        $typeIds = [];

        foreach ($this->rows($this->typesPath()) as $row) {
            $typeIds[$row[self::TYPE]] = $this->type($row[self::TYPE], $row[self::TYPE_DESCRIPTION])->dish_type_id;
        }

        // Databases seeded before the split still hold "Breakfast / Silog" style types.
        $this->splitCombinedTypes($typeIds);

        foreach ($this->rows($this->csvPath()) as $row) {
            $dish = Dish::firstOrCreate(
                ['dish_name' => $row[self::NAME]],
                [
                    'description' => $row[self::DESCRIPTION] ?: null,
                    'price' => (float) $row[self::PRICE],
                    'calories' => $row[self::CALORIES] === '' ? null : (int) $row[self::CALORIES],
                    'main_ingredients' => $row[self::INGREDIENTS] ?: null,
                    'is_active' => true,
                ]
            );

            // A type missing from dish_types.csv is still created, so a typo in the
            // menu cannot silently drop a category.
            $ids = [];

            foreach ($this->typeNames($row[self::TYPES]) as $name) {
                $ids[] = $typeIds[$name] ??= $this->type($name, null)->dish_type_id;
            }

            // syncWithoutDetaching: a second run must not add the same type twice,
            // and a type the app added to the dish stays.
            $dish->dishTypes()->syncWithoutDetaching($ids);

            $this->attachImage($dish);
        }
    }

    // The live type with this name, created when missing (a soft deleted one is not reused).
    protected function type(string $name, ?string $description): DishType
    {
        return DishType::firstOrCreate(
            ['type_name' => $name],
            ['description' => $description ?: null, 'is_active' => true]
        );
    }

    // "Breakfast; Silog" (or the old "Breakfast / Silog") => ['Breakfast', 'Silog'].
    protected function typeNames(string $cell): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[;\/]/', $cell)))));
    }

    // Turn an existing "A / B" type into the types A and B: its dishes move to both
    // and the combined type is soft deleted. Only a name whose parts are all known
    // types is touched, so a type an admin made on purpose is left alone.
    protected function splitCombinedTypes(array $typeIds): void
    {
        $combined = DishType::where('type_name', 'like', '%/%')->get();

        foreach ($combined as $old) {
            $parts = $this->typeNames($old->type_name);

            if (count($parts) < 2 || array_diff($parts, array_keys($typeIds)) !== []) {
                continue;
            }

            $dishIds = $old->dishes()->pluck('dishes.dish_id')->all();

            foreach ($parts as $part) {
                DishType::find($typeIds[$part])->dishes()->syncWithoutDetaching($dishIds);
            }

            $old->dishes()->detach();
            $old->delete();
        }
    }
    // Copy a matching picture to the public disk, for a dish that has none yet.
    protected function attachImage(Dish $dish): void
    {
        if ($dish->image) {
            return;
        }

        $slug = Str::slug($dish->dish_name);

        foreach (self::IMAGE_EXTENSIONS as $extension) {
            $path = $this->imageDirectory() . DIRECTORY_SEPARATOR . $slug . '.' . $extension;

            if (is_file($path)) {
                $dish->update(['image' => Storage::disk('public')->putFileAs('dishes', new File($path), $slug . '.' . $extension)]);

                return;
            }
        }
    }

    protected function imageDirectory(): string
    {
        return database_path('seeders/data/images');
    }

    protected function csvPath(): string
    {
        return database_path('seeders/data/menu.csv');
    }

    protected function typesPath(): string
    {
        return database_path('seeders/data/dish_types.csv');
    }

    /**
     * A CSV as rows keyed by header, skipping blank lines.
     *
     * @return array<int, array<string, string>>
     */
    protected function rows(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('Cannot read ' . $path);
        }

        $header = array_map(fn ($name) => trim((string) $name), fgetcsv($handle) ?: []);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($line === [null]) {
                continue;
            }

            $row = array_combine($header, array_pad(array_map('trim', $line), count($header), ''));

            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}
