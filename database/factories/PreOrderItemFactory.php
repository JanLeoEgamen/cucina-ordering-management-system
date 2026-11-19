<?php

namespace Database\Factories;

use App\Models\PreOrder;
use App\Models\PreOrderItem;
use App\Models\PreOrderSessionDish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PreOrderItem>
 */
class PreOrderItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PreOrderItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(10, 40);
        $unitPrice = fake()->randomFloat(2, 5, 50);

        return [
            'pre_order_id' => PreOrder::factory(),
            'pre_order_session_dish_id' => PreOrderSessionDish::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'sub_total' => round($quantity * $unitPrice, 2),
            'is_active' => true,
        ];
    }
}
