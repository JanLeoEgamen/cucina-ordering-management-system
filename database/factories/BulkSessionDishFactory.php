<?php

namespace Database\Factories;

use App\Models\BulkSession;
use App\Models\BulkSessionDish;
use App\Models\Dish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BulkSessionDish>
 */
class BulkSessionDishFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BulkSessionDish::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bulk_session_id' => BulkSession::factory(),
            'dish_id' => Dish::factory(),
            'is_available' => true,
            'is_active' => true,
        ];
    }
}
