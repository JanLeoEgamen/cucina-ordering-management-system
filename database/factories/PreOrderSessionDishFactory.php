<?php

namespace Database\Factories;

use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Dish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PreOrderSessionDish>
 */
class PreOrderSessionDishFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PreOrderSessionDish::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pre_order_session_id' => PreOrderSession::factory(),
            'dish_id' => Dish::factory(),
            'is_available' => true,
            'is_active' => true,
        ];
    }
}
