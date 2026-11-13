<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The Orders page: every order of every session, with the same order popup (and the
// same actions) as the order session page.
class OrdersPageTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    private function order(string $method, string $paymentStatus = 'pending', ?OrderSession $session = null): Order
    {
        $order = Order::factory()->create([
            'session_id' => ($session ?? OrderSession::factory()->create())->session_id,
            'order_status' => 'pending',
            'payment_status' => $paymentStatus,
        ]);

        Payment::factory()->create([
            'order_id' => $order->order_id,
            'payment_method_id' => PaymentMethod::factory()->create(['method_name' => $method])->method_id,
            'payment_status' => $paymentStatus,
        ]);

        return $order;
    }

    public function test_the_page_is_for_those_who_may_view_orders(): void
    {
        $this->actingAs($this->userWith('view orders'))->get(route('orders.index'))->assertOk()
            ->assertSee('id="orders-table"', false)
            ->assertSee('id="orders-cards"', false)
            ->assertSee('data-view-mode="card"', false)
            ->assertSee('id="order-details-number"', false)
            ->assertSee('id="orders-session-filter"', false);

        // Not even the permission to see sessions or payments opens it.
        $this->actingAs($this->userWith('view order-sessions', 'view payments'))->get(route('orders.index'))->assertForbidden();
    }

    public function test_the_feed_lists_orders_of_every_session_with_their_session_and_customer(): void
    {
        $first = OrderSession::factory()->create();
        $second = OrderSession::factory()->create();
        $a = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', $first);
        $b = $this->order('GCash', 'pending', $second);
        $a->delete();
        $a = $this->order(PaymentMethod::CASH_ON_PICKUP, 'pending', $first);

        $rows = collect($this->actingAs($this->superAdmin())->getJson(route('orders.index'), $this->ajax())
            ->assertOk()->json('data'));

        // Both sessions, and the soft deleted order is not listed.
        $this->assertEqualsCanonicalizing([$a->order_id, $b->order_id], $rows->pluck('order_id')->all());

        $row = $rows->firstWhere('order_id', $b->order_id);

        $this->assertSame($second->session_id, $row['session_id']);
        $this->assertNotEmpty($row['session_label']);
        $this->assertSame(route('order-sessions.show', $second->session_id), $row['session_url']);
        $this->assertSame(User::find($b->user_id)->name, $row['customer_name']);
        $this->assertSame('GCash', $row['method_name']);
        $this->assertStringContainsString('data-status="pending"', $row['status_badge']);
    }

    public function test_each_row_offers_only_the_actions_the_user_may_take(): void
    {
        $cash = $this->order(PaymentMethod::CASH_ON_PICKUP);
        $bank = $this->order('GCash');

        $rowsOf = fn (User $user) => collect($this->actingAs($user)->getJson(route('orders.index'), $this->ajax())->json('data'))->keyBy('order_id');

        // An admin may complete (cash settles on completion), verify / reject (bank) and cancel.
        $admin = $rowsOf($this->superAdmin());

        $this->assertSame(route('orders.complete', $cash->order_id), $admin[$cash->order_id]['complete_url']);
        $this->assertSame(route('orders.cancel', $cash->order_id), $admin[$cash->order_id]['cancel_url']);
        $this->assertNull($admin[$cash->order_id]['verify_url']);
        $this->assertSame(route('payments.verify', $bank->order_id), $admin[$bank->order_id]['verify_url']);
        $this->assertSame(route('payments.reject', $bank->order_id), $admin[$bank->order_id]['reject_url']);
        // A bank order cannot be completed before its payment is verified.
        $this->assertNull($admin[$bank->order_id]['complete_url']);

        // Someone who may only look sees no action at all.
        $viewer = $rowsOf($this->userWith('view orders'));

        foreach ([$cash, $bank] as $order) {
            foreach (['complete_url', 'cancel_url', 'verify_url', 'reject_url'] as $key) {
                $this->assertNull($viewer[$order->order_id][$key], $key);
            }
        }
    }

    public function test_an_order_is_completed_from_the_orders_page_url(): void
    {
        $order = $this->order(PaymentMethod::CASH_ON_PICKUP);

        $url = collect($this->actingAs($this->superAdmin())->getJson(route('orders.index'), $this->ajax())->json('data'))
            ->firstWhere('order_id', $order->order_id)['complete_url'];

        $this->postJson($url)->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'completed']);
    }

    public function test_the_session_page_and_the_orders_page_share_the_popup(): void
    {
        $session = OrderSession::factory()->create();

        $this->actingAs($this->superAdmin())->get(route('order-sessions.show', $session->session_id))
            ->assertOk()
            ->assertSee('id="order-popup-copy"', false)
            ->assertSee('id="order-details-confirm"', false);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('id="order-popup-copy"', false)
            ->assertSee('id="order-details-confirm"', false)
            ->assertSee('id="order-details-session"', false);
    }

    public function test_the_sidebar_and_dashboard_link_to_orders_only_with_the_permission(): void
    {
        $this->actingAs($this->userWith('view orders'))->get(route('dashboard'))
            ->assertOk()->assertSee(route('orders.index'), false);

        $this->actingAs($this->userWith('view payments'))->get(route('dashboard'))
            ->assertOk()->assertDontSee(route('orders.index'), false);
    }
}
