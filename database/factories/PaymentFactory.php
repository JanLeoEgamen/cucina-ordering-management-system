<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Payment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The order id is the primary key, so it is always given.
            'order_id' => Order::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'verified_by' => null,
            'verified_at' => null,
            'amount' => fake()->randomFloat(2, 10, 300),
            'reference_number' => fake()->numerify('REF-########'),
            'proof_of_payment' => null,
            'payment_status' => 'pending',
            'is_active' => true,
        ];
    }
}
