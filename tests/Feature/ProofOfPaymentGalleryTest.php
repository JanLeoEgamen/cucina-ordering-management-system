<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProofOfPaymentGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function method(string $name): PaymentMethod
    {
        return PaymentMethod::factory()->create(['method_name' => $name]);
    }

    private function payment(PaymentMethod $method, array $attributes = []): Payment
    {
        return Payment::factory()->create($attributes + [
            'order_id' => Order::factory(),
            'payment_method_id' => $method->method_id,
            'proof_of_payment' => 'payments/' . fake()->uuid() . '.jpg',
        ]);
    }

    private function feed(array $query = [])
    {
        return $this->actingAs($this->userWith('view payments'))
            ->getJson(route('payments.proofs', $query), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('payments.proofs'))->assertRedirect(route('login'));
    }

    public function test_the_gallery_needs_the_view_payments_permission(): void
    {
        $this->actingAs($this->userWith('verify payments'))->get(route('payments.proofs'))->assertForbidden();
        $this->actingAs($this->userWith('verify payments'))
            ->getJson(route('payments.proofs'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertForbidden();
    }

    public function test_the_page_renders_its_filters_and_is_linked_from_payments(): void
    {
        $viewer = $this->userWith('view payments');

        $this->actingAs($viewer)->get(route('payments.proofs'))
            ->assertOk()
            ->assertSee('Proof of Payment Gallery')
            ->assertSee('id="proof-method-filter"', false)
            ->assertSee('id="proof-date-from"', false)
            ->assertSee('id="proof-date-to"', false)
            ->assertSee('data-page-module="payment-proofs"', false);

        $this->actingAs($viewer)->get(route('payments.index'))->assertSee(route('payments.proofs'), false);
    }

    public function test_only_live_payments_with_a_proof_are_listed_newest_first(): void
    {
        $gcash = $this->method('GCash');

        $older = $this->payment($gcash, ['created_at' => '2026-11-01 09:00:00']);
        $newer = $this->payment($gcash, ['created_at' => '2026-11-02 09:00:00']);
        $this->payment($gcash, ['proof_of_payment' => null]);
        $this->payment($gcash, ['proof_of_payment' => '']);
        Payment::deletePayment($this->payment($gcash)->order_id);
        Order::query()->where('order_id', $this->payment($gcash)->order_id)->delete();

        $json = $this->feed()->json();

        $this->assertSame([$newer->order_id, $older->order_id], array_column($json['data'], 'order_id'));
        $this->assertSame(2, $json['total']);
    }

    public function test_a_row_carries_what_the_card_prints_and_nothing_else(): void
    {
        $payment = $this->payment($this->method('GCash'), ['proof_of_payment' => 'payments/receipt.jpg', 'payment_status' => 'verified']);

        $row = $this->feed()->json('data.0');

        $this->assertSame(
            ['order_id', 'order_number', 'customer_name', 'method_name', 'reference_number', 'amount', 'created_at', 'proof_url', 'status'],
            array_keys($row)
        );
        $this->assertSame($payment->order_id, $row['order_id']);
        $this->assertSame('GCash', $row['method_name']);
        $this->assertSame(Payment::proofUrl('payments/receipt.jpg'), $row['proof_url']);
        $this->assertStringContainsString('data-status="verified"', $row['status']);
    }

    public function test_it_filters_by_payment_method_and_by_date(): void
    {
        $gcash = $this->method('GCash');
        $bank = $this->method('Bank Transfer');

        $first = $this->payment($gcash, ['created_at' => '2026-11-01 00:00:00']);
        $second = $this->payment($bank, ['created_at' => '2026-11-05 12:00:00']);
        $third = $this->payment($gcash, ['created_at' => '2026-11-10 23:59:59']);

        $ids = fn (array $query) => array_column($this->feed($query)->json('data'), 'order_id');

        $this->assertSame([$third->order_id, $first->order_id], $ids(['method' => $gcash->method_id]));
        $this->assertSame([$third->order_id, $second->order_id], $ids(['from' => '2026-11-05']));
        // Both ends are whole days.
        $this->assertSame([$second->order_id, $first->order_id], $ids(['to' => '2026-11-05']));
        $this->assertSame([$third->order_id], $ids(['from' => '2026-11-10', 'to' => '2026-11-10']));
        $this->assertSame([], $ids(['method' => $bank->method_id, 'from' => '2026-11-06']));
    }

    public function test_a_hand_made_filter_never_breaks_the_feed(): void
    {
        $this->payment($this->method('GCash'));

        foreach ([['from' => 'yesterday', 'to' => '1/1/2026'], ['from' => ['x'], 'to' => ['y']], ['method' => 'abc', 'page' => '-4']] as $query) {
            $this->assertSame(1, $this->feed($query)->json('total'));
        }
    }

    public function test_the_filter_offers_only_methods_that_have_a_proof(): void
    {
        $gcash = $this->method('GCash');
        $bank = $this->method('Bank Transfer');
        $this->method('Maya');

        $this->payment($gcash);
        $this->payment($gcash);
        $this->payment($bank);

        // A deleted method's proofs are still in the gallery, so it stays a choice.
        PaymentMethod::query()->where('method_id', $bank->method_id)->delete();

        $this->assertSame(
            [['id' => $bank->method_id, 'name' => 'Bank Transfer'], ['id' => $gcash->method_id, 'name' => 'GCash']],
            $this->feed(['method' => $gcash->method_id])->json('methods')
        );
    }

    public function test_it_is_paged_by_the_server(): void
    {
        $gcash = $this->method('GCash');

        for ($i = 0; $i <= Payment::GALLERY_PAGE_SIZE; $i++) {
            $this->payment($gcash, ['created_at' => now()->subMinutes($i)]);
        }

        $first = $this->feed()->json();

        $this->assertCount(Payment::GALLERY_PAGE_SIZE, $first['data']);
        $this->assertSame([1, 2, Payment::GALLERY_PAGE_SIZE + 1], [$first['page'], $first['last_page'], $first['total']]);

        $this->assertCount(1, $this->feed(['page' => 2])->json('data'));

        // Past the end is the last page, not an empty one.
        $this->assertSame(2, $this->feed(['page' => 99])->json('page'));
    }

    public function test_an_empty_gallery_is_one_empty_page(): void
    {
        $this->feed()->assertExactJson([
            'data' => [], 'total' => 0, 'page' => 1, 'last_page' => 1, 'per_page' => Payment::GALLERY_PAGE_SIZE, 'methods' => [],
        ]);
    }
}
