<?php

namespace Tests\Feature;

use App\Models\PreOrder;
use App\Models\PreOrderItem;
use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\SessionDish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// My Orders / My Pre-Orders: ten a page, newest first, a card that opens the order popup, and
// "?order=ID" (what a notification links to) that finds the page an order is on.
class MyOrdersPopupAndPagingTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    private function preOrderCustomer(): User
    {
        return $this->userWith('view pre-order-menu', 'create pre-orders', 'view my pre-orders');
    }

    /**
     * @return array<int, Order> oldest first (ids ascending)
     */
    private function orders(User $user, int $count): array
    {
        $session = OrderSession::factory()->create();
        $orders = [];

        for ($number = 1; $number <= $count; $number++) {
            $orders[] = Order::factory()->create([
                'user_id' => $user->id,
                'session_id' => $session->session_id,
                'order_number' => sprintf('ORD-T-%03d', $number),
            ]);
        }

        return $orders;
    }

    /**
     * @return array<int, PreOrder> oldest first (ids ascending)
     */
    private function preOrders(User $user, int $count): array
    {
        $session = PreOrderSession::factory()->create(['title' => 'Office lunch']);
        $orders = [];

        for ($number = 1; $number <= $count; $number++) {
            $orders[] = PreOrder::factory()->create([
                'user_id' => $user->id,
                'pre_order_session_id' => $session->pre_order_session_id,
                'order_number' => sprintf('PRE-T-%03d', $number),
            ]);
        }

        return $orders;
    }

    // The JSON each card carries for the popup: [order_number => decoded data-order].
    private function cards(string $html): array
    {
        preg_match_all('/<article id="(?:pre-)?order-\d+" data-order="([^"]*)"/', $html, $found);

        $cards = [];

        foreach ($found[1] as $json) {
            $data = json_decode(html_entity_decode($json), true);
            $cards[$data['order_number']] = $data;
        }

        return $cards;
    }

    /* -------- pagination: My Orders -------- */

    public function test_my_orders_is_ten_a_page_newest_first(): void
    {
        $customer = $this->customer();
        $this->orders($customer, 25);

        $this->actingAs($customer)->get(route('my-orders.index'))
            ->assertOk()
            ->assertSee('Showing 1–10 of 25')
            ->assertSee('ORD-T-025')->assertSee('ORD-T-016')
            ->assertDontSee('ORD-T-015')
            ->assertSee('aria-label="Go to page 3"', false);

        $this->actingAs($customer)->get(route('my-orders.index', ['page' => 3]))
            ->assertSee('Showing 21–25 of 25')
            ->assertSee('ORD-T-005')->assertSee('ORD-T-001')
            ->assertDontSee('ORD-T-006');
    }

    public function test_a_short_list_has_no_pager(): void
    {
        $customer = $this->customer();
        $this->orders($customer, 4);

        $this->actingAs($customer)->get(route('my-orders.index'))
            ->assertOk()
            ->assertSee('ORD-T-004')
            ->assertDontSee('Page navigation');
    }

    public function test_only_my_own_orders_are_listed(): void
    {
        $customer = $this->customer();
        $this->orders($customer, 2);
        Order::factory()->create(['user_id' => $this->customer()->id, 'order_number' => 'ORD-NOT-MINE']);

        $this->actingAs($customer)->get(route('my-orders.index'))
            ->assertOk()->assertSee('ORD-T-002')->assertDontSee('ORD-NOT-MINE');
    }

    public function test_a_page_past_the_end_lands_on_the_last_one(): void
    {
        $customer = $this->customer();
        $this->orders($customer, 25);

        $this->actingAs($customer)->get(route('my-orders.index', ['page' => 99]))
            ->assertRedirect(route('my-orders.index', ['page' => 3]));

        // Nothing ordered yet: the (empty) first page, not an error.
        $this->actingAs($this->customer())->followingRedirects()->get(route('my-orders.index', ['page' => 9]))
            ->assertOk()->assertSee('No orders yet');
    }

    public function test_the_order_parameter_finds_the_page_that_holds_the_order(): void
    {
        $customer = $this->customer();
        $orders = $this->orders($customer, 25);
        $oldest = $orders[0];
        $middle = $orders[14];   // 11 newer ones: page 2
        $newest = $orders[24];

        $this->actingAs($customer);

        $this->get(route('my-orders.index', ['order' => $oldest->order_id]))
            ->assertRedirect(route('my-orders.index', ['page' => 3]) . '#order-' . $oldest->order_id);

        $this->get(route('my-orders.index', ['order' => $middle->order_id]))
            ->assertRedirect(route('my-orders.index', ['page' => 2]) . '#order-' . $middle->order_id);

        // On the first page: no page parameter, just the anchor.
        $this->get(route('my-orders.index', ['order' => $newest->order_id]))
            ->assertRedirect(route('my-orders.index') . '#order-' . $newest->order_id);

        // The page it lands on really holds the card.
        $this->followingRedirects()->get(route('my-orders.index', ['order' => $oldest->order_id]))
            ->assertOk()->assertSee('id="order-' . $oldest->order_id . '"', false);
    }

    public function test_the_order_parameter_ignores_what_is_not_mine_or_not_an_order(): void
    {
        $customer = $this->customer();
        $this->orders($customer, 3);
        $stranger = Order::factory()->create(['user_id' => $this->customer()->id, 'order_number' => 'ORD-STRANGER']);

        $this->actingAs($customer);

        // Someone else's order is neither revealed nor followed: the list just opens.
        $this->get(route('my-orders.index', ['order' => $stranger->order_id]))->assertOk()->assertDontSee('ORD-STRANGER');
        $this->get(route('my-orders.index', ['order' => 999999]))->assertOk()->assertSee('ORD-T-003');
        $this->get(route('my-orders.index', ['order' => 'abc']))->assertOk()->assertSee('ORD-T-003');
        $this->get(route('my-orders.index', ['order' => '']))->assertOk()->assertSee('ORD-T-003');
    }

    /* -------- the order popup: My Orders -------- */

    public function test_every_card_carries_its_order_for_the_popup_and_a_details_button(): void
    {
        $customer = $this->customer();
        [$order] = $this->orders($customer, 1);
        $line = SessionDish::factory()->create([
            'session_id' => $order->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Beef Caldereta'])->dish_id,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->order_id, 'session_dish_id' => $line->session_dish_id,
            'quantity' => 3, 'unit_price' => 120, 'sub_total' => 360,
        ]);

        $page = $this->actingAs($customer)->get(route('my-orders.index'))
            ->assertOk()
            ->assertSee('data-order-open', false)
            ->assertSee('Details of order ORD-T-001')
            // The popup itself is on the page, and its script.
            ->assertSee('id="order-details-number"', false);

        $data = $this->cards($page->getContent())['ORD-T-001'];

        $this->assertSame('Beef Caldereta', $data['items'][0]['dish_name']);
        $this->assertSame(3, $data['items'][0]['quantity']);
        $this->assertEquals(120, $data['items'][0]['unit_price']);
        $this->assertEquals(360, $data['items'][0]['sub_total']);
        $this->assertArrayHasKey('session_label', $data);
        $this->assertArrayHasKey('status_badge', $data);
        $this->assertArrayHasKey('payment_badge', $data);
    }

    public function test_the_popup_data_has_no_staff_fields_and_no_action_urls_even_for_a_staff_member(): void
    {
        // Someone who is staff AND orders for themselves: the popup must stay read only.
        $staff = $this->userWith('view my orders', 'complete orders', 'cancel orders', 'verify payments', 'reject payments', 'view order-sessions');
        $this->orders($staff, 1);

        $data = $this->cards($this->actingAs($staff)->get(route('my-orders.index'))->assertOk()->getContent())['ORD-T-001'];

        foreach (['customer_name', 'verified_by_name', 'verified_at', 'session_url', 'complete_url', 'cancel_url', 'verify_url', 'reject_url', 'order_status', 'payment_state', 'is_cash', 'user_id', 'order_id'] as $key) {
            $this->assertArrayNotHasKey($key, $data, $key);
        }
    }

    public function test_the_popup_marks_the_staff_only_rows_so_a_customer_does_not_see_them(): void
    {
        $this->actingAs($this->customer())->get(route('my-orders.index'))
            ->assertOk()
            // Customer, Verified By and the discount's ID picture carry the marker the script hides when the
            // data has no such key; the discount's "why it was rejected" row hides when there is no reason.
            ->assertSee('data-popup-field', false);

        $this->assertSame(4, substr_count(file_get_contents(resource_path('views/components/ui/order-popup.blade.php')), 'data-popup-field>'));
    }

    /* -------- My Pre-Orders -------- */

    public function test_my_pre_orders_is_ten_a_page_newest_first_and_finds_an_order(): void
    {
        $customer = $this->preOrderCustomer();
        $orders = $this->preOrders($customer, 25);

        $this->actingAs($customer)->get(route('my-pre-orders.index'))
            ->assertOk()
            ->assertSee('Showing 1–10 of 25')
            ->assertSee('PRE-T-025')->assertSee('PRE-T-016')->assertDontSee('PRE-T-015');

        $this->get(route('my-pre-orders.index', ['page' => 3]))
            ->assertSee('Showing 21–25 of 25')->assertSee('PRE-T-001');

        $oldest = $orders[0];
        $this->get(route('my-pre-orders.index', ['order' => $oldest->pre_order_id]))
            ->assertRedirect(route('my-pre-orders.index', ['page' => 3]) . '#pre-order-' . $oldest->pre_order_id);

        $this->get(route('my-pre-orders.index', ['page' => 99]))
            ->assertRedirect(route('my-pre-orders.index', ['page' => 3]));
    }

    public function test_a_pre_order_card_carries_its_order_for_the_popup(): void
    {
        $customer = $this->preOrderCustomer();
        [$order] = $this->preOrders($customer, 1);
        PreOrderItem::factory()->create([
            'pre_order_id' => $order->pre_order_id,
            'pre_order_session_dish_id' => PreOrderSessionDish::factory()->create([
                'dish_id' => Dish::factory()->create(['dish_name' => 'Pancit Canton'])->dish_id,
            ])->pre_order_session_dish_id,
            'quantity' => 20, 'unit_price' => 90, 'sub_total' => 1800,
        ]);

        $page = $this->actingAs($customer)->get(route('my-pre-orders.index'))
            ->assertOk()
            ->assertSee('data-order-open', false)
            ->assertSee('id="order-details-number"', false)
            ->assertSee('my-pre-orders');

        $data = $this->cards($page->getContent())['PRE-T-001'];

        $this->assertStringContainsString('Office lunch', $data['session_label']);
        $this->assertSame('Pancit Canton', $data['items'][0]['dish_name']);
        $this->assertSame(20, $data['items'][0]['quantity']);
        $this->assertEquals(1800, $data['items'][0]['sub_total']);
        $this->assertArrayNotHasKey('customer_name', $data);
        $this->assertArrayNotHasKey('complete_url', $data);
    }

    public function test_the_order_pages_still_need_their_permission(): void
    {
        $this->actingAs($this->userWith('view menu'))->get(route('my-orders.index', ['order' => 1]))->assertForbidden();
        $this->actingAs($this->userWith('view menu'))->get(route('my-pre-orders.index', ['order' => 1]))->assertForbidden();
    }
}
