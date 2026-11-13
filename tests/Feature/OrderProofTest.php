<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderProofTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: OrderSession, 1: SessionDish}
     */
    private function liveSession(): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create()->dish_id,
            'available_stock' => 10,
        ]);

        return [$session, $line];
    }

    public function test_a_bank_order_without_proof_is_rejected(): void
    {
        [$session, $line] = $this->liveSession();
        $method = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), [
                'session_id' => $session->session_id,
                'payment_method_id' => $method->method_id,
                'items' => [$line->session_dish_id => 1],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof_of_payment');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, (int) SessionDish::query()->find($line->session_dish_id)->available_stock);
    }

    public function test_a_bank_order_with_proof_is_placed(): void
    {
        Storage::fake('public');
        [$session, $line] = $this->liveSession();
        $method = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), [
                'session_id' => $session->session_id,
                'payment_method_id' => $method->method_id,
                'items' => [$line->session_dish_id => 1],
                'proof_of_payment' => UploadedFile::fake()->image('proof.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_cash_on_pickup_needs_no_proof(): void
    {
        [$session, $line] = $this->liveSession();
        $method = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('orders.store'), [
                'session_id' => $session->session_id,
                'payment_method_id' => $method->method_id,
                'items' => [$line->session_dish_id => 1],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
