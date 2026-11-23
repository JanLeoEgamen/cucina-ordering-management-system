<?php

namespace Database\Factories;

use App\Models\Discount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Discount>
 */
class DiscountFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Discount::class;

    /**
     * Define the model's default state: 20 percent, proof required, no dates, on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discount_name' => fake()->unique()->words(2, true) . ' discount',
            'percent' => 20,
            'requires_proof' => true,
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
        ];
    }

    /**
     * A discount switched off: the checkout stops offering it.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * A discount that needs no ID: it is approved the moment the order is placed.
     */
    public function withoutProof(): static
    {
        return $this->state(fn () => ['requires_proof' => false]);
    }
}
