<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\SessionDish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SessionDish>
 */
class SessionDishFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SessionDish::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => OrderSession::factory(),
            'dish_id' => Dish::factory(),
            'available_stock' => fake()->numberBetween(5, 50),
            'reserved_stock' => 0,
            'is_available' => true,
            'is_active' => true,
        ];
    }
}
