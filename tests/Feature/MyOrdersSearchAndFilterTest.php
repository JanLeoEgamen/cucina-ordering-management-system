<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\SessionDish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// My Orders search + filters: a GET form (q, status, payment, from, to) that narrows the
// customer's OWN list and keeps working across the pages.
class MyOrdersSearchAndFilterTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    private function order(User $user, string $number, array $attributes = []): Order
    {
        $session = OrderSession::factory()->create();

        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'session_id' => $session->session_id,
            'order_number' => $number,
        ], $attributes));
    }

    public function test_search_finds_an_order_by_its_number(): void
    {
        $customer = $this->customer();
        $this->order($customer, 'ORD-ALPHA-1');
        $this->order($customer, 'ORD-BETA-2');

        $this->actingAs($customer)->get(route('my-orders.index', ['q' => 'alpha']))
            ->assertOk()->assertSee('ORD-ALPHA-1')->assertDontSee('ORD-BETA-2');
    }

    public function test_search_finds_an_order_by_a_dish_it_contains(): void
    {
        $customer = $this->customer();
        $withDish = $this->order($customer, 'ORD-HAS-DISH');
        $this->order($customer, 'ORD-NO-DISH');

        $line = SessionDish::factory()->create([
            'session_id' => $withDish->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Sizzling Sisig'])->dish_id,
        ]);
        OrderItem::factory()->create(['order_id' => $withDish->order_id, 'session_dish_id' => $line->session_dish_id]);

        $this->actingAs($customer)->get(route('my-orders.index', ['q' => 'sisig']))
            ->assertOk()->assertSee('ORD-HAS-DISH')->assertDontSee('ORD-NO-DISH');
    }

    public function test_wildcards_typed_in_the_search_are_plain_characters(): void
    {
        $customer = $this->customer();
        $this->order($customer, 'ORD-ONE');

        // "%" must not match everything.
        $this->actingAs($customer)->get(route('my-orders.index', ['q' => '%']))
            ->assertOk()->assertDontSee('ORD-ONE')->assertSee('No orders match');
    }

    public function test_the_status_filters_narrow_the_list(): void
    {
        $customer = $this->customer();
        $this->order($customer, 'ORD-WAITING');
        $this->order($customer, 'ORD-DONE', ['order_status' => 'completed', 'payment_status' => 'verified']);
        $this->order($customer, 'ORD-REJECTED', ['payment_status' => 'rejected']);

        $this->actingAs($customer);

        $this->get(route('my-orders.index', ['status' => 'completed']))
            ->assertSee('ORD-DONE')->assertDontSee('ORD-WAITING')->assertDontSee('ORD-REJECTED');

        $this->get(route('my-orders.index', ['payment' => 'rejected']))
            ->assertSee('ORD-REJECTED')->assertDontSee('ORD-DONE')->assertDontSee('ORD-WAITING');

        // Both together.
        $this->get(route('my-orders.index', ['status' => 'pending', 'payment' => 'pending']))
            ->assertSee('ORD-WAITING')->assertDontSee('ORD-DONE')->assertDontSee('ORD-REJECTED');
    }

    public function test_the_date_range_includes_both_ends(): void
    {
        $customer = $this->customer();
        $this->order($customer, 'ORD-OLD', ['order_date' => '2026-01-05 08:00:00']);
        $this->order($customer, 'ORD-EDGE', ['order_date' => '2026-02-10 23:30:00']);
        $this->order($customer, 'ORD-NEW', ['order_date' => '2026-03-01 09:00:00']);

        $this->actingAs($customer)->get(route('my-orders.index', ['from' => '2026-02-01', 'to' => '2026-02-10']))
            ->assertSee('ORD-EDGE')->assertDontSee('ORD-OLD')->assertDontSee('ORD-NEW');
    }

    public function test_junk_values_filter_nothing_instead_of_failing(): void
    {
        $customer = $this->customer();
        $this->order($customer, 'ORD-ANY');

        $this->actingAs($customer)
            ->get(route('my-orders.index', ['status' => 'nonsense', 'payment' => '<b>', 'from' => 'yesterday', 'to' => '2026-13-45']))
            ->assertOk()->assertSee('ORD-ANY');
    }

    public function test_a_filter_never_reaches_another_customers_orders(): void
    {
        $customer = $this->customer();
        $this->order($customer, 'ORD-MINE');
        $this->order($this->customer(), 'ORD-THEIRS');

        $this->actingAs($customer)->get(route('my-orders.index', ['q' => 'ORD-']))
            ->assertSee('ORD-MINE')->assertDontSee('ORD-THEIRS');
    }

    public function test_the_pager_keeps_the_filters(): void
    {
        $customer = $this->customer();

        for ($number = 1; $number <= 12; $number++) {
            $this->order($customer, sprintf('ORD-F-%02d', $number), ['order_status' => 'completed']);
        }
        $this->order($customer, 'ORD-OTHER');

        $this->actingAs($customer)->get(route('my-orders.index', ['status' => 'completed']))
            ->assertSee('Showing 1–10 of 12')
            ->assertDontSee('ORD-OTHER')
            ->assertSee('status=completed', false);
    }

    public function test_an_empty_result_says_nothing_matches_not_no_orders_yet(): void
    {
        $customer = $this->customer();
        $this->order($customer, 'ORD-ONE');

        $this->actingAs($customer)->get(route('my-orders.index', ['q' => 'zzzz']))
            ->assertOk()->assertSee('No orders match')->assertSee('Clear filters')->assertDontSee('No orders yet');

        // A customer with no orders at all still gets the invitation to order, and no filter bar.
        $this->actingAs($this->customer())->get(route('my-orders.index'))
            ->assertSee('No orders yet')->assertDontSee('role="search"', false);
    }

    public function test_the_order_link_still_opens_the_unfiltered_page(): void
    {
        $customer = $this->customer();
        $order = $this->order($customer, 'ORD-LINKED');

        $this->actingAs($customer)->get(route('my-orders.index', ['order' => $order->order_id, 'q' => 'nothing']))
            ->assertRedirect(route('my-orders.index') . '#order-' . $order->order_id);
    }
}
