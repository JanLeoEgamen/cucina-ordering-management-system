<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishType;
use Illuminate\Http\File;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

// The real menu, read from database/seeders/data/menu.csv: one row per dish, with
// the type it belongs to (and the type's description) on the same row. Edit the
// CSV, not this class, to change the menu.
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
    // Columns of the CSV, by header text.
    private const TYPE = 'Dish Type Name';
    private const TYPE_DESCRIPTION = 'Dish Type Description';
    private const NAME = 'Dish Name';
    private const DESCRIPTION = 'Description';
    private const PRICE = 'Price';
    private const CALORIES = 'Calories';
    private const INGREDIENTS = 'Main Ingredients';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function run(): void
    {
        foreach ($this->rows() as $row) {
            $type = DishType::firstOrCreate(
                ['type_name' => $row[self::TYPE]],
                ['description' => $row[self::TYPE_DESCRIPTION] ?: null, 'is_active' => true]
            );

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

            // syncWithoutDetaching: a second run must not add the same type twice.
            $dish->dishTypes()->syncWithoutDetaching([$type->dish_type_id]);

            $this->attachImage($dish);
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

    /**
     * The CSV as rows keyed by header, skipping blank lines.
     *
     * @return array<int, array<string, string>>
     */
    protected function rows(): array
    {
        $handle = fopen($this->csvPath(), 'r');

        if ($handle === false) {
            throw new RuntimeException('Cannot read ' . $this->csvPath());
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
