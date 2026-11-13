<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    /**
     * A pending order of 3 plates whose stock is reserved (7 left, 3 reserved).
     *
     * @return array{0: Order, 1: SessionDish}
     */
    private function order(string $method = 'GCash', string $paymentStatus = 'pending', string $orderStatus = 'pending'): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create(['session_id' => $session->session_id, 'available_stock' => 7, 'reserved_stock' => 3]);

        $order = Order::factory()->create([
            'session_id' => $session->session_id,
            'order_status' => $orderStatus,
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

    public function test_cancelling_releases_the_stock_and_rejects_the_pending_payment(): void
    {
        $admin = $this->superAdmin();
        [$order, $line] = $this->order();

        $this->actingAs($admin)
            ->postJson(route('orders.cancel', $order->order_id))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'cancelled', 'payment_status' => 'rejected']);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->order_id,
            'payment_status' => 'rejected',
            'verified_by' => $admin->id,
            'rejection_reason' => 'The order was cancelled.',
        ]);
        // The 3 plates are back on the menu and no longer reserved.
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 10, 'reserved_stock' => 0]);
    }

    public function test_a_cash_order_can_be_cancelled_too(): void
    {
        [$order, $line] = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($this->superAdmin())->postJson(route('orders.cancel', $order->order_id))->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'cancelled']);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 10]);
    }

    public function test_an_order_with_a_verified_payment_is_not_cancelled_until_the_verification_is_undone(): void
    {
        $admin = $this->superAdmin();
        [$order, $line] = $this->order('GCash', 'verified');

        $this->actingAs($admin)->postJson(route('orders.cancel', $order->order_id))->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 7, 'reserved_stock' => 3]);

        $this->actingAs($admin)->postJson(route('payments.undo', $order->order_id))->assertOk();
        $this->actingAs($admin)->postJson(route('orders.cancel', $order->order_id))->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'cancelled']);
    }

    public function test_only_a_pending_order_can_be_cancelled_and_it_must_exist(): void
    {
        $admin = $this->superAdmin();

        foreach (['completed', 'cancelled'] as $status) {
            [$order, $line] = $this->order(PaymentMethod::CASH_ON_PICKUP, 'verified', $status);

            $this->actingAs($admin)->postJson(route('orders.cancel', $order->order_id))->assertStatus(422);
            // Nothing was given back for an order that was not pending.
            $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 7, 'reserved_stock' => 3]);
        }

        $this->actingAs($admin)->postJson(route('orders.cancel', 9999))->assertNotFound();
    }

    public function test_cancelling_needs_its_own_permission(): void
    {
        [$order] = $this->order();

        $this->postJson(route('orders.cancel', $order->order_id))->assertUnauthorized();
        $this->actingAs($this->userWith('complete orders', 'view order-sessions'))
            ->postJson(route('orders.cancel', $order->order_id))
            ->assertForbidden();

        $this->actingAs($this->userWith('cancel orders'))
            ->postJson(route('orders.cancel', $order->order_id))
            ->assertOk();
    }

    public function test_a_cancelled_order_cannot_be_completed_and_its_payment_cannot_be_decided(): void
    {
        $admin = $this->superAdmin();
        [$order] = $this->order();

        $this->actingAs($admin)->postJson(route('orders.cancel', $order->order_id))->assertOk();

        $this->actingAs($admin)->postJson(route('orders.complete', $order->order_id))->assertStatus(422);
        $this->actingAs($admin)->postJson(route('payments.verify', $order->order_id))->assertStatus(422);
        $this->actingAs($admin)->postJson(route('payments.reject', $order->order_id), ['reason' => 'x'])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('payments.undo', $order->order_id))->assertStatus(422);

        // ...and the Payments page offers no decision for it either.
        $row = $this->actingAs($admin)->getJson(route('payments.index'), $this->ajax())->json('data.0');

        $this->assertNull($row['verify_url']);
        $this->assertNull($row['reject_url']);
        $this->assertNull($row['undo_url']);
    }

    public function test_a_second_cancel_does_not_release_the_stock_twice(): void
    {
        $admin = $this->superAdmin();
        [$order, $line] = $this->order();

        $this->actingAs($admin)->postJson(route('orders.cancel', $order->order_id))->assertOk();
        $this->actingAs($admin)->postJson(route('orders.cancel', $order->order_id))->assertStatus(422);

        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 10, 'reserved_stock' => 0]);
    }

    public function test_the_session_feed_offers_cancel_only_where_it_can_succeed(): void
    {
        $session = OrderSession::factory()->create();

        $make = function (string $paymentStatus, string $orderStatus) use ($session): Order {
            $order = Order::factory()->create(['session_id' => $session->session_id, 'order_status' => $orderStatus, 'payment_status' => $paymentStatus]);

            Payment::factory()->create([
                'order_id' => $order->order_id,
                'payment_method_id' => PaymentMethod::factory()->create(['method_name' => 'GCash'])->method_id,
                'payment_status' => $paymentStatus,
            ]);

            return $order;
        };

        $pending = $make('pending', 'pending');
        $verified = $make('verified', 'pending');
        $completed = $make('verified', 'completed');
        $cancelled = $make('rejected', 'cancelled');

        $rows = fn ($user) => collect(
            $this->actingAs($user)
                ->getJson(route('order-sessions.show', $session->session_id), $this->ajax())
                ->assertOk()
                ->json('data')
        )->keyBy('order_id');

        $admin = $rows($this->userWith('view order-sessions', 'cancel orders'));

        $this->assertSame(route('orders.cancel', $pending->order_id), $admin[$pending->order_id]['cancel_url']);
        $this->assertNull($admin[$verified->order_id]['cancel_url']);
        $this->assertNull($admin[$completed->order_id]['cancel_url']);
        $this->assertNull($admin[$cancelled->order_id]['cancel_url']);

        $this->assertNull($rows($this->userWith('view order-sessions'))[$pending->order_id]['cancel_url']);
    }

    public function test_the_order_details_have_the_cancel_button_only_for_those_who_may_cancel(): void
    {
        $session = OrderSession::factory()->create();
        $url = route('order-sessions.show', $session->session_id);

        $this->actingAs($this->userWith('view order-sessions', 'cancel orders'))
            ->get($url)
            ->assertSee('id="order-details-cancel"', false);

        $this->actingAs($this->userWith('view order-sessions'))
            ->get($url)
            ->assertDontSee('id="order-details-cancel"', false);
    }
}
