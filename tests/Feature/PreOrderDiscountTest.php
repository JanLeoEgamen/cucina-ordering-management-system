<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Dish;
use App\Models\PaymentMethod;
use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// A customer picks a discount on a PRE-ORDER (System Configuration -> Discounts: "Pre-orders only" or
// "Both"). The same rules as a regular order (see DiscountCheckoutTest): the server does the math, the
// ID goes to the private disk, a discount that needs one waits for staff.
//
// Staff cannot decide a pre-order's discount yet: the pre-order lifecycle (verify, complete, cancel) is
// not built, so there is no page or route for it. See known-issues.md.
class PreOrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    private PreOrderSession $session;

    private PreOrderSessionDish $line;

    private PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        // 10 plates at 100 = a subtotal of 1,000.
        $this->session = PreOrderSession::factory()->create(['min_quantity' => 10]);
        $this->line = PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $this->session->pre_order_session_id,
            'dish_id' => Dish::factory()->create(['price' => 100])->dish_id,
        ]);
        $this->cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        Setting::setDiscountMode('pre_orders');
    }

    private function customer(): User
    {
        return $this->userWith('view pre-order-menu', 'create pre-orders', 'view my pre-orders');
    }

    private function place(?Discount $discount, array $extra = [])
    {
        $payload = [
            'pre_order_session_id' => $this->session->pre_order_session_id,
            'payment_method_id' => $this->cash->method_id,
            'items' => [$this->line->pre_order_session_dish_id => 10],
        ] + $extra;

        if ($discount) {
            $payload['discount_id'] = $discount->discount_id;
        }

        return $this->actingAs($this->customer())->postJson(route('pre-orders.store'), $payload);
    }

    private function senior(array $override = []): Discount
    {
        return Discount::factory()->create($override + ['discount_name' => 'Senior Citizen', 'percent' => 20]);
    }

    public function test_the_pre_order_menu_offers_discounts_only_for_the_modes_that_cover_pre_orders(): void
    {
        $this->senior();

        $page = fn () => $this->actingAs($this->customer())->get(route('pre-order-menu.index'))->assertOk();

        $page()->assertSee('Senior Citizen');

        foreach (['orders', 'off'] as $mode) {
            Setting::setDiscountMode($mode);
            $page()->assertDontSee('Senior Citizen');
        }

        Setting::setDiscountMode('both');
        $page()->assertSee('Senior Citizen');
    }

    public function test_a_pre_order_with_a_discount_stores_the_math_a_copy_and_a_private_id(): void
    {
        $discount = $this->senior();

        $this->place($discount, ['discount_proof' => UploadedFile::fake()->image('id.jpg'), 'expected_discount_percent' => 20])
            ->assertOk()
            ->assertJsonPath('total_amount', 800)
            ->assertJsonPath('subtotal_amount', 1000)
            ->assertJsonPath('discount_amount', 200)
            ->assertJsonPath('discount_status', 'pending');

        $order = DB::table('pre_orders')->first();

        $this->assertEquals(800, $order->total_amount);
        $this->assertEquals(1000, $order->subtotal_amount);
        $this->assertEquals(200, $order->discount_amount);
        $this->assertSame('Senior Citizen', $order->discount_name);
        $this->assertSame('pending', $order->discount_status);
        $this->assertSame($discount->discount_id, (int) $order->discount_id);

        Storage::disk('local')->assertExists($order->discount_proof);
        Storage::disk('public')->assertMissing($order->discount_proof);
    }

    public function test_a_discount_that_needs_no_proof_is_approved_at_once(): void
    {
        $this->place($this->senior(['requires_proof' => false]))
            ->assertOk()->assertJsonPath('total_amount', 800)->assertJsonPath('discount_status', 'approved');
    }

    public function test_the_proof_is_required_when_the_discount_asks_for_it(): void
    {
        $this->place($this->senior())->assertStatus(422)->assertJsonValidationErrors('discount_proof');

        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_a_discount_that_cannot_be_used_is_refused_with_the_fresh_list(): void
    {
        foreach ([
            Discount::factory()->inactive()->create(),
            Discount::factory()->create(['valid_until' => now()->subDay()->toDateString()]),
            Discount::factory()->create(['deleted_at' => now()]),
        ] as $discount) {
            $this->place($discount, ['discount_proof' => UploadedFile::fake()->image('id.jpg')])
                ->assertStatus(422)
                ->assertJsonPath('discounts', []);
        }

        // Switched off for pre-orders (but on for regular orders): refused too.
        Setting::setDiscountMode('orders');
        $this->place($this->senior(), ['discount_proof' => UploadedFile::fake()->image('id.jpg')])->assertStatus(422);

        $this->assertDatabaseCount('pre_orders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_percent_that_changed_is_refused_with_the_new_one(): void
    {
        $this->place($this->senior(), ['discount_proof' => UploadedFile::fake()->image('id.jpg'), 'expected_discount_percent' => 15])
            ->assertStatus(422)
            ->assertJsonPath('discounts.0.percent', 20);

        $this->assertDatabaseCount('pre_orders', 0);
    }

    public function test_an_order_without_a_discount_is_unchanged(): void
    {
        $this->place(null)->assertOk()->assertJsonPath('total_amount', 1000)->assertJsonPath('discount_status', 'none');

        $this->assertDatabaseHas('pre_orders', ['discount_status' => 'none', 'discount_id' => null]);
    }

    public function test_my_pre_orders_shows_the_discount(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->postJson(route('pre-orders.store'), [
            'pre_order_session_id' => $this->session->pre_order_session_id,
            'payment_method_id' => $this->cash->method_id,
            'items' => [$this->line->pre_order_session_dish_id => 10],
            'discount_id' => $this->senior()->discount_id,
            'discount_proof' => UploadedFile::fake()->image('id.jpg'),
        ])->assertOk();

        $this->actingAs($customer)->get(route('my-pre-orders.index'))
            ->assertOk()
            ->assertSee('Senior Citizen')
            ->assertSee('Staff will check your ID');
    }
}
