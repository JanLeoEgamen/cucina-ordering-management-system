<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    private function payment(string $status = 'pending'): Payment
    {
        $method = PaymentMethod::factory()->create(['method_name' => 'GCash']);
        $order = Order::factory()->create(['payment_status' => $status]);

        return Payment::factory()->create([
            'order_id' => $order->order_id,
            'payment_method_id' => $method->method_id,
            'payment_status' => $status,
        ]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('payments.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_renders_with_the_view_popup(): void
    {
        $this->actingAs($this->userWith('view payments'))
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee('id="payments-table"', false)
            ->assertSee('id="payments-cards"', false)
            ->assertSee('view-payment-modal', false)
            // A viewer may not decide, so the popup carries no decision buttons.
            ->assertDontSee('data-decision="verify"', false)
            ->assertDontSee('data-decision="reject"', false);

        $this->actingAs($this->superAdmin())
            ->get(route('payments.index'))
            ->assertSee('data-decision="verify"', false)
            ->assertSee('data-decision="reject"', false)
            ->assertSee('data-decision="undo"', false)
            ->assertSee('id="reject-reason"', false);
    }

    public function test_every_action_needs_its_own_permission(): void
    {
        $payment = $this->payment();
        $viewer = $this->userWith('view payments');

        $this->actingAs($this->userWith())->get(route('payments.index'))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('payments.verify', $payment->order_id))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('payments.reject', $payment->order_id), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('payments.undo', $payment->order_id))->assertForbidden();
        $this->actingAs($viewer)->deleteJson(route('payments.destroy', $payment->order_id))->assertForbidden();

        // Verifying does not let you reject, and the other way round.
        $this->actingAs($this->userWith('verify payments'))->postJson(route('payments.reject', $payment->order_id), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->userWith('reject payments'))->postJson(route('payments.verify', $payment->order_id))->assertForbidden();
        $this->actingAs($this->userWith('verify payments', 'reject payments'))->postJson(route('payments.undo', $payment->order_id))->assertForbidden();
    }

    public function test_the_row_only_has_view_and_delete_and_carries_the_decision_urls(): void
    {
        $payment = $this->payment();

        $row = $this->actingAs($this->superAdmin())
            ->getJson(route('payments.index'), $this->ajax())
            ->assertOk()
            ->json('data.0');

        $this->assertSame($payment->order_id, $row['order_id']);
        $this->assertSame('GCash', $row['method_name']);
        $this->assertStringContainsString('data-status="pending"', $row['status']);
        $this->assertStringContainsString('data-row-action="view"', $row['action']);
        $this->assertStringContainsString('data-row-action="delete"', $row['action']);
        // Verify and Reject live inside the popup, not in the row.
        $this->assertStringNotContainsString('data-row-action="verify"', $row['action']);
        $this->assertStringNotContainsString('data-row-action="reject"', $row['action']);
        $this->assertSame(route('payments.verify', $payment->order_id), $row['verify_url']);
        $this->assertSame(route('payments.reject', $payment->order_id), $row['reject_url']);

        // A viewer sees the row, but gets neither URL nor Delete.
        $viewerRow = $this->actingAs($this->userWith('view payments'))
            ->getJson(route('payments.index'), $this->ajax())
            ->json('data.0');

        $this->assertNull($viewerRow['verify_url']);
        $this->assertNull($viewerRow['reject_url']);
        $this->assertStringNotContainsString('data-row-action="delete"', $viewerRow['action']);
    }

    public function test_a_decided_payment_offers_undo_but_not_verify_or_reject(): void
    {
        $payment = $this->payment('verified');

        $row = $this->actingAs($this->superAdmin())
            ->getJson(route('payments.index'), $this->ajax())
            ->json('data.0');

        $this->assertNull($row['verify_url']);
        $this->assertNull($row['reject_url']);
        $this->assertSame(route('payments.undo', $payment->order_id), $row['undo_url']);

        // A pending payment has nothing to undo.
        $pending = $this->payment();
        $rows = collect($this->actingAs($this->superAdmin())->getJson(route('payments.index'), $this->ajax())->json('data'));

        $this->assertNull($rows->firstWhere('order_id', $pending->order_id)['undo_url']);
    }

    public function test_verifying_stamps_the_payment_and_mirrors_it_on_the_order(): void
    {
        $payment = $this->payment();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('payments.verify', $payment->order_id))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('payments', [
            'order_id' => $payment->order_id,
            'payment_status' => 'verified',
            'verified_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('orders', ['order_id' => $payment->order_id, 'payment_status' => 'verified']);
        $this->assertNotNull(Payment::query()->find($payment->order_id)->verified_at);
    }

    public function test_rejecting_stamps_the_payment_keeps_the_reason_and_mirrors_it_on_the_order(): void
    {
        $payment = $this->payment();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('payments.reject', $payment->order_id), ['reason' => '  The amount does not match.  '])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('payments', [
            'order_id' => $payment->order_id,
            'payment_status' => 'rejected',
            'verified_by' => $admin->id,
            'rejection_reason' => 'The amount does not match.',
        ]);
        $this->assertDatabaseHas('orders', ['order_id' => $payment->order_id, 'payment_status' => 'rejected']);

        $row = $this->actingAs($admin)->getJson(route('payments.index'), $this->ajax())->json('data.0');

        $this->assertSame('The amount does not match.', $row['rejection_reason']);
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $payment = $this->payment();
        $admin = $this->superAdmin();

        foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => str_repeat('x', 501)]] as $body) {
            $this->actingAs($admin)
                ->postJson(route('payments.reject', $payment->order_id), $body)
                ->assertStatus(422)
                ->assertJsonValidationErrors('reason');
        }

        $this->assertDatabaseHas('payments', ['order_id' => $payment->order_id, 'payment_status' => 'pending']);
    }

    public function test_undo_puts_a_decided_payment_back_to_pending_and_clears_the_reason(): void
    {
        $admin = $this->superAdmin();
        $verified = $this->payment();
        $rejected = $this->payment();

        $this->actingAs($admin)->postJson(route('payments.verify', $verified->order_id))->assertOk();
        $this->actingAs($admin)->postJson(route('payments.reject', $rejected->order_id), ['reason' => 'Blurry proof.'])->assertOk();

        foreach ([$verified, $rejected] as $payment) {
            $this->actingAs($admin)
                ->postJson(route('payments.undo', $payment->order_id))
                ->assertOk()
                ->assertJsonPath('status', 'success');

            $this->assertDatabaseHas('payments', [
                'order_id' => $payment->order_id,
                'payment_status' => 'pending',
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
            ]);
            $this->assertDatabaseHas('orders', ['order_id' => $payment->order_id, 'payment_status' => 'pending']);
        }
    }

    public function test_only_a_decided_payment_can_be_undone(): void
    {
        $admin = $this->superAdmin();
        $pending = $this->payment();

        $this->actingAs($admin)->postJson(route('payments.undo', $pending->order_id))->assertStatus(422);
        $this->actingAs($admin)->postJson(route('payments.undo', 9999))->assertNotFound();
    }

    public function test_an_undone_verified_payment_can_be_deleted_again(): void
    {
        $admin = $this->superAdmin();
        $payment = $this->payment('verified');

        $this->actingAs($admin)->deleteJson(route('payments.destroy', $payment->order_id))->assertStatus(422);
        $this->actingAs($admin)->postJson(route('payments.undo', $payment->order_id))->assertOk();
        $this->actingAs($admin)->deleteJson(route('payments.destroy', $payment->order_id))->assertOk();
    }

    public function test_the_customer_sees_why_a_payment_was_rejected(): void
    {
        $customer = $this->userWith('view my orders');
        $order = Order::factory()->create(['user_id' => $customer->id, 'payment_status' => 'rejected']);
        $method = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        Payment::factory()->create([
            'order_id' => $order->order_id,
            'payment_method_id' => $method->method_id,
            'payment_status' => 'rejected',
            'rejection_reason' => 'Screenshot is unreadable.',
        ]);

        $this->actingAs($customer)
            ->get(route('my-orders.index'))
            ->assertOk()
            ->assertSee('Screenshot is unreadable.');
    }

    public function test_only_a_pending_payment_can_be_decided(): void
    {
        $admin = $this->superAdmin();

        foreach (['verified', 'rejected'] as $status) {
            $payment = $this->payment($status);

            $this->actingAs($admin)->postJson(route('payments.verify', $payment->order_id))->assertStatus(422)->assertJsonPath('status', 'failed');
            $this->actingAs($admin)->postJson(route('payments.reject', $payment->order_id))->assertStatus(422);

            $this->assertDatabaseHas('payments', ['order_id' => $payment->order_id, 'payment_status' => $status]);
        }

        $this->actingAs($admin)->postJson(route('payments.verify', 9999))->assertNotFound();
        $this->actingAs($admin)->postJson(route('payments.reject', 9999))->assertNotFound();
    }

    public function test_a_payment_can_be_soft_deleted_but_a_verified_one_is_kept(): void
    {
        $pending = $this->payment();
        $verified = $this->payment('verified');
        $admin = $this->superAdmin();

        $this->actingAs($admin)->deleteJson(route('payments.destroy', $pending->order_id))->assertOk();
        $this->assertSoftDeleted('payments', ['order_id' => $pending->order_id]);

        $this->actingAs($admin)->deleteJson(route('payments.destroy', $verified->order_id))->assertStatus(422);
        $this->assertDatabaseHas('payments', ['order_id' => $verified->order_id, 'deleted_at' => null]);

        $this->actingAs($admin)->deleteJson(route('payments.destroy', 9999))->assertNotFound();
    }

    public function test_the_page_has_the_filters_and_every_row_carries_what_they_read(): void
    {
        $this->actingAs($this->userWith('view payments'))
            ->get(route('payments.index'))
            ->assertOk()
            ->assertSee('id="payment-status-filter"', false)
            ->assertSee('id="payment-method-filter"', false)
            ->assertSee('id="payment-session-filter"', false)
            ->assertSee('id="payment-date-from"', false)
            ->assertSee('id="payment-date-to"', false)
            ->assertSee('data-table-toolbar', false)
            ->assertSee('data-table-search', false)
            ->assertSee('data-table-clear', false)
            ->assertSee('data-date-range-error="Date from cannot be later than date to."', false);

        $payment = $this->payment();

        $row = $this->actingAs($this->userWith('view payments'))
            ->getJson(route('payments.index'), $this->ajax())
            ->assertOk()
            ->json('data.0');

        $order = Order::query()->find($payment->order_id);

        // Method and session by id, the session's times for its label, and the day
        // the payment was made.
        $this->assertSame($payment->payment_method_id, $row['payment_method_id']);
        $this->assertSame($order->session_id, $row['session_id']);
        $this->assertNotEmpty($row['session_date']);
        $this->assertNotEmpty($row['start_time']);
        $this->assertNotEmpty($row['end_time']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', $row['created_at']);
    }

    public function test_the_navigation_links_to_payments_for_those_who_may_view_them(): void
    {
        $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertSee(route('payments.index'), false);
        $this->actingAs($this->userWith())->get(route('dashboard'))->assertDontSee(route('payments.index'), false);
    }
}
