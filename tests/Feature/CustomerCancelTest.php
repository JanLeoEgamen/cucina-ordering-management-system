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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// A customer cancels their OWN pending order, but only while System Configuration allows it
// (Setting::customerCancel(), off by default). Same stock release as staff's Cancel.
class CustomerCancelTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    /**
     * A pending order of 3 plates (7 left, 3 reserved) with a payment in the given state.
     *
     * @return array{0: Order, 1: SessionDish}
     */
    private function order(User $customer, string $paymentStatus = 'pending', string $orderStatus = 'pending', string $method = 'GCash'): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create(['session_id' => $session->session_id, 'available_stock' => 7, 'reserved_stock' => 3]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
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

    public function test_it_is_off_by_default_and_the_customer_is_refused(): void
    {
        $this->assertFalse(Setting::customerCancel());

        $customer = $this->customer();
        [$order, $line] = $this->order($customer);

        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))
            ->assertForbidden()
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 7, 'reserved_stock' => 3]);
    }

    public function test_when_allowed_the_customer_cancels_and_the_stock_goes_back(): void
    {
        Setting::setCustomerCancel(true);
        $customer = $this->customer();
        [$order, $line] = $this->order($customer);

        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'cancelled', 'payment_status' => 'rejected']);
        // The pending payment is rejected, and nobody is stamped as reviewer: the customer is not staff.
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->order_id,
            'payment_status' => 'rejected',
            'verified_by' => null,
            'rejection_reason' => 'The customer cancelled the order.',
        ]);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 10, 'reserved_stock' => 0]);
    }

    public function test_a_cash_order_can_be_cancelled_by_its_customer(): void
    {
        Setting::setCustomerCancel(true);
        $customer = $this->customer();
        [$order, $line] = $this->order($customer, method: PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'cancelled']);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 10]);
    }

    public function test_a_rejected_payment_does_not_stop_the_customer_cancelling(): void
    {
        Setting::setCustomerCancel(true);
        $customer = $this->customer();
        [$order, $line] = $this->order($customer, 'rejected');

        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'cancelled']);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 10, 'reserved_stock' => 0]);
    }

    public function test_a_verified_payment_is_for_staff_to_undo_first(): void
    {
        Setting::setCustomerCancel(true);
        $customer = $this->customer();
        [$order, $line] = $this->order($customer, 'verified');

        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 7, 'reserved_stock' => 3]);
    }

    public function test_only_a_pending_order_can_be_cancelled_and_never_twice(): void
    {
        Setting::setCustomerCancel(true);
        $customer = $this->customer();

        foreach (['completed', 'cancelled'] as $state) {
            [$order, $line] = $this->order($customer, 'pending', $state);

            $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))->assertStatus(422);
            $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 7, 'reserved_stock' => 3]);
        }

        // A double tap gives the plates back once.
        [$order, $line] = $this->order($customer);
        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))->assertOk();
        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))->assertStatus(422);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 10, 'reserved_stock' => 0]);
    }

    public function test_someone_elses_order_is_not_found(): void
    {
        Setting::setCustomerCancel(true);
        [$order, $line] = $this->order($this->customer());

        $this->actingAs($this->customer())->postJson(route('my-orders.cancel', $order->order_id))->assertNotFound();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);
        $this->assertDatabaseHas('session_dishes', ['session_dish_id' => $line->session_dish_id, 'available_stock' => 7]);
    }

    public function test_without_the_ordering_permission_it_is_forbidden(): void
    {
        Setting::setCustomerCancel(true);
        $viewer = $this->userWith('view my orders');
        [$order] = $this->order($viewer);

        $this->actingAs($viewer)->postJson(route('my-orders.cancel', $order->order_id))->assertForbidden();
        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->postJson(route('my-orders.cancel', 1))->assertUnauthorized();
    }

    public function test_switching_it_off_stops_the_very_next_cancel(): void
    {
        Setting::setCustomerCancel(true);
        $customer = $this->customer();
        [$first] = $this->order($customer);
        [$second] = $this->order($customer);

        $this->actingAs($customer)->postJson(route('my-orders.cancel', $first->order_id))->assertOk();

        Setting::setCustomerCancel(false);
        $this->actingAs($customer)->postJson(route('my-orders.cancel', $second->order_id))->assertForbidden();
        $this->assertDatabaseHas('orders', ['order_id' => $second->order_id, 'order_status' => 'pending']);
    }

    public function test_staff_can_still_cancel_whatever_the_switch(): void
    {
        $customer = $this->customer();
        [$order] = $this->order($customer);

        $this->actingAs($this->superAdmin())->postJson(route('orders.cancel', $order->order_id))->assertOk();
        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'cancelled']);
    }

    public function test_my_orders_shows_the_cancel_button_only_when_it_applies(): void
    {
        $customer = $this->customer();
        [$pending] = $this->order($customer);
        [$verified] = $this->order($customer, 'verified');
        [$done] = $this->order($customer, 'pending', 'completed');

        // Off: no button anywhere.
        $this->actingAs($customer)->get(route('my-orders.index'))
            ->assertOk()
            ->assertDontSee('data-cancel-order', false);

        Setting::setCustomerCancel(true);

        $page = $this->actingAs($customer)->get(route('my-orders.index'))->assertOk();
        $page->assertSee(route('my-orders.cancel', $pending->order_id), false);
        $page->assertDontSee(route('my-orders.cancel', $verified->order_id), false);
        $page->assertDontSee(route('my-orders.cancel', $done->order_id), false);
    }

    public function test_the_customer_and_the_watchers_are_told_in_the_right_words(): void
    {
        Setting::setCustomerCancel(true);
        $watcher = $this->userWith('view orders');
        Setting::setNotificationWatchers([$watcher->id]);
        $customer = $this->customer();
        [$order] = $this->order($customer);

        $this->actingAs($customer)->postJson(route('my-orders.cancel', $order->order_id))->assertOk();

        $mine = DB::table('user_notifications')->where('user_id', $customer->id)->where('type', 'order_cancelled')->first();
        $this->assertNotNull($mine);
        $this->assertStringContainsString('You cancelled your order', $mine->body);

        $theirs = DB::table('user_notifications')->where('user_id', $watcher->id)->where('type', 'order_cancelled')->first();
        $this->assertNotNull($theirs);
        $this->assertStringContainsString('cancelled their order', $theirs->body);
    }

    public function test_the_audit_trail_names_the_action(): void
    {
        $this->assertSame('Cancelled their own order', config('audit.actions')['my-orders.cancel']);
    }
}
