<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Order::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => OrderSession::factory(),
            'user_id' => User::factory(),
            'order_number' => 'ORD-' . now()->format('Ymd') . '-' . fake()->unique()->numerify('####'),
            'order_date' => now(),
            'total_amount' => fake()->randomFloat(2, 10, 300),
            'order_status' => 'pending',
            'payment_status' => 'pending',
            'is_active' => true,
        ];
    }
}
