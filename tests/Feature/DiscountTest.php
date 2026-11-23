<?php

namespace Tests\Feature;

use App\Models\Discount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The Discounts page (the CRUD): what a customer can pick at checkout. How an order uses one is
// in DiscountCheckoutTest.
class DiscountTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'discount_name' => 'Senior Citizen',
            'percent' => 20,
            'requires_proof' => true,
            'valid_from' => '',
            'valid_until' => '',
            'is_active' => true,
        ], $override);
    }

    public function test_the_page_and_the_feed_list_live_discounts(): void
    {
        Discount::factory()->create(['discount_name' => 'PWD', 'percent' => 20]);
        Discount::factory()->create(['discount_name' => 'Gone', 'deleted_at' => now()]);

        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('discounts.index'))->assertOk()->assertSee('Discounts');

        $rows = collect($this->actingAs($admin)->withHeaders($this->ajax())
            ->getJson(route('discounts.index'))->assertOk()->json('data'));

        $this->assertSame(['PWD'], $rows->pluck('discount_name')->all());
        $this->assertStringContainsString('data-status="active"', $rows->first()['status']);
        $this->assertSame(0, $rows->first()['used']);
    }

    public function test_a_discount_can_be_created_edited_and_read(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('discounts.store'), $this->payload())
            ->assertOk()->assertJson(['status' => 'success']);

        $discount = Discount::query()->where('discount_name', 'Senior Citizen')->firstOrFail();
        $this->assertEquals(20, $discount->percent);
        $this->assertTrue($discount->requires_proof);
        $this->assertNull($discount->valid_from);

        $this->actingAs($admin)
            ->putJson(route('discounts.update', $discount->discount_id), $this->payload([
                'discount_name' => 'Senior',
                'percent' => 12.5,
                'requires_proof' => false,
                'valid_from' => '2026-12-01',
                'valid_until' => '2026-12-31',
                'is_active' => false,
            ]))
            ->assertOk();

        $this->assertDatabaseHas('discounts', [
            'discount_id' => $discount->discount_id,
            'discount_name' => 'Senior',
            'requires_proof' => 0,
            'is_active' => 0,
        ]);

        $this->actingAs($admin)->getJson(route('discounts.edit', $discount->discount_id))
            ->assertOk()
            ->assertJsonPath('discount_name', 'Senior')
            ->assertJsonPath('valid_from', '2026-12-01')
            ->assertJsonPath('proof', 'Not needed');
    }

    public function test_the_fields_are_validated(): void
    {
        $admin = $this->superAdmin();
        Discount::factory()->create(['discount_name' => 'PWD']);

        $post = fn (array $override) => $this->actingAs($admin)->postJson(route('discounts.store'), $this->payload($override));

        $post(['discount_name' => ''])->assertStatus(422)->assertJsonValidationErrors('discount_name');
        $post(['discount_name' => 'PWD'])->assertStatus(422)->assertJsonValidationErrors('discount_name');
        $post(['discount_name' => str_repeat('a', 256)])->assertStatus(422)->assertJsonValidationErrors('discount_name');
        $post(['percent' => 0])->assertStatus(422)->assertJsonValidationErrors('percent');
        $post(['percent' => 100.01])->assertStatus(422)->assertJsonValidationErrors('percent');
        $post(['percent' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('percent');
        $post(['percent' => 10.123])->assertStatus(422)->assertJsonValidationErrors('percent');
        $post(['valid_from' => '2026-12-31', 'valid_until' => '2026-12-01'])->assertStatus(422)->assertJsonValidationErrors('valid_until');
        $post(['valid_from' => 'tomorrow'])->assertStatus(422)->assertJsonValidationErrors('valid_from');

        // 100 percent (a free order) and a one day window are fine.
        $post(['discount_name' => 'Free', 'percent' => 100, 'valid_from' => '2026-12-01', 'valid_until' => '2026-12-01'])->assertOk();
    }

    public function test_a_deleted_discounts_name_can_be_used_again_and_an_edit_may_keep_its_own_name(): void
    {
        $admin = $this->superAdmin();
        $old = Discount::factory()->create(['discount_name' => 'PWD']);
        $this->actingAs($admin)->deleteJson(route('discounts.destroy', $old->discount_id))->assertOk();

        $this->assertSoftDeleted('discounts', ['discount_id' => $old->discount_id]);
        $this->actingAs($admin)->postJson(route('discounts.store'), $this->payload(['discount_name' => 'PWD']))->assertOk();

        $new = Discount::query()->where('discount_name', 'PWD')->firstOrFail();
        $this->actingAs($admin)->putJson(route('discounts.update', $new->discount_id), $this->payload(['discount_name' => 'PWD', 'percent' => 30]))->assertOk();

        $this->actingAs($admin)->getJson(route('discounts.edit', $old->discount_id))->assertNotFound();
        $this->actingAs($admin)->deleteJson(route('discounts.destroy', $old->discount_id))->assertNotFound();
    }

    public function test_the_status_follows_the_switch_and_the_dates(): void
    {
        $today = now()->toDateString();

        $this->assertSame('active', Discount::stateOf((object) ['is_active' => 1, 'valid_from' => null, 'valid_until' => null]));
        $this->assertSame('active', Discount::stateOf((object) ['is_active' => 1, 'valid_from' => $today, 'valid_until' => $today]));
        $this->assertSame('inactive', Discount::stateOf((object) ['is_active' => 0, 'valid_from' => null, 'valid_until' => null]));
        $this->assertSame('scheduled', Discount::stateOf((object) ['is_active' => 1, 'valid_from' => now()->addDay()->toDateString(), 'valid_until' => null]));
        $this->assertSame('expired', Discount::stateOf((object) ['is_active' => 1, 'valid_from' => null, 'valid_until' => now()->subDay()->toDateString()]));
    }

    public function test_only_usable_discounts_are_offered(): void
    {
        $open = Discount::factory()->create(['discount_name' => 'Open']);
        $window = Discount::factory()->create(['discount_name' => 'Window', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString()]);
        $off = Discount::factory()->inactive()->create(['discount_name' => 'Off']);
        $later = Discount::factory()->create(['discount_name' => 'Later', 'valid_from' => now()->addDay()->toDateString()]);
        $over = Discount::factory()->create(['discount_name' => 'Over', 'valid_until' => now()->subDay()->toDateString()]);
        $gone = Discount::factory()->create(['discount_name' => 'Gone', 'deleted_at' => now()]);

        $this->assertSame(['Open', 'Window'], array_column(Discount::offered(), 'name'));

        $this->assertNotNull(Discount::usable($open->discount_id));
        $this->assertNotNull(Discount::usable($window->discount_id));

        foreach ([$off, $later, $over, $gone] as $discount) {
            $this->assertNull(Discount::usable($discount->discount_id), $discount->discount_name);
        }
    }

    public function test_the_money_is_rounded_to_cents(): void
    {
        $this->assertSame([20.0, 80.0], Discount::amounts(100, 20));
        $this->assertSame([0.33, 2.67], Discount::amounts(3, 11));
        $this->assertSame([0.0, 5.0], Discount::amounts(5, 0.001));
        $this->assertSame([5.0, 0.0], Discount::amounts(5, 100));
    }

    public function test_every_action_needs_its_permission(): void
    {
        $discount = Discount::factory()->create();

        $this->actingAs($this->userWith())->get(route('discounts.index'))->assertForbidden();
        $this->actingAs($this->userWith('view discounts'))->get(route('discounts.index'))->assertOk();
        $this->actingAs($this->userWith('view discounts'))->getJson(route('discounts.edit', $discount->discount_id))->assertOk();

        $this->actingAs($this->userWith('view discounts'))
            ->postJson(route('discounts.store'), $this->payload())->assertForbidden();
        $this->actingAs($this->userWith('create discounts'))
            ->postJson(route('discounts.store'), $this->payload())->assertOk();

        $this->actingAs($this->userWith('view discounts', 'create discounts'))
            ->putJson(route('discounts.update', $discount->discount_id), $this->payload())->assertForbidden();
        $this->actingAs($this->userWith('edit discounts'))
            ->putJson(route('discounts.update', $discount->discount_id), $this->payload(['discount_name' => 'Renamed']))->assertOk();

        $this->actingAs($this->userWith('view discounts', 'edit discounts'))
            ->deleteJson(route('discounts.destroy', $discount->discount_id))->assertForbidden();
        $this->actingAs($this->userWith('delete discounts'))
            ->deleteJson(route('discounts.destroy', $discount->discount_id))->assertOk();
    }
}
