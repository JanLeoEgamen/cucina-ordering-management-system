<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function protectedNames(): array
    {
        return [
            'cash on pick up' => [PaymentMethod::CASH_ON_PICKUP],
        ];
    }

    #[DataProvider('protectedNames')]
    public function test_a_protected_method_cannot_be_deleted_or_edited(string $name): void
    {
        $method = PaymentMethod::factory()->create(['method_name' => $name]);
        $id = $method->method_id;

        $this->actingAs($this->superAdmin())
            ->deleteJson(route('payment-methods.destroy', $id))
            ->assertStatus(422)
            ->assertJsonPath('status', 'failed');

        $this->actingAs($this->superAdmin())
            ->putJson(route('payment-methods.update', $id), ['method_name' => 'Renamed', 'is_active' => true])
            ->assertStatus(422);

        $this->assertDatabaseHas('payment_methods', ['method_id' => $id, 'method_name' => $name, 'deleted_at' => null]);
    }

    public function test_an_ordinary_method_can_still_be_deleted(): void
    {
        $method = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        $this->actingAs($this->superAdmin())
            ->deleteJson(route('payment-methods.destroy', $method->method_id))
            ->assertOk();

        $this->assertSoftDeleted('payment_methods', ['method_id' => $method->method_id]);
    }

    public function test_the_table_feed_hides_the_delete_button_for_protected_methods(): void
    {
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson(route('payment-methods.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->assertStringNotContainsString('data-row-action="delete"', $response->getContent());
    }

    public function test_a_user_without_the_permission_gets_403(): void
    {
        $method = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        $this->actingAs($this->userWith('view payment-methods'))
            ->deleteJson(route('payment-methods.destroy', $method->method_id))
            ->assertForbidden();
    }
}
