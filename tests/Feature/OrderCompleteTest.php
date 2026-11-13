<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCompleteTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    /**
     * An order with a payment by the given method, in the given states.
     */
    private function order(string $method, string $paymentStatus = 'pending', string $orderStatus = 'pending', ?OrderSession $session = null): Order
    {
        $order = Order::factory()->create([
            'session_id' => ($session ?? OrderSession::factory()->create())->session_id,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
        ]);

        Payment::factory()->create([
            'order_id' => $order->order_id,
            'payment_method_id' => PaymentMethod::factory()->create(['method_name' => $method])->method_id,
            'payment_status' => $paymentStatus,
        ]);

        return $order;
    }

    public function test_completing_a_cash_order_verifies_its_payment(): void
    {
        $admin = $this->superAdmin();
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($admin)
            ->postJson(route('orders.complete', $order->order_id))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'completed', 'payment_status' => 'verified']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->order_id, 'payment_status' => 'verified', 'verified_by' => $admin->id]);
    }

    public function test_a_bank_order_cannot_be_completed_until_its_payment_is_verified(): void
    {
        $admin = $this->superAdmin();
        $order = $this->order('GCash');

        $this->actingAs($admin)
            ->postJson(route('orders.complete', $order->order_id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);

        // The payment can be verified as soon as the order exists - then it completes.
        $this->actingAs($admin)->postJson(route('payments.verify', $order->order_id))->assertOk();
        $this->actingAs($admin)->postJson(route('orders.complete', $order->order_id))->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'completed', 'payment_status' => 'verified']);
    }

    public function test_a_bank_order_with_a_rejected_payment_cannot_be_completed(): void
    {
        $order = $this->order('GCash', 'rejected');

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.complete', $order->order_id))
            ->assertStatus(422);

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);
    }

    public function test_only_a_pending_order_can_be_completed_and_it_must_exist(): void
    {
        $admin = $this->superAdmin();

        foreach (['completed', 'cancelled'] as $status) {
            $order = $this->order(PaymentMethod::CASH_ON_PICKUP, 'verified', $status);

            $this->actingAs($admin)->postJson(route('orders.complete', $order->order_id))->assertStatus(422);
        }

        $this->actingAs($admin)->postJson(route('orders.complete', 9999))->assertNotFound();
    }

    public function test_completing_needs_its_own_permission(): void
    {
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $this->postJson(route('orders.complete', $order->order_id))->assertUnauthorized();
        $this->actingAs($this->userWith('verify payments', 'view order-sessions'))
            ->postJson(route('orders.complete', $order->order_id))
            ->assertForbidden();

        $this->actingAs($this->userWith('complete orders'))
            ->postJson(route('orders.complete', $order->order_id))
            ->assertOk();
    }

    public function test_a_cash_payment_cannot_be_verified_or_rejected_by_hand(): void
    {
        $admin = $this->superAdmin();
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($admin)->postJson(route('payments.verify', $order->order_id))->assertStatus(422);
        $this->actingAs($admin)->postJson(route('payments.reject', $order->order_id), ['reason' => 'x'])->assertStatus(422);

        $this->assertDatabaseHas('payments', ['order_id' => $order->order_id, 'payment_status' => 'pending']);
    }

    public function test_the_completed_orders_payment_decision_cannot_be_undone(): void
    {
        $admin = $this->superAdmin();
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($admin)->postJson(route('orders.complete', $order->order_id))->assertOk();
        $this->actingAs($admin)->postJson(route('payments.undo', $order->order_id))->assertStatus(422);

        $this->assertDatabaseHas('payments', ['order_id' => $order->order_id, 'payment_status' => 'verified']);

        // A verified bank payment of an order that is still pending can be undone.
        $open = $this->order('GCash', 'verified');

        $this->actingAs($admin)->postJson(route('payments.undo', $open->order_id))->assertOk();
    }

    public function test_the_session_feed_offers_the_right_buttons_for_each_payment_method(): void
    {
        $session = OrderSession::factory()->create();
        $cash = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'pending', $session);
        $bankPending = $this->order('GCash', 'pending', 'pending', $session);
        $bankVerified = $this->order('Maya', 'verified', 'pending', $session);
        $done = $this->order(PaymentMethod::CASH_ON_PICKUP, 'verified', 'completed', $session);

        $rows = collect(
            $this->actingAs($this->superAdmin())
                ->getJson(route('order-sessions.show', $session->session_id), $this->ajax())
                ->assertOk()
                ->json('data')
        )->keyBy('order_id');

        // Cash: no verify / reject, but it can be completed (that verifies it).
        $this->assertTrue($rows[$cash->order_id]['is_cash']);
        $this->assertNull($rows[$cash->order_id]['verify_url']);
        $this->assertNull($rows[$cash->order_id]['reject_url']);
        $this->assertSame(route('orders.complete', $cash->order_id), $rows[$cash->order_id]['complete_url']);

        // Bank, unverified: verify / reject from the start, but not complete yet.
        $this->assertFalse($rows[$bankPending->order_id]['is_cash']);
        $this->assertNotNull($rows[$bankPending->order_id]['verify_url']);
        $this->assertNotNull($rows[$bankPending->order_id]['reject_url']);
        $this->assertNull($rows[$bankPending->order_id]['complete_url']);

        // Bank, verified: complete is open.
        $this->assertNull($rows[$bankVerified->order_id]['verify_url']);
        $this->assertSame(route('orders.complete', $bankVerified->order_id), $rows[$bankVerified->order_id]['complete_url']);

        // Already completed: nothing left to do.
        $this->assertNull($rows[$done->order_id]['complete_url']);
    }

    public function test_the_order_details_have_the_complete_button_only_for_those_who_may_complete(): void
    {
        $session = OrderSession::factory()->create();
        $url = route('order-sessions.show', $session->session_id);

        $this->actingAs($this->userWith('view order-sessions', 'complete orders'))
            ->get($url)
            ->assertOk()
            ->assertSee('id="order-details-complete"', false)
            ->assertSee('id="order-details-hint"', false)
            // Confirmed inline in the details, not in a second modal stacked on them.
            ->assertSee('id="order-details-confirm"', false)
            ->assertSee('id="order-details-confirm-go"', false);

        $this->actingAs($this->userWith('view order-sessions'))
            ->get($url)
            ->assertDontSee('id="order-details-complete"', false);
    }
}
