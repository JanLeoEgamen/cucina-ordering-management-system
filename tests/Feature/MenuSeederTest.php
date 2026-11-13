<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuSeederTest extends TestCase
{
    use RefreshDatabase;

    // Data lines of the CSV, so the test follows the file instead of a hard coded count.
    private function csvRows(): array
    {
        $lines = array_filter(array_map('trim', file(database_path('seeders/data/menu.csv'))));

        return array_map('str_getcsv', array_slice($lines, 1));
    }

    public function test_it_seeds_every_dish_with_exactly_one_type(): void
    {
        $this->seed(MenuSeeder::class);

        $rows = $this->csvRows();

        $this->assertSame(count($rows), Dish::count());
        $this->assertSame(count(array_unique(array_column($rows, 0))), DishType::count());

        $dish = Dish::where('dish_name', 'Beef Bulgogi')->firstOrFail();

        $this->assertSame('Korean-style marinated beef', $dish->description);
        $this->assertSame('Beef; soy sauce; garlic; sesame oil', $dish->main_ingredients);
        $this->assertNull($dish->calories);
        $this->assertEquals(0, $dish->price);
        $this->assertTrue((bool) $dish->is_active);
        $this->assertSame(['Beef'], $dish->dishTypes()->pluck('type_name')->all());

        // Names with a slash survive as they are written.
        $this->assertTrue(DishType::where('type_name', 'Breakfast / Silog')->exists());
    }

    public function test_running_it_again_adds_nothing_and_keeps_edits_made_in_the_app(): void
    {
        $this->seed(MenuSeeder::class);

        Dish::where('dish_name', 'Mechado')->update(['price' => 9.50]);

        $this->seed(MenuSeeder::class);

        $this->assertSame(count($this->csvRows()), Dish::count());
        $this->assertEquals(9.5, Dish::where('dish_name', 'Mechado')->value('price'));
        $this->assertSame(1, Dish::where('dish_name', 'Mechado')->firstOrFail()->dishTypes()->count());
    }
}
