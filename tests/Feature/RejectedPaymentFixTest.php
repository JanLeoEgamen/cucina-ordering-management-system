<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// A customer whose payment was rejected can send a new proof or pay with cash on
// pickup. The order keeps its place and its reserved plates meanwhile.
class RejectedPaymentFixTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    /**
     * A pending order of 3 plates (7 left, 3 reserved) whose GCash payment staff rejected.
     */
    private function rejectedOrder(User $customer, string $method = 'GCash', string $orderStatus = 'pending'): Order
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create(['session_id' => $session->session_id, 'available_stock' => 7, 'reserved_stock' => 3]);

        $order = Order::factory()->create([
            'user_id' => $customer->id,
            'session_id' => $session->session_id,
            'order_status' => $orderStatus,
            'payment_status' => 'rejected',
        ]);

        OrderItem::factory()->create(['order_id' => $order->order_id, 'session_dish_id' => $line->session_dish_id, 'quantity' => 3]);

        Payment::factory()->create([
            'order_id' => $order->order_id,
            'payment_method_id' => PaymentMethod::factory()->create(['method_name' => $method])->method_id,
            'payment_status' => 'rejected',
            'rejection_reason' => 'Screenshot is unreadable.',
            'reference_number' => 'REF-OLD',
            'proof_of_payment' => 'payments/old-proof.jpg',
            'verified_by' => $this->superAdmin()->id,
            'verified_at' => now(),
        ]);

        return $order;
    }

    private function payment(Order $order): object
    {
        return DB::table('payments')->where('order_id', $order->order_id)->first();
    }

    private function proof(): array
    {
        return ['proof_of_payment' => UploadedFile::fake()->image('new-proof.jpg')];
    }

    /* ---- sending a new proof ------------------------------------------- */

    public function test_a_new_proof_puts_the_payment_back_to_pending(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('payments/old-proof.jpg', 'old');
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);
        $this->actingAs($customer);

        $this->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof() + ['reference_number' => 'REF-NEW'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $payment = $this->payment($order);

        $this->assertSame('pending', $payment->payment_status);
        $this->assertSame('REF-NEW', $payment->reference_number);
        $this->assertNull($payment->rejection_reason);
        $this->assertNull($payment->verified_by);
        $this->assertNull($payment->verified_at);
        $this->assertNotSame('payments/old-proof.jpg', $payment->proof_of_payment);
        Storage::disk('public')->assertExists($payment->proof_of_payment);
        // The replaced picture is gone.
        Storage::disk('public')->assertMissing('payments/old-proof.jpg');

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'pending', 'payment_status' => 'pending']);
    }

    public function test_the_order_keeps_its_reserved_plates(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())
            ->assertOk();

        $line = SessionDish::query()->where('session_id', $order->session_id)->first();

        $this->assertSame(7, (int) $line->available_stock);
        $this->assertSame(3, (int) $line->reserved_stock);
    }

    public function test_leaving_the_reference_out_keeps_the_old_one(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())
            ->assertOk();

        $this->assertSame('REF-OLD', $this->payment($order)->reference_number);
    }

    public function test_a_new_proof_is_required_and_must_be_an_image(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);
        $this->actingAs($customer);

        $this->postJson(route('my-orders.payment.proof', $order->order_id), [])
            ->assertStatus(422)->assertJsonValidationErrors('proof_of_payment');

        $this->postJson(route('my-orders.payment.proof', $order->order_id), ['proof_of_payment' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('proof_of_payment');

        $this->assertSame('rejected', $this->payment($order)->payment_status);
    }

    public function test_a_cash_order_has_no_proof_to_send(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer, PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())
            ->assertStatus(422);
    }

    public function test_a_refused_new_proof_is_not_kept_on_disk(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer, orderStatus: 'cancelled');

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles('payments'));
    }

    /* ---- paying with cash instead -------------------------------------- */

    public function test_switching_to_cash_makes_it_a_pending_cash_payment_without_proof(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('payments/old-proof.jpg', 'old');
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.cash', $order->order_id))
            ->assertOk();

        $payment = $this->payment($order);

        $this->assertSame((int) $cash->method_id, (int) $payment->payment_method_id);
        $this->assertSame('pending', $payment->payment_status);
        $this->assertNull($payment->proof_of_payment);
        $this->assertNull($payment->reference_number);
        $this->assertNull($payment->rejection_reason);
        Storage::disk('public')->assertMissing('payments/old-proof.jpg');
        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'payment_status' => 'pending', 'order_status' => 'pending']);
    }

    public function test_a_cash_order_can_then_be_completed_by_staff(): void
    {
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)->postJson(route('my-orders.payment.cash', $order->order_id))->assertOk();

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.complete', $order->order_id))
            ->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'order_status' => 'completed', 'payment_status' => 'verified']);
    }

    public function test_switching_to_cash_needs_cash_on_pickup_to_be_available(): void
    {
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.cash', $order->order_id))
            ->assertStatus(422);

        $this->assertSame('rejected', $this->payment($order)->payment_status);
    }

    public function test_an_inactive_cash_option_is_not_offered(): void
    {
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP, 'is_active' => false]);
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.cash', $order->order_id))
            ->assertStatus(422);
    }

    /* ---- who and when -------------------------------------------------- */

    public function test_only_a_rejected_payment_can_be_sent_again(): void
    {
        Storage::fake('public');
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $this->actingAs($customer);

        foreach (['pending', 'verified'] as $status) {
            $order = $this->rejectedOrder($customer);
            DB::table('payments')->where('order_id', $order->order_id)->update(['payment_status' => $status]);

            $this->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())->assertStatus(422);
            $this->postJson(route('my-orders.payment.cash', $order->order_id))->assertStatus(422);

            $this->assertSame($status, $this->payment($order)->payment_status);
        }
    }

    public function test_a_second_tap_after_the_first_one_changes_nothing(): void
    {
        Storage::fake('public');
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);
        $this->actingAs($customer);

        $this->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())->assertOk();
        // The payment is pending again, so another send (or a switch) is refused.
        $this->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())->assertStatus(422);
        $this->postJson(route('my-orders.payment.cash', $order->order_id))->assertStatus(422);
    }

    public function test_a_cancelled_order_cannot_be_fixed(): void
    {
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer, orderStatus: 'cancelled');

        $this->actingAs($customer)->postJson(route('my-orders.payment.cash', $order->order_id))->assertStatus(422);

        $this->assertSame('rejected', $this->payment($order)->payment_status);
    }

    public function test_a_completed_order_cannot_be_fixed(): void
    {
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer, orderStatus: 'completed');

        $this->actingAs($customer)->postJson(route('my-orders.payment.cash', $order->order_id))->assertStatus(422);
    }

    public function test_nobody_can_fix_someone_elses_order(): void
    {
        Storage::fake('public');
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $order = $this->rejectedOrder($this->customer());
        $stranger = $this->customer();
        $this->actingAs($stranger);

        // 404, not 403: the id does not reveal that the order exists.
        $this->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())->assertNotFound();
        $this->postJson(route('my-orders.payment.cash', $order->order_id))->assertNotFound();

        $this->assertSame('rejected', $this->payment($order)->payment_status);
    }

    public function test_an_order_that_does_not_exist_is_not_found(): void
    {
        $this->actingAs($this->customer())->postJson(route('my-orders.payment.cash', 999999))->assertNotFound();
    }

    public function test_both_routes_need_the_create_orders_permission(): void
    {
        Storage::fake('public');
        $owner = $this->userWith('view my orders');
        $order = $this->rejectedOrder($owner);
        $this->actingAs($owner);

        $this->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())->assertForbidden();
        $this->postJson(route('my-orders.payment.cash', $order->order_id))->assertForbidden();
    }

    /* ---- the page ------------------------------------------------------ */

    public function test_the_page_offers_both_ways_out_only_on_a_pending_order_with_a_rejected_payment(): void
    {
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $fixable = $this->rejectedOrder($customer);

        $this->actingAs($customer)
            ->get(route('my-orders.index'))
            ->assertOk()
            ->assertSee('data-fix-payment', false)
            ->assertSee(route('my-orders.payment.proof', $fixable->order_id), false)
            ->assertSee(route('my-orders.payment.cash', $fixable->order_id), false)
            ->assertSee('Screenshot is unreadable.');
    }

    public function test_the_page_hides_the_panel_when_there_is_nothing_to_fix(): void
    {
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $customer = $this->customer();
        $this->rejectedOrder($customer, orderStatus: 'cancelled');

        $this->actingAs($customer)
            ->get(route('my-orders.index'))
            ->assertOk()
            ->assertDontSee('data-fix-payment', false);
    }

    public function test_without_cash_on_pickup_only_the_new_proof_is_offered(): void
    {
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)
            ->get(route('my-orders.index'))
            ->assertSee(route('my-orders.payment.proof', $order->order_id), false)
            ->assertDontSee('data-cash-switch', false);
    }

    public function test_a_cash_order_is_only_offered_nothing_to_upload(): void
    {
        $customer = $this->customer();
        $this->rejectedOrder($customer, PaymentMethod::CASH_ON_PICKUP);

        $this->actingAs($customer)
            ->get(route('my-orders.index'))
            ->assertDontSee('data-reupload-form', false)
            ->assertDontSee('data-fix-payment', false);
    }

    public function test_a_rejection_without_a_reason_is_still_shown(): void
    {
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);
        DB::table('payments')->where('order_id', $order->order_id)->update(['rejection_reason' => null]);

        $this->actingAs($customer)
            ->get(route('my-orders.index'))
            ->assertSee('Payment rejected');
    }

    public function test_without_the_create_orders_permission_the_page_offers_no_way_out(): void
    {
        $viewer = $this->userWith('view my orders');
        $this->rejectedOrder($viewer);

        $this->actingAs($viewer)
            ->get(route('my-orders.index'))
            ->assertOk()
            ->assertDontSee('data-fix-payment', false);
    }

    public function test_a_resent_payment_is_pending_again_for_staff(): void
    {
        Storage::fake('public');
        $customer = $this->customer();
        $order = $this->rejectedOrder($customer);

        $this->actingAs($customer)->postJson(route('my-orders.payment.proof', $order->order_id), $this->proof())->assertOk();

        // Staff can verify it now: it is an ordinary pending payment again.
        $this->actingAs($this->superAdmin())
            ->postJson(route('payments.verify', $order->order_id))
            ->assertOk();

        $this->assertDatabaseHas('orders', ['order_id' => $order->order_id, 'payment_status' => 'verified']);
    }
}
