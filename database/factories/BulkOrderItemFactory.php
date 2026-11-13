<?php

namespace Database\Factories;

use App\Models\BulkOrder;
use App\Models\BulkOrderItem;
use App\Models\BulkSessionDish;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BulkOrderItem>
 */
class BulkOrderItemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BulkOrderItem::class;

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
            'bulk_order_id' => BulkOrder::factory(),
            'bulk_session_dish_id' => BulkSessionDish::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'sub_total' => round($quantity * $unitPrice, 2),
            'is_active' => true,
        ];
    }
}
