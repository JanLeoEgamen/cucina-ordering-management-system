<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// An admin changes a price while a customer is browsing. The customer must not
// be charged (or have a payment proof matched against) a price they never saw.
class OrderPriceChangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: OrderSession, 1: SessionDish, 2: PaymentMethod}
     */
    private function liveSession(float $price = 100.0): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Adobo', 'price' => $price])->dish_id,
            'available_stock' => 10,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        return [$session, $line, $cash];
    }

    private function setPrice(SessionDish $line, float $price): void
    {
        DB::table('dishes')->where('dish_id', $line->dish_id)->update(['price' => $price]);
    }

    private function order(OrderSession $session, PaymentMethod $method, array $items, array $extra = []): array
    {
        return [
            'session_id' => $session->session_id,
            'payment_method_id' => $method->method_id,
            'items' => $items,
        ] + $extra;
    }

    public function test_the_live_feed_carries_the_current_prices(): void
    {
        [$session, $line] = $this->liveSession(100);
        $this->actingAs($this->userWith('view menu'));

        $this->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertOk()
            ->assertJsonPath('prices.' . $line->session_dish_id, 100);

        $this->setPrice($line, 120.5);

        $this->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertJsonPath('prices.' . $line->session_dish_id, 120.5);
    }

    public function test_the_feed_has_no_price_for_a_dish_that_was_turned_off_or_a_closed_session(): void
    {
        [$session, $line] = $this->liveSession();
        $this->actingAs($this->userWith('view menu'));

        SessionDish::query()->whereKey($line->session_dish_id)->update(['is_available' => false]);

        $this->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertJsonMissingPath('prices.' . $line->session_dish_id);

        OrderSession::endSession((int) $session->session_id);

        $this->getJson(route('menu.stock', ['session_id' => $session->session_id]))
            ->assertJsonPath('prices', []);
    }

    public function test_an_order_at_the_price_the_customer_saw_is_placed(): void
    {
        [$session, $line, $cash] = $this->liveSession(100);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 2], [
                'expected_prices' => [$line->session_dish_id => '100'],
            ]))
            ->assertOk()
            ->assertJsonPath('total_amount', 200);
    }

    public function test_a_changed_price_refuses_the_order_and_shows_the_new_price(): void
    {
        [$session, $line, $cash] = $this->liveSession(100);
        $this->setPrice($line, 120);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 2], [
                'expected_prices' => [$line->session_dish_id => '100'],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('prices.' . $line->session_dish_id, 120)
            ->assertJsonPath('stock.' . $line->session_dish_id, 10)
            // The JSON body writes the peso sign as ₱, so the decoded message is checked.
            ->assertJson(fn ($json) => $json->where('message', fn ($message) => str_contains($message, 'Adobo is now ₱120.00 (was ₱100.00)'))->etc());

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, (int) SessionDish::query()->find($line->session_dish_id)->available_stock);
    }

    public function test_the_same_cart_goes_through_once_the_customer_has_the_new_price(): void
    {
        [$session, $line, $cash] = $this->liveSession(100);
        $this->setPrice($line, 120);
        $user = $this->superAdmin();

        $this->actingAs($user)
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 2], [
                'idempotency_key' => 'cart-1',
                'expected_prices' => [$line->session_dish_id => '100'],
            ]))
            ->assertStatus(422);

        // The refusal is not remembered: the menu retries with the new price.
        $this->actingAs($user)
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 2], [
                'idempotency_key' => 'cart-1',
                'expected_prices' => [$line->session_dish_id => '120'],
            ]))
            ->assertOk()
            ->assertJsonPath('total_amount', 240);
    }

    public function test_a_bank_order_with_a_stale_price_leaves_no_proof_behind(): void
    {
        Storage::fake('public');
        [$session, $line] = $this->liveSession(100);
        $bank = PaymentMethod::factory()->create(['method_name' => 'GCash']);
        $this->setPrice($line, 120);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $bank, [$line->session_dish_id => 2], [
                'expected_prices' => [$line->session_dish_id => '100'],
                'proof_of_payment' => UploadedFile::fake()->image('proof.jpg'),
            ]))
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles('payments'));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_one_changed_price_in_a_cart_refuses_the_whole_order(): void
    {
        [$session, $adobo, $cash] = $this->liveSession(100);
        $rice = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Rice', 'price' => 20])->dish_id,
            'available_stock' => 10,
        ]);
        $this->setPrice($rice, 25);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$adobo->session_dish_id => 1, $rice->session_dish_id => 1], [
                'expected_prices' => [$adobo->session_dish_id => '100', $rice->session_dish_id => '20'],
            ]))
            ->assertStatus(422)
            ->assertJson(fn ($json) => $json
                ->where('message', fn ($message) => str_contains($message, 'Rice is now ₱25.00 (was ₱20.00)') && ! str_contains($message, 'Adobo is now'))
                ->etc());

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_price_change_of_a_dish_not_in_the_cart_does_not_matter(): void
    {
        [$session, $adobo, $cash] = $this->liveSession(100);
        $rice = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['price' => 20])->dish_id,
            'available_stock' => 10,
        ]);
        $this->setPrice($rice, 99);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$adobo->session_dish_id => 1], [
                'expected_prices' => [$adobo->session_dish_id => '100'],
            ]))
            ->assertOk();
    }

    public function test_cents_written_differently_are_the_same_price(): void
    {
        [$session, $line, $cash] = $this->liveSession(0.10);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1], [
                'expected_prices' => [$line->session_dish_id => '0.1'],
            ]))
            ->assertOk();
    }

    public function test_a_client_that_sends_no_prices_is_not_checked(): void
    {
        [$session, $line, $cash] = $this->liveSession(100);
        $this->setPrice($line, 120);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1]))
            ->assertOk()
            ->assertJsonPath('total_amount', 120);
    }

    public function test_a_price_that_is_not_a_number_is_refused(): void
    {
        [$session, $line, $cash] = $this->liveSession();

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), $this->order($session, $cash, [$line->session_dish_id => 1], [
                'expected_prices' => [$line->session_dish_id => 'cheap'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_prices.' . $line->session_dish_id);
    }

    public function test_the_menu_marks_each_price_pill_so_the_script_can_update_it(): void
    {
        [, $line] = $this->liveSession(100);

        $this->actingAs($this->userWith('view menu'))
            ->get(route('menu.index'))
            ->assertOk()
            ->assertSee('data-price-label="' . $line->session_dish_id . '"', false)
            ->assertSee('data-price-changed=', false);
    }
}
