<?php

namespace Database\Factories;

use App\Models\Dish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Dish>
 */
class DishFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Dish::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // No dish_type_id: types are attached through dish_dish_types.
            'dish_name' => fake()->randomElement([
                'Grilled Chicken', 'Beef Steak', 'Caesar Salad', 'Chocolate Cake',
                'Mushroom Soup', 'Fresh Orange Juice', 'Greek Salad', 'Tiramisu',
                'Pasta Carbonara', 'Fish and Chips', 'Vegetable Stir Fry', 'Beef Burger',
                'Margherita Pizza', 'Chicken Curry', 'Sushi Roll', 'Apple Pie',
            ]),
            'description' => fake()->sentence(),
            'image' => fake()->imageUrl(640, 480, 'food', true),
            'price' => fake()->randomFloat(2, 5, 50),
            'calories' => fake()->numberBetween(100, 800),
            'main_ingredients' => fake()->sentence(6),
            // No rice flagged until a test (or staff) says so: see the `withRice()` state.
            'includes_rice' => false,
            'is_active' => true,
        ];
    }

    // A dish that normally comes with rice (the only kind that can be ordered ala carte).
    public function withRice(): static
    {
        return $this->state(fn () => ['includes_rice' => true]);
    }
}