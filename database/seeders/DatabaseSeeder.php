<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );

        $dishTypes = DishType::factory()->count(5)->create();

        Dish::factory()->count(20)->create()->each(function (Dish $dish) use ($dishTypes) {
            $dish->dishTypes()->attach($dishTypes->random()->dish_type_id);
        });
    }
}
