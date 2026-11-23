<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// A customer picks a discount at checkout (regular orders), staff approve or reject it. The master
// switch is System Configuration -> Discounts (Setting::discountMode()).
class DiscountCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private OrderSession $session;

    private SessionDish $line;

    private PaymentMethod $cash;

    private PaymentMethod $gcash;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->session = OrderSession::factory()->create();
        // 2 plates at 100 = a subtotal of 200.
        $this->line = SessionDish::factory()->create([
            'session_id' => $this->session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Adobo', 'price' => 100])->dish_id,
            'available_stock' => 10,
        ]);
        $this->cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $this->gcash = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        Setting::setDiscountMode('orders');
    }

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    // The order payload a customer's checkout sends.
    private function payload(?Discount $discount, PaymentMethod $method = null, array $extra = []): array
    {
        $method ??= $this->cash;

        $payload = [
            'session_id' => $this->session->session_id,
            'payment_method_id' => $method->method_id,
            'items' => [$this->line->session_dish_id => 2],
        ] + $extra;

        if ($discount) {
            $payload['discount_id'] = $discount->discount_id;
        }

        if ($method->method_name !== PaymentMethod::CASH_ON_PICKUP) {
            $payload['proof_of_payment'] = UploadedFile::fake()->image('proof.jpg');
        }

        return $payload;
    }

    private function place(User $customer, ?Discount $discount, PaymentMethod $method = null, array $extra = [])
    {
        return $this->actingAs($customer)->postJson(route('orders.store'), $this->payload($discount, $method, $extra));
    }

    private function orderId(): int
    {
        return (int) DB::table('orders')->orderByDesc('order_id')->value('order_id');
    }

    private function senior(array $override = []): Discount
    {
        return Discount::factory()->create($override + ['discount_name' => 'Senior Citizen', 'percent' => 20]);
    }

    private function id(): UploadedFile
    {
        return UploadedFile::fake()->image('id.jpg');
    }

    /* ---------------------------------------------------------------- the menu */

    public function test_the_menu_offers_discounts_only_while_the_switch_allows_them(): void
    {
        $this->senior();
        Discount::factory()->inactive()->create(['discount_name' => 'Switched off']);

        $page = fn () => $this->actingAs($this->customer())->get(route('menu.index'))->assertOk();

        $page()->assertSee('Senior Citizen')->assertDontSee('Switched off');

        Setting::setDiscountMode('pre_orders');
        $page()->assertDontSee('Senior Citizen');

        Setting::setDiscountMode('off');
        $page()->assertDontSee('Senior Citizen');

        Setting::setDiscountMode('both');
        $page()->assertSee('Senior Citizen');
    }

    public function test_the_discount_is_its_own_checkout_step_only_while_one_is_offered(): void
    {
        $this->senior();

        $page = fn () => $this->actingAs($this->customer())->get(route('menu.index'))->assertOk();

        // On: a panel between Review and Payment, and four steps on the bar.
        $page()->assertSee('data-step-panel="discount"', false)
            ->assertSee('id="discount-options"', false)
            ->assertSee('repeat(4, minmax(0, 1fr))', false);

        // Off: no panel, and the bar counts three steps.
        Setting::setDiscountMode('off');
        $page()->assertDontSee('data-step-panel="discount"', false)
            ->assertSee('repeat(3, minmax(0, 1fr))', false);
    }

    /* ---------------------------------------------------------------- placing */

    public function test_an_order_with_a_discount_stores_the_math_a_copy_and_a_private_id(): void
    {
        $discount = $this->senior();
        $customer = $this->customer();

        $this->place($customer, $discount, $this->gcash, ['discount_proof' => $this->id(), 'expected_discount_percent' => 20])
            ->assertOk()
            ->assertJsonPath('total_amount', 160)
            ->assertJsonPath('subtotal_amount', 200)
            ->assertJsonPath('discount_amount', 40)
            ->assertJsonPath('discount_status', 'pending');

        $order = DB::table('orders')->where('order_id', $this->orderId())->first();

        $this->assertEquals(160, $order->total_amount);
        $this->assertEquals(200, $order->subtotal_amount);
        $this->assertEquals(40, $order->discount_amount);
        $this->assertEquals(20, $order->discount_percent);
        $this->assertSame('Senior Citizen', $order->discount_name);
        $this->assertSame('pending', $order->discount_status);
        $this->assertSame($discount->discount_id, (int) $order->discount_id);

        // What is to be paid is the discounted total.
        $this->assertEquals(160, DB::table('payments')->where('order_id', $order->order_id)->value('amount'));

        // The ID is on the private disk, never on the public one.
        Storage::disk('local')->assertExists($order->discount_proof);
        $this->assertSame([], Storage::disk('public')->files('discount-proofs'));
    }

    public function test_an_order_without_a_discount_is_unchanged(): void
    {
        $this->place($this->customer(), null)->assertOk()->assertJsonPath('total_amount', 200)->assertJsonPath('discount_status', 'none');

        $order = DB::table('orders')->first();

        $this->assertSame('none', $order->discount_status);
        $this->assertNull($order->discount_id);
        $this->assertEquals(0, $order->discount_amount);
    }

    public function test_a_discount_that_needs_no_proof_is_approved_at_once(): void
    {
        $discount = $this->senior(['requires_proof' => false]);

        $this->place($this->customer(), $discount)->assertOk()->assertJsonPath('total_amount', 160)->assertJsonPath('discount_status', 'approved');

        $this->assertNull(DB::table('orders')->value('discount_proof'));
    }

    public function test_the_proof_is_required_when_the_discount_asks_for_it(): void
    {
        $this->place($this->customer(), $this->senior())
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_proof');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, (int) SessionDish::query()->find($this->line->session_dish_id)->available_stock);
    }

    public function test_the_id_must_be_a_raster_picture(): void
    {
        $discount = $this->senior();

        foreach ([
            UploadedFile::fake()->create('id.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->create('id.pdf', 5, 'application/pdf'),
            UploadedFile::fake()->image('big.jpg')->size(3000),
        ] as $file) {
            $this->place($this->customer(), $discount, extra: ['discount_proof' => $file])
                ->assertStatus(422)->assertJsonValidationErrors('discount_proof');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_discount_that_cannot_be_used_is_refused_and_nothing_is_written(): void
    {
        $customer = $this->customer();

        foreach ([
            Discount::factory()->inactive()->create(),
            Discount::factory()->create(['valid_from' => now()->addDay()->toDateString()]),
            Discount::factory()->create(['valid_until' => now()->subDay()->toDateString()]),
            Discount::factory()->create(['deleted_at' => now()]),
        ] as $discount) {
            $this->place($customer, $discount, extra: ['discount_proof' => $this->id()])
                ->assertStatus(422)
                ->assertJsonPath('discounts', []);
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, (int) SessionDish::query()->find($this->line->session_dish_id)->available_stock);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_unknown_discount_is_refused(): void
    {
        $this->actingAs($this->customer())
            ->postJson(route('orders.store'), $this->payload(null, null, ['discount_id' => 999999]))
            ->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_nothing_is_taken_off_while_discounts_are_switched_off_for_orders(): void
    {
        $discount = $this->senior();

        foreach (['off', 'pre_orders'] as $mode) {
            Setting::setDiscountMode($mode);

            $this->place($this->customer(), $discount, extra: ['discount_proof' => $this->id()])->assertStatus(422);
        }

        $this->assertDatabaseCount('orders', 0);

        Setting::setDiscountMode('both');
        $this->place($this->customer(), $discount, extra: ['discount_proof' => $this->id()])->assertOk();
    }

    public function test_a_percent_that_changed_since_the_cart_was_drawn_is_refused_with_the_new_one(): void
    {
        $discount = $this->senior();

        $this->place($this->customer(), $discount, extra: ['discount_proof' => $this->id(), 'expected_discount_percent' => 10])
            ->assertStatus(422)
            ->assertJsonPath('discounts.0.percent', 20)
            ->assertJsonPath('discounts.0.name', 'Senior Citizen');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_hundred_percent_makes_the_order_free(): void
    {
        $discount = $this->senior(['percent' => 100, 'requires_proof' => false]);

        $this->place($this->customer(), $discount)->assertOk()->assertJsonPath('total_amount', 0);
    }

    public function test_an_order_keeps_its_copy_when_the_discount_changes_or_goes(): void
    {
        $discount = $this->senior(['requires_proof' => false]);
        $this->place($this->customer(), $discount)->assertOk();
        $id = $this->orderId();

        $admin = $this->superAdmin();
        $this->actingAs($admin)->putJson(route('discounts.update', $discount->discount_id), [
            'discount_name' => 'Renamed', 'percent' => 5, 'requires_proof' => 1, 'is_active' => 1,
        ])->assertOk();
        $this->actingAs($admin)->deleteJson(route('discounts.destroy', $discount->discount_id))->assertOk();

        $order = DB::table('orders')->where('order_id', $id)->first();

        $this->assertSame('Senior Citizen', $order->discount_name);
        $this->assertEquals(20, $order->discount_percent);
        $this->assertEquals(160, $order->total_amount);
    }

    /* ---------------------------------------------------------------- the customer's view */

    public function test_my_orders_shows_the_discount_without_any_staff_field(): void
    {
        $customer = $this->customer();
        $this->place($customer, $this->senior(), $this->gcash, ['discount_proof' => $this->id()])->assertOk();

        $this->actingAs($customer)->get(route('my-orders.index'))
            ->assertOk()
            ->assertSee('Senior Citizen')
            ->assertDontSee('discount-proofs/', false)
            ->assertDontSee('/discount-proof', false);
    }

    /* ---------------------------------------------------------------- staff decide */

    private function pendingOrder(PaymentMethod $method = null): array
    {
        $customer = $this->customer();
        $this->place($customer, $this->senior(), $method ?? $this->gcash, ['discount_proof' => $this->id()])->assertOk();

        return [$customer, $this->orderId()];
    }

    public function test_approving_keeps_the_total_stamps_the_reviewer_and_tells_the_customer(): void
    {
        [$customer, $id] = $this->pendingOrder();
        $reviewer = $this->userWith('review discounts');

        $this->actingAs($reviewer)->postJson(route('orders.discount.approve', $id))->assertOk()->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('orders', [
            'order_id' => $id,
            'discount_status' => 'approved',
            'discount_decided_by' => $reviewer->id,
            'total_amount' => 160,
        ]);
        $this->assertNotNull(DB::table('orders')->where('order_id', $id)->value('discount_decided_at'));

        $note = DB::table('user_notifications')->where('user_id', $customer->id)->where('type', 'discount_approved')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Senior Citizen', $note->body);

        // Decided once: the second press finds it done.
        $this->actingAs($reviewer)->postJson(route('orders.discount.approve', $id))->assertStatus(422);
        $this->actingAs($reviewer)->postJson(route('orders.discount.reject', $id), ['reason' => 'No'])->assertStatus(422);
    }

    public function test_rejecting_puts_the_full_price_back_and_sends_a_paid_in_advance_payment_back(): void
    {
        [$customer, $id] = $this->pendingOrder($this->gcash);
        $reviewer = $this->userWith('review discounts');

        $this->actingAs($reviewer)->postJson(route('orders.discount.reject', $id), ['reason' => 'The ID is blurry'])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'order_id' => $id,
            'discount_status' => 'rejected',
            'discount_amount' => 0,
            'total_amount' => 200,
            'discount_reject_reason' => 'The ID is blurry',
            'payment_status' => 'rejected',
        ]);

        // The proof was for 160: it goes back, for 200, with the reason, like any rejected payment.
        $payment = DB::table('payments')->where('order_id', $id)->first();
        $this->assertEquals(200, $payment->amount);
        $this->assertSame('rejected', $payment->payment_status);
        $this->assertStringContainsString('₱200.00', $payment->rejection_reason);

        // The plates stay held: the customer can fix the payment (resend a proof or switch to cash).
        $this->assertSame(8, (int) SessionDish::query()->find($this->line->session_dish_id)->available_stock);
        $this->actingAs($customer)->postJson(route('my-orders.payment.cash', $id))->assertOk();

        $note = DB::table('user_notifications')->where('user_id', $customer->id)->where('type', 'discount_rejected')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('The ID is blurry', $note->body);
        $this->assertStringContainsString('₱200.00', $note->body);
    }

    public function test_rejecting_a_cash_order_only_moves_the_total(): void
    {
        [, $id] = $this->pendingOrder($this->cash);

        $this->actingAs($this->userWith('review discounts'))
            ->postJson(route('orders.discount.reject', $id), ['reason' => 'Not eligible'])->assertOk();

        $payment = DB::table('payments')->where('order_id', $id)->first();

        $this->assertEquals(200, $payment->amount);
        $this->assertSame('pending', $payment->payment_status);
        $this->assertDatabaseHas('orders', ['order_id' => $id, 'total_amount' => 200, 'payment_status' => 'pending']);
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        [, $id] = $this->pendingOrder();

        $this->actingAs($this->userWith('review discounts'))
            ->postJson(route('orders.discount.reject', $id), ['reason' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->assertDatabaseHas('orders', ['order_id' => $id, 'discount_status' => 'pending']);
    }

    public function test_a_pending_discount_blocks_verifying_completing_and_announcing(): void
    {
        [, $id] = $this->pendingOrder($this->gcash);
        $staff = $this->userWith('verify payments', 'complete orders', 'reject payments');

        $this->actingAs($staff)->postJson(route('payments.verify', $id))->assertStatus(422);
        $this->actingAs($staff)->postJson(route('orders.complete', $id))->assertStatus(422);
        $this->actingAs($staff)->postJson(route('orders.ready', $id))->assertStatus(422);

        $this->assertDatabaseHas('payments', ['order_id' => $id, 'payment_status' => 'pending']);
        $this->assertDatabaseHas('orders', ['order_id' => $id, 'order_status' => 'pending', 'ready_at' => null]);

        // Decided: the normal flow goes on.
        $this->actingAs($this->userWith('review discounts'))->postJson(route('orders.discount.approve', $id))->assertOk();

        $this->actingAs($staff)->postJson(route('payments.verify', $id))->assertOk();
        $this->actingAs($staff)->postJson(route('orders.complete', $id))->assertOk();
    }

    public function test_a_cash_order_with_a_pending_discount_cannot_be_completed_either(): void
    {
        [, $id] = $this->pendingOrder($this->cash);

        $this->actingAs($this->userWith('complete orders'))->postJson(route('orders.complete', $id))->assertStatus(422);

        $this->actingAs($this->userWith('review discounts'))->postJson(route('orders.discount.approve', $id))->assertOk();
        $this->actingAs($this->userWith('complete orders'))->postJson(route('orders.complete', $id))->assertOk();
    }

    public function test_the_order_rows_only_offer_the_decisions_that_apply(): void
    {
        [, $id] = $this->pendingOrder($this->gcash);
        $reviewer = $this->userWith('view orders', 'review discounts', 'verify payments', 'complete orders');

        $row = fn () => collect($this->actingAs($reviewer)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('orders.index'))->assertOk()->json('data'))->firstWhere('order_id', $id);

        $pending = $row();

        $this->assertNotNull($pending['discount_approve_url']);
        $this->assertNotNull($pending['discount_reject_url']);
        $this->assertNull($pending['verify_url']);
        $this->assertNull($pending['complete_url']);
        $this->assertNotNull($pending['discount_proof_url']);
        // The private path never leaves the server.
        $this->assertArrayNotHasKey('discount_proof', $pending);

        $this->actingAs($reviewer)->postJson(route('orders.discount.approve', $id))->assertOk();

        $decided = $row();

        $this->assertNull($decided['discount_approve_url']);
        $this->assertNotNull($decided['verify_url']);

        // Without the permission there is no button at all.
        $viewer = $this->userWith('view orders');
        $viewerRow = collect($this->actingAs($viewer)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('orders.index'))->json('data'))->firstWhere('order_id', $id);

        $this->assertNull($viewerRow['discount_approve_url']);
    }

    public function test_a_cancelled_order_cannot_have_its_discount_decided(): void
    {
        [, $id] = $this->pendingOrder($this->cash);

        $this->actingAs($this->userWith('cancel orders'))->postJson(route('orders.cancel', $id))->assertOk();

        $this->actingAs($this->userWith('review discounts'))->postJson(route('orders.discount.approve', $id))->assertStatus(422);
        $this->assertDatabaseHas('orders', ['order_id' => $id, 'discount_status' => 'pending']);
    }

    public function test_deciding_needs_the_review_permission_and_a_real_order(): void
    {
        [, $id] = $this->pendingOrder();

        foreach (['approve', 'reject'] as $action) {
            $this->actingAs($this->userWith('view orders', 'complete orders', 'verify payments'))
                ->postJson(route('orders.discount.' . $action, $id), ['reason' => 'x'])->assertForbidden();
        }

        $this->actingAs($this->customer())->postJson(route('orders.discount.approve', $id))->assertForbidden();

        $reviewer = $this->userWith('review discounts');
        $this->actingAs($reviewer)->postJson(route('orders.discount.approve', 999999))->assertNotFound();
        $this->assertDatabaseHas('orders', ['order_id' => $id, 'discount_status' => 'pending']);
    }

    /* ---------------------------------------------------------------- a new ID after a rejection */

    // An order whose discount staff rejected (the discounted payment sent back when it was paid in advance).
    private function rejectedOrder(PaymentMethod $method = null): array
    {
        [$customer, $id] = $this->pendingOrder($method);

        $this->actingAs($this->userWith('review discounts'))
            ->postJson(route('orders.discount.reject', $id), ['reason' => 'The ID is blurry'])->assertOk();

        return [$customer, $id];
    }

    public function test_a_rejected_discount_can_be_tried_again_with_a_new_id(): void
    {
        [$customer, $id] = $this->rejectedOrder($this->cash);
        $oldPath = DB::table('orders')->where('order_id', $id)->value('discount_proof');

        $this->actingAs($customer)
            ->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])
            ->assertOk()->assertJsonPath('status', 'success');

        $order = DB::table('orders')->where('order_id', $id)->first();

        // Waiting for staff again, at the discounted price, with the old decision cleared and the new picture kept.
        $this->assertSame('pending', $order->discount_status);
        $this->assertEquals(160, $order->total_amount);
        $this->assertEquals(40, $order->discount_amount);
        $this->assertNull($order->discount_reject_reason);
        $this->assertNull($order->discount_decided_by);
        $this->assertNotSame($oldPath, $order->discount_proof);
        Storage::disk('local')->assertExists($order->discount_proof);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertEquals(160, DB::table('payments')->where('order_id', $id)->value('amount'));

        // And staff can decide it again.
        $this->actingAs($this->userWith('review discounts'))->postJson(route('orders.discount.approve', $id))->assertOk();
        $this->assertDatabaseHas('orders', ['order_id' => $id, 'discount_status' => 'approved', 'total_amount' => 160]);
    }

    public function test_a_payment_that_was_waiting_at_the_full_price_is_sent_back_when_the_id_is_sent_again(): void
    {
        [$customer, $id] = $this->rejectedOrder($this->gcash);

        // The customer already sent a new proof for the full price: it is pending again.
        $this->actingAs($customer)->postJson(route('my-orders.payment.proof', $id), ['proof_of_payment' => UploadedFile::fake()->image('p.jpg')])->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'payment_status' => 'pending', 'amount' => 200]);

        $this->actingAs($customer)
            ->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertOk();

        $payment = DB::table('payments')->where('order_id', $id)->first();

        $this->assertEquals(160, $payment->amount);
        $this->assertSame('rejected', $payment->payment_status);
        $this->assertStringContainsString('₱160.00', $payment->rejection_reason);
        $this->assertDatabaseHas('orders', ['order_id' => $id, 'payment_status' => 'rejected']);
        // The plates stay held.
        $this->assertSame(8, (int) SessionDish::query()->find($this->line->session_dish_id)->available_stock);
    }

    public function test_sending_the_id_again_is_refused_when_it_does_not_apply(): void
    {
        [$customer, $id] = $this->rejectedOrder($this->cash);
        $send = fn (User $user = null) => $this->actingAs($user ?? $customer)
            ->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()]);

        // Someone else's order answers "not found".
        $send($this->customer())->assertNotFound();

        // The ID is required and must be a raster picture.
        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), [])->assertStatus(422)->assertJsonValidationErrors('discount_proof');
        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => UploadedFile::fake()->create('id.svg', 5, 'image/svg+xml')])
            ->assertStatus(422)->assertJsonValidationErrors('discount_proof');

        // Discounts switched off: no new try.
        Setting::setDiscountMode('off');
        $send()->assertStatus(422);
        Setting::setDiscountMode('orders');

        // A payment verified at the full price: the discount can no longer be applied.
        $this->actingAs($this->userWith('complete orders'))->postJson(route('orders.complete', $id))->assertOk();
        $send()->assertStatus(422);

        $this->assertDatabaseHas('orders', ['order_id' => $id, 'discount_status' => 'rejected', 'total_amount' => 200]);
    }

    public function test_only_a_rejected_discount_can_be_sent_again_and_only_once_it_is_pending_again(): void
    {
        [$customer, $id] = $this->pendingOrder($this->cash);

        // Pending (or approved): nothing to try again.
        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertStatus(422);

        $this->actingAs($this->userWith('review discounts'))->postJson(route('orders.discount.reject', $id), ['reason' => 'No'])->assertOk();
        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertOk();

        // A double tap: it is pending again, so the second one finds nothing to try again, and no file is left over.
        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertStatus(422);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_cancelled_order_cannot_have_its_id_sent_again(): void
    {
        [$customer, $id] = $this->rejectedOrder($this->cash);
        $this->actingAs($this->userWith('cancel orders'))->postJson(route('orders.cancel', $id))->assertOk();

        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertStatus(422);
    }

    public function test_the_customer_needs_create_orders_and_staff_are_told(): void
    {
        [$customer, $id] = $this->rejectedOrder($this->cash);
        $watcher = $this->userWith('view orders');
        Setting::setNotificationWatchers([$watcher->id]);

        $this->actingAs($this->userWith('view my orders'))
            ->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertForbidden();

        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertOk();

        // Staff-only event: the watcher is told, the customer (who just did it) is not.
        $this->assertTrue(DB::table('user_notifications')->where('user_id', $watcher->id)->where('type', 'discount_resent')->exists());
        $this->assertFalse(DB::table('user_notifications')->where('user_id', $customer->id)->where('type', 'discount_resent')->exists());
    }

    public function test_my_orders_offers_the_new_id_form_only_while_it_applies(): void
    {
        [$customer, $id] = $this->rejectedOrder($this->cash);
        $page = fn () => $this->actingAs($customer)->get(route('my-orders.index'))->assertOk();

        $page()->assertSee('data-fix-discount', false)->assertSee(route('my-orders.discount.proof', $id, false), false);

        Setting::setDiscountMode('off');
        $page()->assertDontSee('data-fix-discount', false);
        Setting::setDiscountMode('orders');

        $this->actingAs($customer)->postJson(route('my-orders.discount.proof', $id), ['discount_proof' => $this->id()])->assertOk();
        $page()->assertDontSee('data-fix-discount', false);
    }

    /* ---------------------------------------------------------------- the private ID */

    public function test_the_id_is_served_to_its_customer_and_to_order_staff_only(): void
    {
        [$customer, $id] = $this->pendingOrder();

        $this->actingAs($customer)->get(route('orders.discount.proof', $id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->userWith('view orders'))->get(route('orders.discount.proof', $id))->assertOk();
        $this->actingAs($this->userWith('review discounts'))->get(route('orders.discount.proof', $id))->assertOk();

        // Another customer, someone with no relevant permission, a missing order: all "not found".
        $this->actingAs($this->customer())->get(route('orders.discount.proof', $id))->assertNotFound();
        $this->actingAs($this->userWith('view payments'))->get(route('orders.discount.proof', $id))->assertNotFound();
        $this->actingAs($this->userWith('view orders'))->get(route('orders.discount.proof', 999999))->assertNotFound();
    }

    public function test_an_order_with_no_id_has_nothing_to_serve(): void
    {
        $this->place($this->customer(), null)->assertOk();

        $this->actingAs($this->userWith('view orders'))->get(route('orders.discount.proof', $this->orderId()))->assertNotFound();
    }

    public function test_the_id_is_never_a_public_file(): void
    {
        [, $id] = $this->pendingOrder();
        $path = DB::table('orders')->where('order_id', $id)->value('discount_proof');

        Storage::disk('public')->assertMissing($path);
        $this->assertStringStartsWith('discount-proofs/', $path);
    }
}
