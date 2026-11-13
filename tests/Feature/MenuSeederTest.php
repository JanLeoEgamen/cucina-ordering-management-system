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

    // Data lines of a CSV, so the test follows the file instead of a hard coded count.
    private function csvRows(string $file = 'menu.csv'): array
    {
        $lines = array_filter(array_map('trim', file(database_path('seeders/data/' . $file))));

        return array_map('str_getcsv', array_slice($lines, 1));
    }

    public function test_it_seeds_every_dish_and_every_type(): void
    {
        $this->seed(MenuSeeder::class);

        $this->assertSame(count($this->csvRows()), Dish::count());

        // Every type of dish_types.csv exists, also one no dish uses.
        $types = array_column($this->csvRows('dish_types.csv'), 0);
        $this->assertEqualsCanonicalizing($types, DishType::pluck('type_name')->all());

        $dish = Dish::where('dish_name', 'Beef Bulgogi')->firstOrFail();

        $this->assertSame('Korean-style marinated beef', $dish->description);
        $this->assertSame('Beef; soy sauce; garlic; sesame oil', $dish->main_ingredients);
        $this->assertNull($dish->calories);
        $this->assertEquals(0, $dish->price);
        $this->assertTrue((bool) $dish->is_active);
        $this->assertSame(['Beef'], $dish->dishTypes()->pluck('type_name')->all());
    }

    public function test_a_dish_with_several_types_gets_each_one_as_its_own_type(): void
    {
        $this->seed(MenuSeeder::class);

        // No "A / B" type any more: each part is a type of its own.
        $this->assertSame(0, DishType::where('type_name', 'like', '%/%')->count());

        $this->assertEqualsCanonicalizing(
            ['Breakfast', 'Silog'],
            Dish::where('dish_name', 'Tapsilog')->firstOrFail()->dishTypes()->pluck('type_name')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['Desserts', 'Cookies'],
            Dish::where('dish_name', 'Turon')->firstOrFail()->dishTypes()->pluck('type_name')->all()
        );

        // Every dish type listed in menu.csv exists.
        foreach ($this->csvRows() as $row) {
            foreach (array_map('trim', explode(';', $row[0])) as $name) {
                $this->assertTrue(DishType::where('type_name', $name)->exists(), $name);
            }
        }
    }

    public function test_it_splits_an_old_combined_type_and_keeps_its_dishes(): void
    {
        // What the earlier seeder left behind: one type "Breakfast / Silog".
        $old = DishType::create(['type_name' => 'Breakfast / Silog', 'is_active' => true]);
        $dish = Dish::create(['dish_name' => 'Tapsilog', 'price' => 0, 'is_active' => true]);
        $dish->dishTypes()->attach($old->dish_type_id);

        // A type an admin made on purpose, not made of known parts, is left alone.
        $custom = DishType::create(['type_name' => 'Kids / Party', 'is_active' => true]);

        $this->seed(MenuSeeder::class);

        $this->assertSoftDeleted($old);
        $this->assertNotSoftDeleted($custom);
        $this->assertEqualsCanonicalizing(
            ['Breakfast', 'Silog'],
            $dish->fresh()->dishTypes()->pluck('type_name')->all()
        );
        $this->assertSame(1, Dish::where('dish_name', 'Tapsilog')->count());
    }

    public function test_running_it_again_adds_nothing_and_keeps_edits_made_in_the_app(): void
    {
        $this->seed(MenuSeeder::class);

        Dish::where('dish_name', 'Mechado')->update(['price' => 9.50]);

        $this->seed(MenuSeeder::class);

        $this->assertSame(count($this->csvRows()), Dish::count());
        $this->assertEquals(9.5, Dish::where('dish_name', 'Mechado')->value('price'));
        $this->assertSame(1, Dish::where('dish_name', 'Mechado')->firstOrFail()->dishTypes()->count());
        $this->assertSame(2, Dish::where('dish_name', 'Tapsilog')->firstOrFail()->dishTypes()->count());
    }
}
