<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\Discount;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\PreOrderItem;
use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Ala carte: a plate WITHOUT its rice, for the rice price less. The switch and the price are System
// Configuration (Setting::alaCarteMode() / ricePrice()), the dishes that come with rice are ticked on
// the Dish Masterlist (dishes.includes_rice), and the customer picks how many plates go without rice
// at checkout (regular orders and pre-orders). The server does all the math.
class AlaCarteTest extends TestCase
{
    use RefreshDatabase;

    private OrderSession $session;

    private SessionDish $line;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->session = OrderSession::factory()->create();
        // A dish at ₱100 that comes with rice.
        $this->line = SessionDish::factory()->create([
            'session_id' => $this->session->session_id,
            'dish_id' => Dish::factory()->withRice()->create(['dish_name' => 'Adobo', 'price' => 100])->dish_id,
            'available_stock' => 10,
        ]);
        $this->cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        // Ala carte on for regular orders, rice is ₱20 a plate.
        Setting::setAlaCarte('orders', 20);
    }

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    private function place(array $extra = [], int $quantity = 3, ?User $customer = null)
    {
        return $this->actingAs($customer ?? $this->customer())->postJson(route('orders.store'), $extra + [
            'session_id' => $this->session->session_id,
            'payment_method_id' => $this->cash->method_id,
            'items' => [$this->line->session_dish_id => $quantity],
        ]);
    }

    private function orderId(): int
    {
        return (int) DB::table('orders')->orderByDesc('order_id')->value('order_id');
    }

    private function stock(): int
    {
        return (int) DB::table('session_dishes')->where('session_dish_id', $this->line->session_dish_id)->value('available_stock');
    }

    /* -------- the settings -------- */

    public function test_it_is_off_until_an_admin_turns_it_on(): void
    {
        DB::table('settings')->delete();
        \Illuminate\Support\Facades\Cache::forget('settings.all');

        $this->assertSame('off', Setting::alaCarteMode());
        $this->assertFalse(Setting::alaCarteFor('orders'));
        $this->assertFalse(Setting::alaCarteFor('pre_orders'));
    }

    public function test_the_mode_decides_where_it_is_offered_and_a_zero_price_offers_nothing(): void
    {
        Setting::setAlaCarte('orders', 20);
        $this->assertTrue(Setting::alaCarteFor('orders'));
        $this->assertFalse(Setting::alaCarteFor('pre_orders'));

        Setting::setAlaCarte('pre_orders', 20);
        $this->assertFalse(Setting::alaCarteFor('orders'));
        $this->assertTrue(Setting::alaCarteFor('pre_orders'));

        Setting::setAlaCarte('both', 20);
        $this->assertTrue(Setting::alaCarteFor('orders'));
        $this->assertTrue(Setting::alaCarteFor('pre_orders'));

        // A deduction of nothing is not an offer.
        Setting::setAlaCarte('both', 0);
        $this->assertFalse(Setting::alaCarteFor('orders'));
    }

    public function test_an_admin_saves_the_mode_and_the_rice_price_and_others_cannot(): void
    {
        $payload = ['session_start_time' => '09:00', 'session_end_time' => '17:00', 'ala_carte_mode' => 'both', 'ala_carte_rice_price' => '25.50'];

        $this->actingAs($this->userWith('view settings'))->putJson(route('settings.update'), $payload)->assertForbidden();

        $this->actingAs($this->superAdmin())->putJson(route('settings.update'), $payload)->assertOk();

        $this->assertSame('both', Setting::alaCarteMode());
        $this->assertSame(25.5, Setting::ricePrice());

        $this->actingAs($this->userWith('view settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="ala_carte_mode"', false)
            ->assertSee('value="25.50"', false);
    }

    public function test_the_settings_refuse_a_bad_mode_a_zero_price_while_on_and_cents_beyond_two(): void
    {
        $admin = $this->superAdmin();
        $base = ['session_start_time' => '09:00', 'session_end_time' => '17:00'];

        $this->actingAs($admin)->putJson(route('settings.update'), $base + ['ala_carte_mode' => 'sometimes', 'ala_carte_rice_price' => 20])
            ->assertStatus(422)->assertJsonValidationErrors('ala_carte_mode');

        $this->actingAs($admin)->putJson(route('settings.update'), $base + ['ala_carte_mode' => 'orders', 'ala_carte_rice_price' => 0])
            ->assertStatus(422)->assertJsonValidationErrors('ala_carte_rice_price');

        $this->actingAs($admin)->putJson(route('settings.update'), $base + ['ala_carte_mode' => 'orders', 'ala_carte_rice_price' => 20.123])
            ->assertStatus(422)->assertJsonValidationErrors('ala_carte_rice_price');

        $this->actingAs($admin)->putJson(route('settings.update'), $base + ['ala_carte_mode' => 'orders', 'ala_carte_rice_price' => -5])
            ->assertStatus(422)->assertJsonValidationErrors('ala_carte_rice_price');

        // Nothing of the refused saves stuck; "off" may keep a price of 0.
        $this->assertSame('orders', Setting::alaCarteMode());
        $this->assertSame(20.0, Setting::ricePrice());

        $this->actingAs($admin)->putJson(route('settings.update'), $base + ['ala_carte_mode' => 'off', 'ala_carte_rice_price' => 0])->assertOk();
        $this->assertSame('off', Setting::alaCarteMode());
    }

    public function test_a_save_that_leaves_the_fields_out_changes_nothing(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson(route('settings.update'), ['session_start_time' => '09:00', 'session_end_time' => '17:00'])->assertOk();

        $this->assertSame('orders', Setting::alaCarteMode());
        $this->assertSame(20.0, Setting::ricePrice());
    }

    /* -------- the dish flag -------- */

    public function test_a_dish_is_flagged_as_coming_with_rice_on_create_and_update(): void
    {
        $admin = $this->superAdmin();
        $payload = [
            'dish_types' => [DishType::factory()->create()->dish_type_id],
            'dish_name' => 'Tapsilog',
            'price' => '90',
            'is_active' => '1',
            'includes_rice' => '1',
        ];

        $this->actingAs($admin)->post(route('dishes.store'), $payload)->assertOk();
        $dish = Dish::query()->where('dish_name', 'Tapsilog')->first();
        $this->assertTrue((bool) $dish->includes_rice);

        $this->actingAs($admin)->getJson(route('dishes.edit', $dish->dish_id))->assertOk()->assertJsonPath('includes_rice', 1);

        $this->actingAs($admin)->put(route('dishes.update', $dish->dish_id), ['includes_rice' => '0'] + $payload)->assertOk();
        $this->assertFalse((bool) Dish::query()->find($dish->dish_id)->includes_rice);
    }

    public function test_a_new_dish_does_not_come_with_rice_unless_ticked(): void
    {
        $this->actingAs($this->superAdmin())->post(route('dishes.store'), [
            'dish_types' => [DishType::factory()->create()->dish_type_id],
            'dish_name' => 'Halo-halo',
            'price' => '60',
            'is_active' => '1',
        ])->assertOk();

        $this->assertFalse((bool) Dish::query()->where('dish_name', 'Halo-halo')->value('includes_rice'));
    }

    /* -------- the menu -------- */

    public function test_the_menu_tells_the_page_which_dishes_can_go_without_rice(): void
    {
        $plain = SessionDish::factory()->create([
            'session_id' => $this->session->session_id,
            'dish_id' => Dish::factory()->create(['price' => 100])->dish_id,
        ]);

        $html = $this->actingAs($this->customer())->get(route('menu.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ala-carte=', $html);
        $this->assertMatchesRegularExpression('/data-ala-carte=\'\{"enabled":true,"rice_price":20,"dishes":\[' . $this->line->session_dish_id . '\]\}\'/', $html);
        $this->assertStringNotContainsString('"dishes":[' . $plain->session_dish_id, $html);
    }

    public function test_a_dish_that_costs_no_more_than_the_rice_is_not_offered_without_it(): void
    {
        DB::table('dishes')->where('dish_id', $this->line->dish_id)->update(['price' => 20]);

        $this->actingAs($this->customer())->get(route('menu.index'))->assertOk()->assertSee('"dishes":[]', false);
    }

    public function test_the_stock_poll_carries_the_switch_and_the_rice_price(): void
    {
        $this->actingAs($this->customer())->getJson(route('menu.stock', ['session_id' => $this->session->session_id]))
            ->assertOk()->assertJsonPath('ala_carte.enabled', true)->assertJsonPath('ala_carte.rice_price', 20);

        Setting::setAlaCarte('off', 20);

        $this->actingAs($this->customer())->getJson(route('menu.stock', ['session_id' => $this->session->session_id]))
            ->assertJsonPath('ala_carte.enabled', false);
    }

    /* -------- placing an order -------- */

    public function test_plates_without_rice_are_their_own_cheaper_line(): void
    {
        $this->place(['ala_carte' => [$this->line->session_dish_id => 1], 'expected_rice_price' => 20])
            ->assertOk()
            // 2 x 100 + 1 x (100 - 20)
            ->assertJsonPath('total_amount', 280);

        $orderId = $this->orderId();
        $lines = OrderItem::ofOrder($orderId);

        $this->assertCount(2, $lines);
        $this->assertSame(['Adobo', 2, 100.0, 200.0, false], [$lines[0]['dish_name'], $lines[0]['quantity'], $lines[0]['unit_price'], $lines[0]['sub_total'], $lines[0]['no_rice']]);
        $this->assertSame(['Adobo (no rice)', 1, 80.0, 80.0, true], [$lines[1]['dish_name'], $lines[1]['quantity'], $lines[1]['unit_price'], $lines[1]['sub_total'], $lines[1]['no_rice']]);

        $this->assertEquals(280, DB::table('orders')->where('order_id', $orderId)->value('total_amount'));
        $this->assertEquals(280, DB::table('payments')->where('order_id', $orderId)->value('amount'));

        // Every plate holds stock, with or without rice.
        $this->assertSame(7, $this->stock());
    }

    public function test_every_plate_without_rice_and_no_ala_carte_at_all_still_work(): void
    {
        $this->place(['ala_carte' => [$this->line->session_dish_id => 3]])->assertOk()->assertJsonPath('total_amount', 240);
        $this->assertCount(1, OrderItem::ofOrder($this->orderId()));

        // Nothing asked: a plain order, exactly as before.
        $this->place([])->assertOk()->assertJsonPath('total_amount', 300);
        $this->assertFalse(OrderItem::ofOrder($this->orderId())[0]['no_rice']);
    }

    public function test_a_discount_takes_its_percent_of_the_price_without_rice(): void
    {
        Setting::setDiscountMode('orders');
        $discount = Discount::factory()->create(['discount_name' => 'Staff', 'percent' => 10, 'requires_proof' => false]);

        // 2 x 100 + 1 x 80 = 280; 10% off = 252
        $this->place(['ala_carte' => [$this->line->session_dish_id => 1], 'discount_id' => $discount->discount_id])
            ->assertOk()->assertJsonPath('subtotal_amount', 280)->assertJsonPath('total_amount', 252);

        $this->assertEquals(280, DB::table('orders')->where('order_id', $this->orderId())->value('subtotal_amount'));
    }

    public function test_it_is_refused_while_the_switch_is_off_and_nothing_is_written(): void
    {
        Setting::setAlaCarte('off', 20);

        $this->place(['ala_carte' => [$this->line->session_dish_id => 1]])
            ->assertStatus(422)
            ->assertJsonPath('ala_carte.enabled', false)
            ->assertJsonStructure(['stock', 'prices', 'ala_carte' => ['enabled', 'rice_price', 'dishes']]);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->stock());
    }

    public function test_it_is_refused_for_regular_orders_when_only_pre_orders_have_it(): void
    {
        Setting::setAlaCarte('pre_orders', 20);

        $this->place(['ala_carte' => [$this->line->session_dish_id => 1]])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_dish_that_does_not_come_with_rice_is_refused(): void
    {
        DB::table('dishes')->where('dish_id', $this->line->dish_id)->update(['includes_rice' => false]);

        $this->place(['ala_carte' => [$this->line->session_dish_id => 1]])
            ->assertStatus(422)
            ->assertJsonPath('ala_carte.dishes', [])
            ->assertJsonFragment(['message' => 'Adobo cannot be ordered without rice. Please review your cart.']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_more_plates_without_rice_than_ordered_are_refused(): void
    {
        $this->place(['ala_carte' => [$this->line->session_dish_id => 4]], 3)->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_dish_that_costs_no_more_than_the_rice_cannot_go_without_it(): void
    {
        DB::table('dishes')->where('dish_id', $this->line->dish_id)->update(['price' => 20]);

        $this->place(['ala_carte' => [$this->line->session_dish_id => 1]])->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_rice_price_that_moved_since_the_cart_was_drawn_is_refused_with_the_new_one(): void
    {
        Setting::setAlaCarte('orders', 30);

        $this->place(['ala_carte' => [$this->line->session_dish_id => 1], 'expected_rice_price' => 20])
            ->assertStatus(422)
            ->assertJsonPath('ala_carte.rice_price', 30)
            ->assertJsonPath('ala_carte.dishes', [$this->line->session_dish_id]);

        $this->assertDatabaseCount('orders', 0);

        // With the price the customer now sees, it goes through at the new price.
        $this->place(['ala_carte' => [$this->line->session_dish_id => 1], 'expected_rice_price' => 30])
            ->assertOk()->assertJsonPath('total_amount', 270);
    }

    public function test_a_posted_price_cannot_be_trusted_the_server_prices_it(): void
    {
        // The client can only say HOW MANY plates; there is no field for a price.
        $this->place(['ala_carte' => [$this->line->session_dish_id => 1], 'unit_price' => 1, 'total_amount' => 1])
            ->assertOk()->assertJsonPath('total_amount', 280);
    }

    public function test_changing_the_rice_price_later_never_rewrites_a_placed_order(): void
    {
        $this->place(['ala_carte' => [$this->line->session_dish_id => 1]])->assertOk();
        $orderId = $this->orderId();

        Setting::setAlaCarte('orders', 50);
        DB::table('dishes')->where('dish_id', $this->line->dish_id)->update(['price' => 120]);

        $lines = OrderItem::ofOrder($orderId);

        $this->assertSame(80.0, $lines[1]['unit_price']);
        $this->assertEquals(280, DB::table('orders')->where('order_id', $orderId)->value('total_amount'));
    }

    public function test_cancelling_gives_back_the_plates_of_both_lines(): void
    {
        $this->place(['ala_carte' => [$this->line->session_dish_id => 1]])->assertOk();
        $this->assertSame(7, $this->stock());

        $this->assertSame([$this->line->session_dish_id => 3], OrderItem::takenByOrder($this->orderId()));

        $this->actingAs($this->superAdmin())->postJson(route('orders.cancel', $this->orderId()))->assertOk();

        $this->assertSame(10, $this->stock());
    }

    public function test_the_order_feeds_print_the_line_as_no_rice(): void
    {
        $this->place(['ala_carte' => [$this->line->session_dish_id => 1]])->assertOk();

        $items = OrderItem::itemsByOrder([$this->orderId()])->get($this->orderId());

        $this->assertSame(['Adobo', 'Adobo (no rice)'], array_column($items, 'dish_name'));
    }

    /* -------- pre-orders -------- */

    private function preOrderLine(): array
    {
        $session = PreOrderSession::factory()->create(['min_quantity' => 10]);
        $line = PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $session->pre_order_session_id,
            'dish_id' => Dish::factory()->withRice()->create(['dish_name' => 'Pancit', 'price' => 100])->dish_id,
        ]);

        return [$session, $line];
    }

    private function placePreOrder(PreOrderSession $session, PreOrderSessionDish $line, array $extra = [], int $quantity = 10)
    {
        return $this->actingAs($this->userWith('view pre-order-menu', 'create pre-orders', 'view my pre-orders'))
            ->postJson(route('pre-orders.store'), $extra + [
                'pre_order_session_id' => $session->pre_order_session_id,
                'payment_method_id' => $this->cash->method_id,
                'items' => [$line->pre_order_session_dish_id => $quantity],
            ]);
    }

    public function test_a_pre_order_can_have_plates_without_rice_when_the_mode_covers_it(): void
    {
        Setting::setAlaCarte('both', 20);
        [$session, $line] = $this->preOrderLine();

        // 6 x 100 + 4 x 80 = 920; all 10 plates still count toward the minimum.
        $this->placePreOrder($session, $line, ['ala_carte' => [$line->pre_order_session_dish_id => 4], 'expected_rice_price' => 20])
            ->assertOk()->assertJsonPath('total_amount', 920)->assertJsonPath('plates', 10);

        $items = PreOrderItem::itemsByOrder([(int) DB::table('pre_orders')->value('pre_order_id')])->first();

        $this->assertSame(['Pancit', 6, 100.0, false], [$items[0]['dish_name'], $items[0]['quantity'], $items[0]['unit_price'], $items[0]['no_rice']]);
        $this->assertSame(['Pancit (no rice)', 4, 80.0, true], [$items[1]['dish_name'], $items[1]['quantity'], $items[1]['unit_price'], $items[1]['no_rice']]);
    }

    public function test_a_pre_order_is_refused_when_the_mode_is_regular_orders_only(): void
    {
        Setting::setAlaCarte('orders', 20);
        [$session, $line] = $this->preOrderLine();

        $this->placePreOrder($session, $line, ['ala_carte' => [$line->pre_order_session_dish_id => 4]])
            ->assertStatus(422)->assertJsonPath('ala_carte.enabled', false);

        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_a_pre_order_refuses_more_plates_without_rice_than_ordered(): void
    {
        Setting::setAlaCarte('both', 20);
        [$session, $line] = $this->preOrderLine();

        $this->placePreOrder($session, $line, ['ala_carte' => [$line->pre_order_session_dish_id => 11]])->assertStatus(422);
        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_the_pre_order_menu_has_the_rice_row_only_for_dishes_that_come_with_rice(): void
    {
        Setting::setAlaCarte('both', 20);
        [$session] = $this->preOrderLine();
        PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $session->pre_order_session_id,
            'dish_id' => Dish::factory()->create(['price' => 50])->dish_id,
        ]);

        $html = $this->actingAs($this->userWith('view pre-order-menu', 'create pre-orders'))
            ->get(route('pre-order-menu.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-rice-row'));
        $this->assertStringContainsString('"enabled":true', $html);
    }
}
