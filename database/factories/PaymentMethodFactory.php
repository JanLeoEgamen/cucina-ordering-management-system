<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PaymentMethod::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'method_name' => fake()->randomElement(['GCash', 'Maya', 'Bank Transfer', 'Credit Card']),
            'account_name' => fake()->name(),
            'account_number' => fake()->numerify('####-####-####'),
            // No QR by default: the checkout only shows one when it is set.
            'qr_image' => null,
            'is_active' => true,
        ];
    }
}
