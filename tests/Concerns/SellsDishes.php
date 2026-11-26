<?php

namespace Tests\Concerns;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\User;
use App\Support\Analytics\Period;

// Sample trade for the analytics tests: a 09:00-17:00 session per day, dishes with their stock in
// it, and orders placed at a given moment with their lines and payment.
trait SellsDishes
{
    private array $sessions = [];

    private array $lines = [];

    private array $methods = [];

    private int $number = 0;

    // The 09:00-17:00 session of a day (made once per day).
    private function window(string $day): OrderSession
    {
        return $this->sessions[$day] ??= OrderSession::factory()->create(['order_date' => $day, 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
    }

    private function dish(string $name, string $day = '2026-11-25', int $available = 10, int $reserved = 0, array $attributes = []): SessionDish
    {
        $dishId = Dish::query()->where('dish_name', $name)->value('dish_id')
            ?? Dish::factory()->create($attributes + ['dish_name' => $name, 'is_active' => true])->dish_id;

        return $this->lines[$day][$name] ??= SessionDish::factory()->create([
            'session_id' => $this->window($day)->session_id,
            'dish_id' => $dishId,
            'available_stock' => $available,
            'reserved_stock' => $reserved,
        ]);
    }

    private function method(string $name): int
    {
        return $this->methods[$name] ??= (int) PaymentMethod::factory()->create(['method_name' => $name, 'is_active' => true])->method_id;
    }

    /**
     * An order placed at $at, in that day's session. $lines: [dish name, plates, no rice?, sub total].
     */
    private function order(string $at, string $status = 'completed', float $total = 100, string $method = 'GCash', array $lines = [], array $order = [], array $payment = []): Order
    {
        $day = substr($at, 0, 10);
        $paid = $payment['payment_status'] ?? ($status === 'completed' ? 'verified' : ($status === 'cancelled' ? 'rejected' : 'pending'));

        $placed = Order::factory()->create($order + [
            'session_id' => $this->window($day)->session_id,
            'user_id' => User::factory(),
            'order_number' => 'ORD-T-' . str_pad((string) ++$this->number, 4, '0', STR_PAD_LEFT),
            'order_date' => $at,
            'total_amount' => $total,
            'order_status' => $status,
            'payment_status' => $paid,
        ]);

        foreach ($lines as $line) {
            OrderItem::factory()->create([
                'order_id' => $placed->order_id,
                'session_dish_id' => $this->dish($line[0], $day)->session_dish_id,
                'quantity' => $line[1],
                'no_rice' => $line[2] ?? false,
                'sub_total' => $line[3] ?? $line[1] * 50,
            ]);
        }

        Payment::factory()->create($payment + [
            'order_id' => $placed->order_id,
            'payment_method_id' => $this->method($method),
            'amount' => $total,
            'payment_status' => $paid,
            'verified_at' => $paid === 'verified' ? $at : null,
            'reference_number' => null,
        ]);

        return $placed;
    }

    private function range(string $key = '30d'): Period
    {
        return Period::fromInput(['range' => $key]);
    }

    private function between(string $from, string $to): Period
    {
        return Period::fromInput(['range' => 'custom', 'from' => $from, 'to' => $to]);
    }
}
