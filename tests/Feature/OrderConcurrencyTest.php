<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Many customers pressing Place Order at once, and the live stock feed the menu
// polls. SQLite cannot run two requests truly in parallel, so these tests cover
// the guards around the race (lock, idempotency key, stock in the refusal,
// ordered reserve) and the stock feed; the atomic UPDATE itself is covered by
// the "last plate" test.
class OrderConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: OrderSession, 1: SessionDish, 2: PaymentMethod}
     */
    private function liveSession(int $stock = 10, float $price = 5.0): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['price' => $price])->dish_id,
            'available_stock' => $stock,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        return [$session, $line, $cash];
    }

    private function order(OrderSession $session, PaymentMethod $method, array $items, array $extra = []): array
    {
        return [
            'session_id' => $session->session_id,
            'payment_method_id' => $method->method_id,
            'items' => $items,
        ] + $extra;
    }

    private function stockOf(SessionDish $line): int
    {
        return (int) SessionDish::query()->find($line->session_dish_id)->available_stock;
    }

    /* ---- the live stock feed ------------------------------------------- */

    public function test_the_stock_feed_needs_the_view_menu_permission(): void
    {
        [$session] = $this->liveSession();

        $this->actingAs($this->userWith())
            ->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertForbidden();
    }

    public function test_the_stock_feed_returns_what_is_left_and_is_never_cached(): void
    {
        [$session, $line] = $this->liveSession(7);

        $this->actingAs($this->userWith('view menu'))
            ->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertOk()
            ->assertJsonPath('live', true)
            ->assertJsonPath('stock.' . $line->session_dish_id, 7)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_stock_feed_follows_an_order_and_a_cancel(): void
    {
        [$session, $line, $cash] = $this->liveSession(5);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 2]))
            ->assertOk();

        $this->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertJsonPath('stock.' . $line->session_dish_id, 3);

        $orderId = (int) \DB::table('orders')->value('order_id');

        $this->postJson(route('orders.cancel', $orderId))->assertOk();

        $this->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertJsonPath('stock.' . $line->session_dish_id, 5);
    }

    public function test_the_stock_feed_says_when_the_session_is_no_longer_live(): void
    {
        [$session, $line] = $this->liveSession();
        OrderSession::endSession((int) $session->session_id);

        $this->actingAs($this->userWith('view menu'))
            ->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertOk()
            ->assertJsonPath('live', false)
            ->assertJsonPath('stock', []);

        $this->getJson(route('menu.stock'))->assertJsonPath('live', false);
    }

    public function test_a_dish_that_was_turned_off_leaves_the_stock_feed(): void
    {
        [$session, $line] = $this->liveSession();
        SessionDish::query()->whereKey($line->session_dish_id)->update(['is_available' => false]);

        $this->actingAs($this->userWith('view menu'))
            ->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertJsonMissingPath('stock.' . $line->session_dish_id);
    }

    /* ---- placing orders at the same time ------------------------------- */

    public function test_the_last_plate_goes_to_one_customer_only(): void
    {
        [$session, $line, $cash] = $this->liveSession(1);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1]))
            ->assertOk();

        // The second customer still holds a menu that showed one plate.
        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1]))
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $this->stockOf($line));
    }

    public function test_a_refused_order_names_the_dish_and_carries_the_real_stock(): void
    {
        [$session, $line, $cash] = $this->liveSession(2);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 5]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('stock.' . $line->session_dish_id, 2)
            ->assertSee('Only 2 left of');
    }

    public function test_the_refusal_of_a_sold_out_dish_says_sold_out(): void
    {
        [$session, $line, $cash] = $this->liveSession(0);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1]))
            ->assertStatus(422)
            ->assertSee('is sold out');
    }

    public function test_a_second_order_from_the_same_customer_is_refused_while_one_is_being_placed(): void
    {
        [$session, $line, $cash] = $this->liveSession();
        $user = $this->superAdmin();

        // Another request of this customer is still inside store().
        $held = Cache::lock('place-order:' . $user->id, 30);
        $this->assertTrue($held->get());

        $this->actingAs($user)
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1]))
            ->assertStatus(409);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->stockOf($line));

        // Once it is done, the customer can order again.
        $held->release();

        $this->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1]))
            ->assertOk();
    }

    public function test_the_lock_is_released_after_a_refused_order(): void
    {
        [$session, $line, $cash] = $this->liveSession(1);
        $user = $this->superAdmin();

        $this->actingAs($user)
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 9]))
            ->assertStatus(422);

        // Not a 409: the refusal did not leave the customer locked out.
        $this->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1]))
            ->assertOk();
    }

    public function test_a_retry_with_the_same_key_returns_the_same_receipt_and_takes_stock_once(): void
    {
        [$session, $line, $cash] = $this->liveSession(10);
        $payload = $this->order($session, $cash, [$line->session_dish_id => 2], ['idempotency_key' => 'abc-123']);
        $user = $this->superAdmin();

        $first = $this->actingAs($user)->postJson(route('orders.store'), $payload)->assertOk();
        // The answer got lost, the customer taps Place Order again.
        $second = $this->actingAs($user)->postJson(route('orders.store'), $payload)->assertOk();

        $this->assertSame($first->json('order_number'), $second->json('order_number'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(8, $this->stockOf($line));
    }

    public function test_a_different_key_is_a_different_order(): void
    {
        [$session, $line, $cash] = $this->liveSession(10);
        $user = $this->superAdmin();

        $this->actingAs($user)->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1], ['idempotency_key' => 'one']))->assertOk();
        $this->actingAs($user)->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1], ['idempotency_key' => 'two']))->assertOk();

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(8, $this->stockOf($line));
    }

    public function test_the_same_key_from_another_customer_is_not_a_replay(): void
    {
        [$session, $line, $cash] = $this->liveSession(10);
        $payload = $this->order($session, $cash, [$line->session_dish_id => 1], ['idempotency_key' => 'shared']);

        $this->actingAs($this->superAdmin())->postJson(route('orders.store'), $payload)->assertOk();
        $this->actingAs($this->superAdmin())->postJson(route('orders.store'), $payload)->assertOk();

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_orders_for_the_same_dishes_in_any_order_both_succeed(): void
    {
        $session = OrderSession::factory()->create();
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $a = SessionDish::factory()->create(['session_id' => $session->session_id, 'dish_id' => Dish::factory()->create()->dish_id, 'available_stock' => 5]);
        $b = SessionDish::factory()->create(['session_id' => $session->session_id, 'dish_id' => Dish::factory()->create()->dish_id, 'available_stock' => 5]);

        // The cart order of the two customers is crosswise: this is the shape
        // that deadlocks MySQL unless the lines are reserved in a fixed order.
        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$a->session_dish_id => 1, $b->session_dish_id => 1]))
            ->assertOk();
        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$b->session_dish_id => 2, $a->session_dish_id => 2]))
            ->assertOk();

        $this->assertSame(2, $this->stockOf($a));
        $this->assertSame(2, $this->stockOf($b));
    }

    public function test_a_failed_multi_dish_order_takes_nothing(): void
    {
        $session = OrderSession::factory()->create();
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $plenty = SessionDish::factory()->create(['session_id' => $session->session_id, 'dish_id' => Dish::factory()->create()->dish_id, 'available_stock' => 5]);
        $short = SessionDish::factory()->create(['session_id' => $session->session_id, 'dish_id' => Dish::factory()->create()->dish_id, 'available_stock' => 1]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$plenty->session_dish_id => 2, $short->session_dish_id => 3]))
            ->assertStatus(422);

        $this->assertSame(5, $this->stockOf($plenty));
        $this->assertSame(1, $this->stockOf($short));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_refused_order_does_not_keep_the_uploaded_proof(): void
    {
        Storage::fake('public');
        [$session, $line] = $this->liveSession(1);
        $bank = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $bank, [$line->session_dish_id => 5], [
                'proof_of_payment' => UploadedFile::fake()->image('proof.jpg'),
            ]))
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles('payments'));
    }

    public function test_reserve_refuses_a_dish_that_was_just_turned_off(): void
    {
        [, $line] = $this->liveSession(5);
        SessionDish::query()->whereKey($line->session_dish_id)->update(['is_available' => false]);

        $this->assertFalse(SessionDish::reserve((int) $line->session_dish_id, 1));
        $this->assertSame(5, $this->stockOf($line));
    }

    /* ---- the order itself ---------------------------------------------- */

    public function test_a_payment_method_that_was_turned_off_cannot_be_used(): void
    {
        [$session, $line] = $this->liveSession();
        $off = PaymentMethod::factory()->create(['method_name' => 'Maya', 'is_active' => false]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $off, [$line->session_dish_id => 1], [
                'proof_of_payment' => UploadedFile::fake()->image('proof.jpg'),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method_id');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_total_is_rounded_to_cents(): void
    {
        [$session, $line, $cash] = $this->liveSession(10, 0.10);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 3]))
            ->assertOk();

        // 3 x 0.10 as floats is 0.30000000000000004.
        $this->assertEquals(0.3, \DB::table('orders')->value('total_amount'));
        $this->assertEquals(0.3, \DB::table('order_items')->sum('sub_total'));
    }

    public function test_placing_orders_is_throttled(): void
    {
        $user = $this->superAdmin();

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson(route('orders.store'), [])->assertStatus(422);
        }

        $this->actingAs($user)->postJson(route('orders.store'), [])->assertStatus(429);
    }

    /* ---- staff acting on the same order -------------------------------- */

    public function test_cancelling_twice_gives_the_stock_back_once(): void
    {
        [$session, $line, $cash] = $this->liveSession(5);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 2]))
            ->assertOk();

        $orderId = (int) \DB::table('orders')->value('order_id');

        $this->postJson(route('orders.cancel', $orderId))->assertOk();
        $this->postJson(route('orders.cancel', $orderId))->assertStatus(422);

        $this->assertSame(5, $this->stockOf($line));
    }
}
