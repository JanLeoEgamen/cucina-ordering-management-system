<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// What an order remembers about being closed: orders.completed_at, cancelled_at, cancelled_by and
// cancel_reason (the analytics read them).
class OrderClosingStampTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A pending order of 3 plates (7 left, 3 reserved) with a pending payment.
     *
     * @return array{0: Order, 1: SessionDish}
     */
    private function order(?User $customer = null, string $paymentStatus = 'pending', string $method = 'GCash'): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create(['session_id' => $session->session_id, 'available_stock' => 7, 'reserved_stock' => 3]);

        $order = Order::factory()->create([
            'user_id' => ($customer ?? User::factory()->create())->id,
            'session_id' => $session->session_id,
            'order_status' => 'pending',
            'payment_status' => $paymentStatus,
        ]);

        OrderItem::factory()->create(['order_id' => $order->order_id, 'session_dish_id' => $line->session_dish_id, 'quantity' => 3]);

        Payment::factory()->create([
            'order_id' => $order->order_id,
            'payment_method_id' => PaymentMethod::factory()->create(['method_name' => $method])->method_id,
            'payment_status' => $paymentStatus,
        ]);

        return [$order, $line];
    }

    private function row(Order $order): object
    {
        return DB::table('orders')->where('order_id', $order->order_id)->first();
    }

    private function assertStillOpen(Order $order): void
    {
        $row = $this->row($order);

        $this->assertSame('pending', $row->order_status);
        $this->assertNull($row->completed_at);
        $this->assertNull($row->cancelled_at);
        $this->assertNull($row->cancelled_by);
        $this->assertNull($row->cancel_reason);
    }

    public function test_a_new_order_has_none_of_them(): void
    {
        [$order] = $this->order();

        $this->assertStillOpen($order);
    }

    public function test_completing_stamps_when(): void
    {
        [$order] = $this->order(method: PaymentMethod::CASH_ON_PICKUP);
        $this->travelTo(Carbon::parse('2026-11-26 12:34:00'));

        $this->actingAs($this->userWith('complete orders'))->postJson(route('orders.complete', $order->order_id))->assertOk();

        $row = $this->row($order);
        $this->assertSame('2026-11-26 12:34:00', $row->completed_at);
        $this->assertNull($row->cancelled_at);
        $this->assertNull($row->cancelled_by);
    }

    public function test_a_staff_cancel_stamps_when_who_and_why(): void
    {
        [$order] = $this->order();
        $staff = $this->userWith('cancel orders');
        $this->travelTo(Carbon::parse('2026-11-26 09:15:00'));

        $this->actingAs($staff)
            ->postJson(route('orders.cancel', $order->order_id), ['reason' => '  Ran out of chicken  '])
            ->assertOk();

        $row = $this->row($order);
        $this->assertSame('2026-11-26 09:15:00', $row->cancelled_at);
        $this->assertSame($staff->id, (int) $row->cancelled_by);
        $this->assertSame('Ran out of chicken', $row->cancel_reason);
        $this->assertNull($row->completed_at);
        // The customer still reads the usual words, not what staff typed.
        $this->assertSame('The order was cancelled.', DB::table('payments')->where('order_id', $order->order_id)->value('rejection_reason'));
    }

    public function test_the_reason_is_optional(): void
    {
        [$order] = $this->order();

        $this->actingAs($this->userWith('cancel orders'))
            ->postJson(route('orders.cancel', $order->order_id), ['reason' => ''])
            ->assertOk();

        $this->assertNull($this->row($order)->cancel_reason);
        $this->assertNotNull($this->row($order)->cancelled_at);
    }

    public function test_a_reason_that_is_too_long_cancels_nothing(): void
    {
        [$order, $line] = $this->order();

        $this->actingAs($this->userWith('cancel orders'))
            ->postJson(route('orders.cancel', $order->order_id), ['reason' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertStillOpen($order);
        $this->assertSame(7, (int) DB::table('session_dishes')->where('session_dish_id', $line->session_dish_id)->value('available_stock'));
    }

    public function test_a_customers_cancel_names_the_customer_and_takes_no_reason(): void
    {
        Setting::set('orders.customer_cancel', '1');
        $customer = $this->userWith('create orders', 'view my orders');
        [$order] = $this->order($customer);

        $this->actingAs($customer)
            ->postJson(route('my-orders.cancel', $order->order_id), ['reason' => 'Sent by hand'])
            ->assertOk();

        $row = $this->row($order);
        $this->assertSame($customer->id, (int) $row->cancelled_by);
        $this->assertNotNull($row->cancelled_at);
        $this->assertNull($row->cancel_reason);
    }

    public function test_a_refused_cancel_or_complete_stamps_nothing(): void
    {
        [$paid] = $this->order(paymentStatus: 'verified');

        $this->actingAs($this->userWith('cancel orders'))
            ->postJson(route('orders.cancel', $paid->order_id), ['reason' => 'No'])
            ->assertStatus(422);

        $this->assertStillOpen($paid);

        [$unpaid] = $this->order();

        $this->actingAs($this->userWith('complete orders'))
            ->postJson(route('orders.complete', $unpaid->order_id))
            ->assertStatus(422);

        $this->assertStillOpen($unpaid);
    }

    public function test_the_cancel_confirmation_has_the_reason_box_for_someone_who_may_only_cancel(): void
    {
        $this->actingAs($this->userWith('view orders', 'cancel orders'))->get(route('orders.index'))
            ->assertOk()
            ->assertSee('id="order-reject-reason"', false)
            ->assertSee('Reason (optional)');

        $this->actingAs($this->userWith('view orders'))->get(route('orders.index'))
            ->assertOk()
            ->assertDontSee('id="order-reject-reason"', false);
    }
}
