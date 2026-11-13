<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishType;
use Illuminate\Database\Seeder;

// The dishes the menu starts with. Each one names the types it belongs to, so
// the pivot is filled by name and the seeder does not depend on ids.
class DishSeeder extends Seeder
{
    // A Filipino menu: four starters, eight mains, four sides, four desserts and
    // three drinks. The images are left empty; the menu shows its placeholder
    // until a photo is uploaded from the dish drawer.
    public const DISHES = [
        // Appetizers
        ['dish_name' => 'Lumpiang Shanghai', 'types' => ['Appetizers'], 'description' => 'Crisp pork spring rolls served with sweet chili sauce.', 'price' => 6.50, 'calories' => 320, 'main_ingredients' => 'Ground pork, carrots, onions, spring roll wrapper'],
        ['dish_name' => "Tokwa't Baboy", 'types' => ['Appetizers'], 'description' => 'Crisp tofu and pork in a vinegar and soy dressing.', 'price' => 7.00, 'calories' => 290, 'main_ingredients' => 'Tofu, pork belly, vinegar, soy sauce, onion'],
        ['dish_name' => 'Ukoy', 'types' => ['Appetizers'], 'description' => 'Shrimp and vegetable fritters with a vinegar dip.', 'price' => 6.00, 'calories' => 260, 'main_ingredients' => 'Shrimp, bean sprouts, sweet potato, egg, flour'],
        ['dish_name' => 'Kinilaw', 'types' => ['Appetizers'], 'description' => 'Fresh fish cured in vinegar, ginger and chili.', 'price' => 8.50, 'calories' => 180, 'main_ingredients' => 'Fresh fish, vinegar, ginger, chili, calamansi'],

        // Main Courses
        ['dish_name' => 'Chicken Adobo', 'types' => ['Main Courses'], 'description' => 'Chicken braised in soy sauce, vinegar and garlic.', 'price' => 12.50, 'calories' => 480, 'main_ingredients' => 'Chicken, soy sauce, vinegar, garlic, black pepper, bay leaf'],
        ['dish_name' => 'Pork Sinigang', 'types' => ['Main Courses'], 'description' => 'Sour tamarind soup with pork and vegetables.', 'price' => 13.00, 'calories' => 390, 'main_ingredients' => 'Pork ribs, tamarind, kangkong, radish, tomato, sitaw'],
        ['dish_name' => 'Lechon Kawali', 'types' => ['Main Courses'], 'description' => 'Crispy pork belly with a liver sauce on the side.', 'price' => 14.50, 'calories' => 620, 'main_ingredients' => 'Pork belly, garlic, bay leaf, peppercorn, liver sauce'],
        ['dish_name' => 'Beef Kare-Kare', 'types' => ['Main Courses'], 'description' => 'Beef and vegetables in a thick peanut stew.', 'price' => 15.00, 'calories' => 550, 'main_ingredients' => 'Beef shank, peanut butter, eggplant, banana blossom, bagoong'],
        ['dish_name' => 'Chicken Inasal', 'types' => ['Main Courses'], 'description' => 'Grilled chicken marinated in calamansi, lemongrass and annatto.', 'price' => 13.50, 'calories' => 470, 'main_ingredients' => 'Chicken, calamansi, lemongrass, garlic, annatto oil'],
        ['dish_name' => 'Bicol Express', 'types' => ['Main Courses'], 'description' => 'Pork simmered in coconut milk with chili and shrimp paste.', 'price' => 12.00, 'calories' => 520, 'main_ingredients' => 'Pork belly, coconut milk, chili, shrimp paste, garlic'],
        ['dish_name' => 'Pork Menudo', 'types' => ['Main Courses'], 'description' => 'Pork and liver stew with potatoes, carrots and tomato.', 'price' => 11.50, 'calories' => 430, 'main_ingredients' => 'Pork, liver, potato, carrot, tomato sauce, raisins'],
        ['dish_name' => 'Beef Caldereta', 'types' => ['Main Courses'], 'description' => 'Slow cooked beef in tomato and liver sauce with peppers.', 'price' => 14.00, 'calories' => 500, 'main_ingredients' => 'Beef, tomato sauce, liver spread, bell pepper, potato, carrot'],

        // Sides
        ['dish_name' => 'Garlic Rice', 'types' => ['Sides'], 'description' => 'Fried rice tossed with crisp garlic.', 'price' => 3.00, 'calories' => 220, 'main_ingredients' => 'Rice, garlic, oil, salt'],
        ['dish_name' => 'Pandesal (4 pcs)', 'types' => ['Sides'], 'description' => 'Warm bread rolls baked in house.', 'price' => 2.50, 'calories' => 180, 'main_ingredients' => 'Flour, yeast, milk, butter, breadcrumbs'],
        ['dish_name' => 'Ensaladang Talong', 'types' => ['Sides'], 'description' => 'Grilled eggplant with tomato and onion.', 'price' => 4.00, 'calories' => 90, 'main_ingredients' => 'Eggplant, tomato, onion, vinegar'],
        ['dish_name' => 'Atchara', 'types' => ['Sides'], 'description' => 'Pickled green papaya with carrot and ginger.', 'price' => 2.50, 'calories' => 60, 'main_ingredients' => 'Green papaya, carrot, ginger, vinegar, sugar'],

        // Desserts
        // Two types: it is a dessert the drinks list also offers.
        ['dish_name' => 'Halo-Halo', 'types' => ['Desserts', 'Beverages'], 'description' => 'Shaved ice with beans, jackfruit, leche flan and ube.', 'price' => 6.50, 'calories' => 380, 'main_ingredients' => 'Shaved ice, sweet beans, jackfruit, leche flan, ube, evaporated milk'],
        ['dish_name' => 'Leche Flan', 'types' => ['Desserts'], 'description' => 'Steamed custard with a caramel top.', 'price' => 5.50, 'calories' => 340, 'main_ingredients' => 'Egg yolk, condensed milk, evaporated milk, sugar, vanilla'],
        ['dish_name' => 'Buko Pandan', 'types' => ['Desserts'], 'description' => 'Young coconut strips in pandan jelly cream.', 'price' => 5.00, 'calories' => 300, 'main_ingredients' => 'Young coconut, pandan jelly, cream, condensed milk'],
        ['dish_name' => 'Turon', 'types' => ['Desserts'], 'description' => 'Caramelised banana spring rolls.', 'price' => 4.00, 'calories' => 260, 'main_ingredients' => 'Saba banana, jackfruit, brown sugar, spring roll wrapper'],

        // Beverages
        ['dish_name' => "Sago't Gulaman", 'types' => ['Beverages'], 'description' => 'Brown sugar cooler with tapioca pearls and jelly.', 'price' => 3.50, 'calories' => 150, 'main_ingredients' => 'Brown sugar, tapioca pearls, gulaman, water'],
        ['dish_name' => 'Fresh Buko Juice', 'types' => ['Beverages'], 'description' => 'Chilled coconut water straight from the shell.', 'price' => 4.00, 'calories' => 90, 'main_ingredients' => 'Coconut water, young coconut meat'],
        ['dish_name' => 'Calamansi Juice', 'types' => ['Beverages'], 'description' => 'Calamansi juice sweetened with honey.', 'price' => 3.00, 'calories' => 80, 'main_ingredients' => 'Calamansi, honey, water, ice'],
    ];

    public function run(): void
    {
        // The types first, so this seeder also works when it is run on its own.
        $this->call(DishTypeSeeder::class);

        // Type ids by name: a dish lists names, so no id has to be hard coded.
        $typeIds = DishType::pluck('dish_type_id', 'type_name');

        foreach (self::DISHES as $dish) {
            $typeNames = $dish['types'];
            unset($dish['types']);

            // A dish is looked up by its name, so running the seeder again keeps
            // its row (and any edits made to it in the app).
            $row = Dish::firstOrCreate(
                ['dish_name' => $dish['dish_name']],
                $dish + ['is_active' => true]
            );

            // syncWithoutDetaching: a second run must not add the same type twice.
            $row->dishTypes()->syncWithoutDetaching(
                collect($typeNames)
                    ->map(fn (string $name) => $typeIds[$name] ?? null)
                    ->filter()
                    ->values()
                    ->all()
            );
        }
    }
}