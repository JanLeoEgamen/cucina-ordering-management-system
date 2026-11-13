<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SessionDish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = OrderItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 4);
        $unitPrice = fake()->randomFloat(2, 5, 50);

        return [
            'order_id' => Order::factory(),
            'session_dish_id' => SessionDish::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'sub_total' => round($quantity * $unitPrice, 2),
            'is_active' => true,
        ];
    }
}
