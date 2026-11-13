<?php

namespace Database\Seeders;

use App\Models\DishType;
use Illuminate\Database\Seeder;

// The dish types the menu is grouped by. Running it again keeps the rows it
// already made: a type is looked up by its name.
class DishTypeSeeder extends Seeder
{
    // The types, in the order the menu is read.
    public const TYPES = [
        ['type_name' => 'Appetizers', 'description' => 'Small plates to start the meal.'],
        ['type_name' => 'Main Courses', 'description' => 'The plates the kitchen is known for.'],
        ['type_name' => 'Sides', 'description' => 'Rice, breads and vegetables to go with a main.'],
        ['type_name' => 'Desserts', 'description' => 'Something sweet to finish.'],
        ['type_name' => 'Beverages', 'description' => 'Cold drinks and juices.'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            DishType::firstOrCreate(
                ['type_name' => $type['type_name']],
                $type + ['is_active' => true]
            );
        }
    }
}