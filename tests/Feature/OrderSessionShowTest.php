<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSessionShowTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    public function test_guests_are_sent_to_login(): void
    {
        $session = OrderSession::factory()->create();

        $this->get(route('order-sessions.show', $session->session_id))->assertRedirect(route('login'));
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $session = OrderSession::factory()->create();

        $this->actingAs($this->userWith('create order-sessions'))
            ->get(route('order-sessions.show', $session->session_id))
            ->assertForbidden();
    }

    public function test_the_page_shows_the_session_and_its_orders_table(): void
    {
        $session = OrderSession::factory()->create();
        $dish = Dish::factory()->create(['dish_name' => 'Chicken Adobo']);
        SessionDish::factory()->create(['session_id' => $session->session_id, 'dish_id' => $dish->dish_id, 'available_stock' => 7]);

        $this->actingAs($this->userWith('view order-sessions'))
            ->get(route('order-sessions.show', $session->session_id))
            ->assertOk()
            ->assertSee('id="session-orders-table"', false)
            ->assertSee('id="session-orders-cards"', false)
            ->assertSee('Actions')
            ->assertSee('id="order-details-items"', false)
            ->assertSee('Chicken Adobo')
            ->assertSee('aria-controls="session-dishes-panel"', false);
    }

    public function test_the_order_details_have_verify_and_reject_only_for_those_who_may_decide(): void
    {
        $session = OrderSession::factory()->create();
        $url = route('order-sessions.show', $session->session_id);

        $this->actingAs($this->userWith('view order-sessions', 'verify payments', 'reject payments'))
            ->get($url)
            ->assertOk()
            ->assertSee('id="order-details-verify"', false)
            ->assertSee('id="order-details-reject"', false)
            ->assertSee('id="order-reject-reason"', false);

        // Each button follows its own permission; the popup is gone for good.
        $this->actingAs($this->userWith('view order-sessions', 'verify payments'))
            ->get($url)
            ->assertSee('id="order-details-verify"', false)
            ->assertDontSee('id="order-details-reject"', false);

        $this->actingAs($this->userWith('view order-sessions'))
            ->get($url)
            ->assertOk()
            ->assertDontSee('id="order-details-verify"', false)
            ->assertDontSee('id="order-details-reject"', false)
            ->assertDontSee('order-details-payment-button', false)
            ->assertDontSee('view-payment-modal', false);
    }

    public function test_a_pending_orders_row_carries_the_decision_urls(): void
    {
        $session = OrderSession::factory()->create();
        $pending = Order::factory()->create(['session_id' => $session->session_id]);
        $decided = Order::factory()->create(['session_id' => $session->session_id, 'payment_status' => 'verified']);

        Payment::factory()->create(['order_id' => $pending->order_id, 'payment_status' => 'pending']);
        Payment::factory()->create(['order_id' => $decided->order_id, 'payment_status' => 'verified']);

        $rows = fn ($user) => collect(
            $this->actingAs($user)
                ->getJson(route('order-sessions.show', $session->session_id), $this->ajax())
                ->assertOk()
                ->json('data')
        )->keyBy('order_id');

        $admin = $rows($this->userWith('view order-sessions', 'verify payments', 'reject payments'));

        $this->assertSame(route('payments.verify', $pending->order_id), $admin[$pending->order_id]['verify_url']);
        $this->assertSame(route('payments.reject', $pending->order_id), $admin[$pending->order_id]['reject_url']);
        // A payment that is already decided has nothing to decide here.
        $this->assertNull($admin[$decided->order_id]['verify_url']);
        $this->assertNull($admin[$decided->order_id]['reject_url']);

        // Without the permissions the URLs are not handed out.
        $viewer = $rows($this->userWith('view order-sessions'));

        $this->assertNull($viewer[$pending->order_id]['verify_url']);
        $this->assertNull($viewer[$pending->order_id]['reject_url']);
    }

    public function test_deciding_from_an_order_goes_through_the_payment_routes(): void
    {
        $session = OrderSession::factory()->create();
        $order = Order::factory()->create(['session_id' => $session->session_id]);
        Payment::factory()->create(['order_id' => $order->order_id, 'payment_status' => 'pending']);

        $this->actingAs($this->userWith('verify payments'))
            ->postJson(route('payments.verify', $order->order_id))
            ->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'payment_status' => 'verified']);
    }
    public function test_an_unknown_session_is_a_404_for_the_page_and_the_feed(): void
    {
        $user = $this->userWith('view order-sessions');

        $this->actingAs($user)->get(route('order-sessions.show', 9999))->assertNotFound();
        $this->actingAs($user)->getJson(route('order-sessions.show', 9999), $this->ajax())->assertNotFound();
    }

    public function test_the_feed_lists_only_the_orders_of_this_session(): void
    {
        $session = OrderSession::factory()->create();
        $other = OrderSession::factory()->create();
        $line = SessionDish::factory()->create(['session_id' => $session->session_id]);

        $mine = Order::factory()->create(['session_id' => $session->session_id, 'order_number' => 'ORD-MINE-0001']);
        Order::factory()->create(['session_id' => $other->session_id, 'order_number' => 'ORD-OTHER-0001']);
        OrderItem::factory()->create(['order_id' => $mine->order_id, 'session_dish_id' => $line->session_dish_id, 'quantity' => 3]);

        $response = $this->actingAs($this->userWith('view order-sessions'))
            ->getJson(route('order-sessions.show', $session->session_id), $this->ajax())
            ->assertOk();

        $rows = $response->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('ORD-MINE-0001', $rows[0]['order_number']);
        $this->assertSame($mine->order_id, $rows[0]['order_id']);
        $this->assertSame(3, $rows[0]['items_count']);
        $this->assertStringContainsString('data-status="pending"', $rows[0]['status_badge']);
        $this->assertStringContainsString('data-status="pending"', $rows[0]['payment_badge']);
    }

    public function test_the_list_links_to_the_page_instead_of_a_drawer(): void
    {
        $session = OrderSession::factory()->create();

        $response = $this->actingAs($this->userWith('view order-sessions'))
            ->getJson(route('order-sessions.index'), $this->ajax())
            ->assertOk();

        $this->assertStringContainsString(route('order-sessions.show', $session->session_id), $response->json('data.0.action'));
        $this->assertStringNotContainsString('data-row-action="view"', $response->json('data.0.action'));
    }
}
