<?php

namespace Database\Factories;

use App\Models\OrderSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OrderSession>
 */
class OrderSessionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = OrderSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A whole day, so a session made in a test is live the moment it is
        // created (the window starts before now and ends after it).
        return [
            'created_by' => User::factory(),
            'order_date' => now()->toDateString(),
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'ended_at' => null,
            'is_active' => true,
        ];
    }

    // A session that already went past its end time.
    public function ended(): static
    {
        return $this->state(fn () => [
            'order_date' => now()->subDay()->toDateString(),
        ]);
    }
}
