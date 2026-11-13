<?php

namespace Database\Factories;

use App\Models\BulkOrder;
use App\Models\BulkSession;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BulkOrder>
 */
class BulkOrderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BulkOrder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bulk_session_id' => BulkSession::factory(),
            'user_id' => User::factory(),
            'order_number' => 'BLK-' . now()->format('Ymd') . '-' . fake()->unique()->numerify('####'),
            'order_date' => now(),
            'total_amount' => fake()->randomFloat(2, 500, 5000),
            'order_status' => 'pending',
            'payment_method_id' => PaymentMethod::factory(),
            'payment_status' => 'pending',
            'is_active' => true,
        ];
    }
}
