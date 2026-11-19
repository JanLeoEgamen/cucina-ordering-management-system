<?php

namespace Tests\Feature;

use App\Models\BulkOrder;
use App\Models\BulkSession;
use App\Models\BulkSessionDish;
use App\Models\Dish;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// The customer side of Bulk Orders: the bulk menu, placing an order (minimum plates,
// prices, session state, payment proof, idempotency, the per-customer lock), and
// My Bulk Orders. There is no stock, so there is nothing to reserve or run out of.
class BulkCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return $this->userWith('view bulk-menu', 'create bulk-orders', 'view my bulk-orders');
    }

    /**
     * A live bulk session (minimum 10) with one dish at ₱100.
     *
     * @return array{0: BulkSession, 1: BulkSessionDish}
     */
    private function liveSession(int $minimum = 10, float $price = 100): array
    {
        $session = BulkSession::factory()->create(['min_quantity' => $minimum]);
        $line = BulkSessionDish::factory()->create([
            'bulk_session_id' => $session->bulk_session_id,
            'dish_id' => Dish::factory()->create(['price' => $price])->dish_id,
        ]);

        return [$session, $line];
    }

    // Change the masterlist dish behind a bulk session line (an admin editing it).
    private function updateDish(BulkSessionDish $line, array $attributes): void
    {
        DB::table('dishes')->where('dish_id', $line->dish_id)->update($attributes);
    }

    private function cash(): PaymentMethod
    {
        return PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
    }

    private function order(BulkSession $session, array $items, PaymentMethod $method, array $extra = []): array
    {
        return array_merge([
            'bulk_session_id' => $session->bulk_session_id,
            'payment_method_id' => $method->method_id,
            'items' => $items,
        ], $extra);
    }

    /* -------- access -------- */

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('bulk-menu.index'))->assertRedirect(route('login'));
        $this->get(route('bulk-my-orders.index'))->assertRedirect(route('login'));
        $this->post(route('bulk-orders.store'), [])->assertRedirect(route('login'));
    }

    public function test_each_page_and_the_order_need_their_own_permission(): void
    {
        [$session, $line] = $this->liveSession();
        $cash = $this->cash();

        $nobody = $this->userWith('view menu', 'create orders', 'view my orders');
        $this->actingAs($nobody);
        $this->get(route('bulk-menu.index'))->assertForbidden();
        $this->get(route('bulk-my-orders.index'))->assertForbidden();
        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $cash))->assertForbidden();

        // Seeing the menu is not ordering from it.
        $this->actingAs($this->userWith('view bulk-menu'));
        $this->get(route('bulk-menu.index'))->assertOk();
        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $cash))->assertForbidden();

        $this->assertDatabaseCount('bulk_orders', 0);
    }

    /* -------- the bulk menu -------- */

    public function test_the_menu_says_so_when_no_bulk_session_is_running(): void
    {
        BulkSession::factory()->ended()->create();

        $this->actingAs($this->customer())
            ->get(route('bulk-menu.index'))
            ->assertOk()
            ->assertSee('No bulk session is running')
            ->assertDontSee('id="bulk-summary"', false);
    }

    public function test_the_menu_lists_the_dishes_of_the_live_bulk_session_with_its_minimum(): void
    {
        [$session, $line] = $this->liveSession(25);
        $this->updateDish($line, ['dish_name' => 'Pork Sisig']);
        $this->cash();

        $this->actingAs($this->customer())
            ->get(route('bulk-menu.index'))
            ->assertOk()
            ->assertSee('Pork Sisig')
            ->assertSee('id="bulk-summary"', false)
            ->assertSee('data-min-quantity="25"', false)
            ->assertSee('data-dish-card="' . $line->bulk_session_dish_id . '"', false)
            // No stock anywhere on a bulk menu.
            ->assertDontSee('Sold out')
            ->assertDontSee('data-stock-url', false)
            ->assertSee('data-cash-tile', false);
    }

    public function test_a_dish_that_left_the_session_or_the_masterlist_is_not_offered(): void
    {
        [$session, $line] = $this->liveSession();
        $gone = BulkSessionDish::factory()->create([
            'bulk_session_id' => $session->bulk_session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Hidden Dish'])->dish_id,
            'is_available' => false,
        ]);

        $this->actingAs($this->customer())
            ->get(route('bulk-menu.index'))
            ->assertOk()
            ->assertDontSee('Hidden Dish')
            ->assertDontSee('data-dish-card="' . $gone->bulk_session_dish_id . '"', false);
    }

    public function test_the_regular_menu_and_the_bulk_menu_do_not_share_sessions(): void
    {
        // A live BULK session must not turn the regular menu on...
        $this->liveSession();
        $this->actingAs($this->userWith('view menu'))
            ->get(route('menu.index'))
            ->assertOk()
            ->assertSee('No order session is running');

        // ...and a live ORDER session must not turn the bulk menu on.
        BulkSession::query()->delete();
        \App\Models\OrderSession::factory()->create();
        $this->actingAs($this->customer())
            ->get(route('bulk-menu.index'))
            ->assertOk()
            ->assertSee('No bulk session is running');
    }

    /* -------- placing an order -------- */

    public function test_a_cash_order_is_placed_with_no_proof_and_no_stock_touched(): void
    {
        [$session, $line] = $this->liveSession(10, 120);
        $cash = $this->cash();
        $customer = $this->customer();

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 12], $cash))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('total_amount', 1440)
            ->assertJsonPath('plates', 12);

        $order = BulkOrder::ofUser($customer->id)[0];

        $this->assertSame('BLK-' . now()->format('Ymd') . '-0001', $order->order_number);
        $this->assertSame('pending', $order->order_status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(PaymentMethod::CASH_ON_PICKUP, $order->method_name);
        $this->assertNull($order->proof_of_payment);
        $this->assertSame(1440.0, (float) $order->total_amount);
        $this->assertSame(12, $order->items[0]['quantity']);
        // The line keeps the price of the day.
        $this->assertSame(120.0, $order->items[0]['unit_price']);
        $this->assertSame(1440.0, $order->items[0]['sub_total']);
        // Not an ordinary order: nothing in the regular tables.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SessionDish::query()->count());
    }

    public function test_a_price_change_afterwards_does_not_touch_a_placed_order(): void
    {
        [$session, $line] = $this->liveSession(10, 50);
        $customer = $this->customer();

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $this->cash()))
            ->assertOk();

        $this->updateDish($line, ['price' => 999]);

        $this->assertSame(50.0, BulkOrder::ofUser($customer->id)[0]->items[0]['unit_price']);
    }

    public function test_the_order_can_mix_dishes_and_the_minimum_counts_all_of_them(): void
    {
        [$session, $first] = $this->liveSession(10, 100);
        $second = BulkSessionDish::factory()->create([
            'bulk_session_id' => $session->bulk_session_id,
            'dish_id' => Dish::factory()->create(['price' => 30.1])->dish_id,
        ]);
        $customer = $this->customer();

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, [
                $first->bulk_session_dish_id => 4,
                $second->bulk_session_dish_id => 6,
            ], $this->cash()))
            ->assertOk()
            ->assertJsonPath('plates', 10)
            // 4 x 100 + 6 x 30.10, in cents.
            ->assertJsonPath('total_amount', 580.6);

        $this->assertCount(2, BulkOrder::ofUser($customer->id)[0]->items);
    }

    public function test_an_order_below_the_minimum_is_refused_and_leaves_nothing_behind(): void
    {
        Storage::fake('public');
        [$session, $line] = $this->liveSession(20);
        $gcash = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 19], $gcash, [
                'proof_of_payment' => UploadedFile::fake()->image('proof.jpg'),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('min_quantity', 20)
            ->assertJsonPath('prices.' . $line->bulk_session_dish_id, 100);

        $this->assertDatabaseCount('bulk_orders', 0);
        $this->assertDatabaseCount('bulk_order_items', 0);
        // The picture was not stored.
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /* -------- the limits of System Configuration -------- */

    public function test_an_order_above_the_system_maximum_is_refused_and_leaves_nothing_behind(): void
    {
        Storage::fake('public');
        Setting::setBulkLimits(10, 50);
        [$session, $line] = $this->liveSession(10);
        $gcash = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        $response = $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 51], $gcash, [
                'proof_of_payment' => UploadedFile::fake()->image('proof.jpg'),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('min_quantity', 10)
            ->assertJsonPath('max_quantity', 50);

        $this->assertStringContainsString('at most 50 plates', $response->json('message'));
        $this->assertDatabaseCount('bulk_orders', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_exactly_the_system_maximum_is_enough(): void
    {
        Setting::setBulkLimits(10, 50);
        [$session, $line] = $this->liveSession(10);

        $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 50], $this->cash()))
            ->assertOk()->assertJsonPath('plates', 50);
    }

    public function test_without_a_maximum_a_big_order_is_accepted(): void
    {
        [$session, $line] = $this->liveSession(10);

        $this->assertNull(Setting::bulkLimits()['max']);

        $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 5000], $this->cash()))
            ->assertOk();
    }

    public function test_the_system_minimum_beats_a_smaller_session_minimum(): void
    {
        // A session made before the floor was raised.
        $session = BulkSession::factory()->create(['min_quantity' => 5]);
        $line = BulkSessionDish::factory()->create([
            'bulk_session_id' => $session->bulk_session_id,
            'dish_id' => Dish::factory()->create(['price' => 100])->dish_id,
        ]);
        Setting::setBulkLimits(20, null);

        $response = $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $this->cash()))
            ->assertStatus(422)
            ->assertJsonPath('min_quantity', 20);

        $this->assertStringContainsString('at least 20 plates', $response->json('message'));
    }

    public function test_a_session_minimum_above_a_lowered_maximum_is_brought_down_to_it(): void
    {
        [$session, $line] = $this->liveSession(40);
        // The maximum was lowered after the session was made: it must stay orderable.
        Setting::setBulkLimits(10, 30);

        $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 30], $this->cash()))
            ->assertOk();
    }

    public function test_the_menu_shows_the_limits_to_the_customer(): void
    {
        Setting::setBulkLimits(15, 80);
        $this->liveSession(10);
        $this->cash();

        $this->actingAs($this->customer())
            ->get(route('bulk-menu.index'))
            ->assertOk()
            ->assertSee('data-min-quantity="15"', false)
            ->assertSee('data-max-quantity="80"', false);
    }

    public function test_exactly_the_minimum_is_enough(): void
    {
        [$session, $line] = $this->liveSession(20);

        $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 20], $this->cash()))
            ->assertOk();
    }

    public function test_the_minimum_raised_while_checking_out_is_applied(): void
    {
        [$session, $line] = $this->liveSession(10);
        $cash = $this->cash();

        BulkSession::updateSession($session->bulk_session_id, ['min_quantity' => 30], [$line->dish_id]);

        $response = $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $cash))
            ->assertStatus(422);

        $this->assertStringContainsString('at least 30 plates', $response->json('message'));
        $this->assertSame(30, $response->json('min_quantity'));
    }

    public function test_a_session_that_is_not_running_refuses_the_order(): void
    {
        $cash = $this->cash();
        $customer = $this->customer();

        $ended = BulkSession::factory()->ended()->create();
        $scheduled = BulkSession::factory()->scheduled()->create();
        $manual = BulkSession::factory()->create();
        BulkSession::endSession($manual->bulk_session_id);

        foreach ([$ended, $scheduled, $manual] as $session) {
            $line = BulkSessionDish::factory()->create(['bulk_session_id' => $session->bulk_session_id]);

            $this->actingAs($customer)
                ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 50], $cash))
                ->assertStatus(422)
                ->assertJsonPath('message', 'This bulk session is not running.');
        }

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), [
                'bulk_session_id' => 999999, 'payment_method_id' => $cash->method_id, 'items' => [1 => 50],
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('bulk_orders', 0);
    }

    public function test_a_dish_from_another_session_or_a_removed_one_is_refused(): void
    {
        [$session, $line] = $this->liveSession();
        [, $foreign] = $this->liveSession();
        $cash = $this->cash();

        // Another session's dish id must not be orderable here.
        $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$foreign->bulk_session_dish_id => 50], $cash))
            ->assertStatus(422)
            ->assertJsonPath('prices.' . $line->bulk_session_dish_id, 100);

        // A dish an admin took out of the session while the customer was browsing.
        BulkSession::syncDishes($session->bulk_session_id, []);
        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 50], $cash))
            ->assertStatus(422);

        $this->assertDatabaseCount('bulk_orders', 0);
    }

    public function test_a_changed_price_refuses_the_order_and_carries_the_new_price(): void
    {
        [$session, $line] = $this->liveSession(10, 100);
        $this->updateDish($line, ['price' => 125]);

        $response = $this->actingAs($this->customer())
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $this->cash(), [
                'expected_prices' => [$line->bulk_session_dish_id => 100],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('prices.' . $line->bulk_session_dish_id, 125);

        $this->assertStringContainsString('now ₱125.00 (was ₱100.00)', $response->json('message'));
        $this->assertDatabaseCount('bulk_orders', 0);
    }

    public function test_cents_compare_equal_and_a_missing_expected_price_is_not_checked(): void
    {
        [$session, $line] = $this->liveSession(10, 99.9);
        $cash = $this->cash();
        $customer = $this->customer();

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $cash, [
                'expected_prices' => [$line->bulk_session_dish_id => '99.90'],
                'idempotency_key' => 'a',
            ]))
            ->assertOk();

        $this->updateDish($line, ['price' => 150]);

        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $cash, [
            'idempotency_key' => 'b',
        ]))->assertOk()->assertJsonPath('total_amount', 1500);
    }

    /* -------- payment -------- */

    public function test_a_bank_order_needs_a_proof_and_keeps_it_with_the_reference(): void
    {
        Storage::fake('public');
        [$session, $line] = $this->liveSession();
        $gcash = PaymentMethod::factory()->create(['method_name' => 'GCash']);
        $customer = $this->customer();
        $items = [$line->bulk_session_dish_id => 10];

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, $items, $gcash))
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_of_payment');
        $this->assertDatabaseCount('bulk_orders', 0);

        $this->postJson(route('bulk-orders.store'), $this->order($session, $items, $gcash, [
            'proof_of_payment' => UploadedFile::fake()->image('proof.jpg'),
            'reference_number' => 'REF-123',
        ]))->assertOk();

        $order = BulkOrder::ofUser($customer->id)[0];

        $this->assertSame('GCash', $order->method_name);
        $this->assertSame('REF-123', $order->reference_number);
        $this->assertSame('pending', $order->payment_status);
        $this->assertNotNull($order->proof_of_payment);
        Storage::disk('public')->assertExists($order->proof_of_payment);
    }

    public function test_a_proof_must_be_a_small_image(): void
    {
        Storage::fake('public');
        [$session, $line] = $this->liveSession();
        $gcash = PaymentMethod::factory()->create(['method_name' => 'GCash']);
        $this->actingAs($this->customer());

        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $gcash, [
            'proof_of_payment' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ]))->assertStatus(422)->assertJsonValidationErrors('proof_of_payment');

        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $gcash, [
            'proof_of_payment' => UploadedFile::fake()->image('proof.jpg')->size(3000),
        ]))->assertStatus(422)->assertJsonValidationErrors('proof_of_payment');

        $this->assertDatabaseCount('bulk_orders', 0);
    }

    public function test_an_inactive_or_unknown_payment_method_is_refused(): void
    {
        [$session, $line] = $this->liveSession();
        $off = PaymentMethod::factory()->create(['method_name' => 'Old Wallet', 'is_active' => false]);
        $this->actingAs($this->customer());

        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $off))
            ->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
        $this->postJson(route('bulk-orders.store'), [
            'bulk_session_id' => $session->bulk_session_id, 'items' => [$line->bulk_session_dish_id => 10],
        ])->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
    }

    /* -------- input rules -------- */

    public function test_the_items_are_validated(): void
    {
        [$session, $line] = $this->liveSession();
        $cash = $this->cash();
        $this->actingAs($this->customer());

        $this->postJson(route('bulk-orders.store'), $this->order($session, [], $cash))
            ->assertStatus(422)->assertJsonValidationErrors('items');
        // Zero and negative plates are dropped, which leaves nothing.
        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 0], $cash))
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => -5], $cash))
            ->assertStatus(422)->assertJsonValidationErrors('items');
        // An absurd line is refused rather than stored.
        $this->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10001], $cash))
            ->assertStatus(422)->assertJsonValidationErrors('items.' . $line->bulk_session_dish_id);

        $this->assertDatabaseCount('bulk_orders', 0);
    }

    /* -------- double taps and retries -------- */

    public function test_a_retry_with_the_same_key_returns_the_same_receipt_and_one_order(): void
    {
        [$session, $line] = $this->liveSession();
        $cash = $this->cash();
        $customer = $this->customer();
        $body = $this->order($session, [$line->bulk_session_dish_id => 10], $cash, ['idempotency_key' => 'abc-123']);

        $first = $this->actingAs($customer)->postJson(route('bulk-orders.store'), $body)->assertOk()->json();
        $second = $this->postJson(route('bulk-orders.store'), $body)->assertOk()->json();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('bulk_orders', 1);
    }

    public function test_the_same_key_from_another_customer_is_a_different_order(): void
    {
        [$session, $line] = $this->liveSession();
        $cash = $this->cash();
        $body = $this->order($session, [$line->bulk_session_dish_id => 10], $cash, ['idempotency_key' => 'shared-key']);

        $this->actingAs($this->customer())->postJson(route('bulk-orders.store'), $body)->assertOk();
        $this->actingAs($this->customer())->postJson(route('bulk-orders.store'), $body)->assertOk();

        $this->assertDatabaseCount('bulk_orders', 2);
    }

    public function test_a_customer_with_an_order_in_flight_gets_409_and_the_lock_is_released_after(): void
    {
        [$session, $line] = $this->liveSession();
        $cash = $this->cash();
        $customer = $this->customer();
        $body = $this->order($session, [$line->bulk_session_dish_id => 10], $cash);

        $held = Cache::lock('place-bulk-order:' . $customer->id, 30);
        $this->assertTrue($held->get());

        $this->actingAs($customer)->postJson(route('bulk-orders.store'), $body)->assertStatus(409);
        $this->assertDatabaseCount('bulk_orders', 0);

        $held->release();

        $this->postJson(route('bulk-orders.store'), $body)->assertOk();
        // And it let go again, so the next order is not blocked.
        $this->postJson(route('bulk-orders.store'), $body)->assertOk();
    }

    public function test_a_regular_order_in_flight_does_not_block_a_bulk_order(): void
    {
        [$session, $line] = $this->liveSession();
        $customer = $this->customer();

        $regular = Cache::lock('place-order:' . $customer->id, 30);
        $this->assertTrue($regular->get());

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $this->cash()))
            ->assertOk();

        $regular->release();
    }

    public function test_a_bulk_receipt_never_answers_a_regular_order_key(): void
    {
        [$session, $line] = $this->liveSession();
        $customer = $this->customer();

        // The regular menu cached a receipt under the same key and customer.
        Cache::put('order-receipt:' . $customer->id . ':same', ['status' => 'success', 'order_number' => 'ORD-X'], 600);

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $this->cash(), ['idempotency_key' => 'same']))
            ->assertOk()
            ->assertJsonPath('order_number', 'BLK-' . now()->format('Ymd') . '-0001');
    }

    public function test_order_numbers_count_up_per_day_with_the_bulk_prefix(): void
    {
        [$session, $line] = $this->liveSession();
        $cash = $this->cash();
        $this->actingAs($this->customer());
        $body = $this->order($session, [$line->bulk_session_dish_id => 10], $cash);

        $one = $this->postJson(route('bulk-orders.store'), $body)->json('order_number');
        $two = $this->postJson(route('bulk-orders.store'), $body)->json('order_number');

        $this->assertSame('BLK-' . now()->format('Ymd') . '-0001', $one);
        $this->assertSame('BLK-' . now()->format('Ymd') . '-0002', $two);
    }

    public function test_the_order_endpoint_is_throttled_to_ten_a_minute(): void
    {
        [$session, $line] = $this->liveSession();
        $cash = $this->cash();
        $this->actingAs($this->customer());
        $body = $this->order($session, [$line->bulk_session_dish_id => 10], $cash);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('bulk-orders.store'), $body)->assertOk();
        }

        $this->postJson(route('bulk-orders.store'), $body)->assertStatus(429);
    }

    /* -------- My Bulk Orders -------- */

    public function test_my_bulk_orders_lists_only_my_orders_newest_first_with_lines_and_payment(): void
    {
        $me = $this->customer();
        $someoneElse = $this->customer();
        $mine = BulkOrder::factory()->create(['user_id' => $me->id, 'order_number' => 'BLK-OLD', 'total_amount' => 500]);
        $newer = BulkOrder::factory()->create([
            'user_id' => $me->id,
            'order_number' => 'BLK-NEW',
            'payment_status' => 'rejected',
            'rejection_reason' => 'The picture is blurry',
        ]);
        BulkOrder::factory()->create(['user_id' => $someoneElse->id, 'order_number' => 'BLK-NOT-MINE']);
        \App\Models\BulkOrderItem::factory()->create([
            'bulk_order_id' => $mine->bulk_order_id,
            'bulk_session_dish_id' => BulkSessionDish::factory()->create([
                'dish_id' => Dish::factory()->create(['dish_name' => 'Beef Caldereta'])->dish_id,
            ])->bulk_session_dish_id,
            'quantity' => 15,
            'unit_price' => 200,
            'sub_total' => 3000,
        ]);

        $this->actingAs($me)
            ->get(route('bulk-my-orders.index'))
            ->assertOk()
            ->assertSeeInOrder(['BLK-NEW', 'BLK-OLD'])
            ->assertDontSee('BLK-NOT-MINE')
            ->assertSee('Beef Caldereta')
            ->assertSee('15×', false)
            ->assertSee('₱3,000.00')
            ->assertSee('Payment rejected')
            ->assertSee('The picture is blurry')
            ->assertSee('id="bulk-order-' . $newer->bulk_order_id . '"', false);
    }

    public function test_my_bulk_orders_is_empty_for_a_new_customer(): void
    {
        $this->actingAs($this->customer())
            ->get(route('bulk-my-orders.index'))
            ->assertOk()
            ->assertSee('No bulk orders yet');
    }

    public function test_a_bulk_order_is_not_listed_in_my_regular_orders_or_the_orders_page(): void
    {
        [$session, $line] = $this->liveSession();
        $customer = $this->userWith('view bulk-menu', 'create bulk-orders', 'view my orders', 'view orders');

        $this->actingAs($customer)
            ->postJson(route('bulk-orders.store'), $this->order($session, [$line->bulk_session_dish_id => 10], $this->cash()))
            ->assertOk();

        $this->get(route('my-orders.index'))->assertOk()->assertDontSee('BLK-');
        $this->getJson(route('orders.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson(route('payments.index'), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
    }

    /* -------- navigation -------- */

    public function test_the_sidebar_and_dashboard_link_to_the_bulk_pages_by_permission(): void
    {
        $this->actingAs($this->customer())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('bulk-menu.index'))
            ->assertSee(route('bulk-my-orders.index'));

        $this->actingAs($this->userWith('view menu', 'view my orders'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('bulk-menu.index'))
            ->assertDontSee(route('bulk-my-orders.index'));
    }

    public function test_the_customer_role_can_use_bulk_ordering_out_of_the_box(): void
    {
        $this->seedPermissions();

        $customer = User::factory()->create();
        $customer->assignRole(\App\Models\Role::CUSTOMER);

        $this->actingAs($customer)->get(route('bulk-menu.index'))->assertOk();
        $this->get(route('bulk-my-orders.index'))->assertOk();
        // ...but nothing behind the counter.
        $this->get(route('bulk-sessions.index'))->assertForbidden();
    }
}
