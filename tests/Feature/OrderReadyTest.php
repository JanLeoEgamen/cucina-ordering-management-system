<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// "Ready for pick up": staff tell a customer (one order, or every waiting order of a session)
// that the food can be collected. The order stays pending; orders.ready_at is the flag. The
// test queue is `sync`, so the notification job runs inside the request.
class OrderReadyTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $method, string $paymentStatus = 'pending', string $orderStatus = 'pending', ?OrderSession $session = null, ?User $customer = null): Order
    {
        $order = Order::factory()->create([
            'session_id' => ($session ?? OrderSession::factory()->create())->session_id,
            'user_id' => ($customer ?? User::factory()->create())->id,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
        ]);

        Payment::factory()->create([
            'order_id' => $order->order_id,
            'payment_method_id' => DB::table('payment_methods')->where('method_name', $method)->value('method_id')
                ?? PaymentMethod::factory()->create(['method_name' => $method])->method_id,
            'payment_status' => $paymentStatus,
        ]);

        return $order;
    }

    private function rows(User $user, string $type = OrderNotifier::READY)
    {
        return DB::table('user_notifications')->where('user_id', $user->id)->where('type', $type)->get();
    }

    public function test_announcing_a_cash_order_tells_the_customer_and_keeps_it_pending(): void
    {
        $customer = $this->userWith('view my orders');
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'pending', null, $customer);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.ready', $order->order_id))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending']);
        $this->assertNotNull(Order::readyAtOf($order->order_id));

        $row = $this->rows($customer)->first();

        $this->assertStringContainsString('ready for pick up', $row->title);
        $this->assertStringContainsString('cash', $row->body);
        $this->assertStringContainsString('order=' . $order->order_id, $row->url);
    }

    public function test_the_watchers_are_told_except_the_one_who_announced(): void
    {
        $customer = $this->userWith('view my orders');
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'pending', null, $customer);
        $staff = $this->superAdmin();
        $watcher = $this->userWith('view orders');
        Setting::setNotificationWatchers([$staff->id, $watcher->id]);

        $this->actingAs($staff)->postJson(route('orders.ready', $order->order_id))->assertOk();

        $this->assertCount(1, $this->rows($watcher));
        $this->assertCount(0, $this->rows($staff));
        $this->assertCount(1, $this->rows($customer));
    }

    public function test_a_bank_order_needs_its_payment_verified_first(): void
    {
        $admin = $this->superAdmin();
        $order = $this->order('GCash');

        $this->actingAs($admin)->postJson(route('orders.ready', $order->order_id))->assertStatus(422);
        $this->assertNull(Order::readyAtOf($order->order_id));
        $this->assertSame(0, DB::table('user_notifications')->count());

        $this->actingAs($admin)->postJson(route('payments.verify', $order->order_id))->assertOk();
        $this->actingAs($admin)->postJson(route('orders.ready', $order->order_id))->assertOk();

        $this->assertNotNull(Order::readyAtOf($order->order_id));
    }

    public function test_a_rejected_payment_cannot_be_announced(): void
    {
        $order = $this->order('GCash', 'rejected');

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.ready', $order->order_id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The payment of this order was rejected.');
    }

    public function test_an_order_is_announced_once_and_only_while_pending(): void
    {
        $admin = $this->superAdmin();
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($admin)->postJson(route('orders.ready', $order->order_id))->assertOk();
        $this->actingAs($admin)->postJson(route('orders.ready', $order->order_id))->assertStatus(422);
        $this->assertCount(1, DB::table('user_notifications')->where('type', OrderNotifier::READY)->get());

        foreach (['completed', 'cancelled'] as $status) {
            $other = $this->order(PaymentMethod::CASH_ON_PICKUP, 'verified', $status);

            $this->actingAs($admin)->postJson(route('orders.ready', $other->order_id))->assertStatus(422);
        }

        $this->actingAs($admin)->postJson(route('orders.ready', 9999))->assertNotFound();
    }

    public function test_announcing_an_order_needs_the_complete_permission(): void
    {
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $this->postJson(route('orders.ready', $order->order_id))->assertUnauthorized();
        $this->actingAs($this->userWith('view orders', 'cancel orders'))
            ->postJson(route('orders.ready', $order->order_id))
            ->assertForbidden();
        $this->actingAs($this->userWith('complete orders'))
            ->postJson(route('orders.ready', $order->order_id))
            ->assertOk();
    }

    public function test_a_session_announcement_tells_only_the_orders_that_qualify(): void
    {
        $session = OrderSession::factory()->create();
        $other = OrderSession::factory()->create();
        $first = $this->userWith('view my orders');
        $second = $this->userWith('view my orders');
        $third = $this->userWith('view my orders');

        $cash = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'pending', $session, $first);
        $verified = $this->order('GCash', 'verified', 'pending', $session, $second);
        $unverified = $this->order('GCash', 'pending', 'pending', $session, $third);
        $cancelled = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'cancelled', $session, $third);
        $elsewhere = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'pending', $other, $third);

        $watcher = $this->userWith('view orders');
        Setting::setNotificationWatchers([$watcher->id]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('order-sessions.announce-ready', $session->session_id))
            ->assertOk()
            ->assertJsonPath('message', '2 customers were told their orders are ready for pick up.');

        $this->assertNotNull(Order::readyAtOf($cash->order_id));
        $this->assertNotNull(Order::readyAtOf($verified->order_id));
        $this->assertNull(Order::readyAtOf($unverified->order_id));
        $this->assertNull(Order::readyAtOf($cancelled->order_id));
        $this->assertNull(Order::readyAtOf($elsewhere->order_id));

        $this->assertCount(1, $this->rows($first));
        $this->assertCount(1, $this->rows($second));
        $this->assertCount(0, $this->rows($third));

        // One line for the whole announcement, not one per order.
        $summary = $this->rows($watcher);

        $this->assertCount(1, $summary);
        $this->assertStringStartsWith('2 orders are ready', $summary->first()->title);

        // Pressing it again finds nothing left to announce.
        $this->actingAs($this->superAdmin())
            ->postJson(route('order-sessions.announce-ready', $session->session_id))
            ->assertStatus(422);
        $this->assertCount(1, $this->rows($first));
    }

    public function test_a_session_announcement_needs_the_complete_permission_and_a_real_session(): void
    {
        $session = OrderSession::factory()->create();

        $this->actingAs($this->userWith('view order-sessions'))
            ->postJson(route('order-sessions.announce-ready', $session->session_id))
            ->assertForbidden();

        $this->actingAs($this->userWith('complete orders'))
            ->postJson(route('order-sessions.announce-ready', 9999))
            ->assertNotFound();
    }

    public function test_the_customer_and_staff_see_the_ready_badge(): void
    {
        $customer = $this->userWith('view my orders');
        $session = OrderSession::factory()->create();
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'pending', $session, $customer);

        $admin = $this->superAdmin();

        $this->actingAs($customer)->get(route('my-orders.index'))->assertOk()->assertDontSee('Ready for pick up');

        $this->actingAs($admin)->postJson(route('orders.ready', $order->order_id))->assertOk();

        $this->actingAs($customer)->get(route('my-orders.index'))->assertOk()->assertSee('Ready for pick up');

        // Staff: the feed row carries both badges, and no announce URL any more.
        $row = $this->actingAs($admin)
            ->getJson(route('order-sessions.show', $session->session_id), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data.0');

        $this->assertStringContainsString('data-status="pending"', $row['status_badge']);
        $this->assertStringContainsString('data-status="ready"', $row['status_badge']);
        $this->assertNull($row['ready_url']);
    }

    public function test_the_session_page_shows_the_button_with_the_waiting_count(): void
    {
        $session = OrderSession::factory()->create();
        $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', 'pending', $session);
        $this->order('GCash', 'pending', 'pending', $session);

        $this->actingAs($this->superAdmin())
            ->get(route('order-sessions.show', $session->session_id))
            ->assertOk()
            ->assertSee('id="announce-ready"', false)
            ->assertSee(route('order-sessions.announce-ready', $session->session_id), false)
            ->assertSee('customers of 1 waiting order', false);

        $this->actingAs($this->userWith('view order-sessions'))
            ->get(route('order-sessions.show', $session->session_id))
            ->assertOk()
            ->assertDontSee('id="announce-ready"', false);
    }
}
