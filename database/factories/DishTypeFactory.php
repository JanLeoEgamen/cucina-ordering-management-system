<?php

namespace Database\Factories;

use App\Models\DishType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DishType>
 */
class DishTypeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = DishType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type_name' => fake()->randomElement([
                'Appetizers', 'Main Course', 'Desserts', 'Beverages', 'Soups', 'Salads',
            ]),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}