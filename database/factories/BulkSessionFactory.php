<?php

namespace Database\Factories;

use App\Models\BulkSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BulkSession>
 */
class BulkSessionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BulkSession::class;

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
            'title' => null,
            'order_date' => now()->toDateString(),
            'start_time' => '00:00:00',
            'end_time' => '23:59:59',
            'min_quantity' => 10,
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

    // A session that opens tomorrow.
    public function scheduled(): static
    {
        return $this->state(fn () => [
            'order_date' => now()->addDay()->toDateString(),
        ]);
    }
}
