<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DishTypeTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        // The CRUD routes are permission gated (see DishTypeController::middleware()).
        return $this->superAdmin();
    }

    /**
     * The table feed is the index route answering its own AJAX request.
     */
    private function feed(string $route, array $parameters = [])
    {
        return $this->actingAs($this->user())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route($route, $parameters));
    }

    public function test_the_table_feed_lists_live_types_with_their_action_urls(): void
    {
        $live = DishType::factory()->create(['type_name' => 'Mains']);
        DishType::factory()->create()->delete();

        $rows = $this->feed('dish-types.index')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Mains', $rows[0]['type_name']);
        $this->assertStringContainsString(__('Active'), $rows[0]['is_active']);
        $this->assertStringContainsString(route('dish-types.edit', $live->dish_type_id), $rows[0]['action']);
        $this->assertStringContainsString(route('dish-types.update', $live->dish_type_id), $rows[0]['action']);
        $this->assertStringContainsString(route('dish-types.destroy', $live->dish_type_id), $rows[0]['action']);
        $this->assertStringContainsString('data-row-action="view"', $rows[0]['action']);
        $this->assertArrayNotHasKey('restore_url', $rows[0]);
    }

    public function test_the_edit_endpoint_returns_the_record_or_404(): void
    {
        $user = $this->user();
        $dishType = DishType::factory()->create(['type_name' => 'Mains', 'description' => 'Main dishes']);

        $this->actingAs($user)
            ->getJson(route('dish-types.edit', $dishType->dish_type_id))
            ->assertOk()
            ->assertJsonPath('type_name', 'Mains')
            ->assertJsonPath('description', 'Main dishes');

        $id = $dishType->dish_type_id;
        $dishType->delete();

        $this->actingAs($user)->getJson(route('dish-types.edit', $id))->assertNotFound();
        $this->actingAs($user)->putJson(route('dish-types.update', $id), ['type_name' => 'Nope', 'is_active' => '1'])->assertNotFound();
        $this->actingAs($user)->deleteJson(route('dish-types.destroy', $id))->assertNotFound();
    }

    public function test_a_dish_type_can_be_created_updated_and_soft_deleted(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('dish-types.store'), ['type_name' => 'Desserts', 'is_active' => '1'])
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $dishType = DishType::sole();
        $this->assertTrue($dishType->is_active);

        // The unique rule must ignore the record being updated.
        $this->actingAs($user)
            ->putJson(route('dish-types.update', $dishType), ['type_name' => 'Desserts', 'is_active' => '0'])
            ->assertOk();

        $this->assertFalse($dishType->refresh()->is_active);

        $this->actingAs($user)->deleteJson(route('dish-types.destroy', $dishType))->assertOk();

        $this->assertSoftDeleted('dish_types', ['dish_type_id' => $dishType->dish_type_id]);
    }

    public function test_the_name_must_be_unique(): void
    {
        DishType::factory()->create(['type_name' => 'Mains']);

        $this->actingAs($this->user())
            ->postJson(route('dish-types.store'), ['type_name' => 'Mains', 'is_active' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type_name');

        $this->assertDatabaseCount('dish_types', 1);
    }

    public function test_a_dish_type_with_dishes_cannot_be_deleted(): void
    {
        $dishType = DishType::factory()->create();
        $dish = Dish::factory()->create();
        $dish->dishTypes()->attach($dishType->dish_type_id);

        $this->actingAs($this->user())
            ->deleteJson(route('dish-types.destroy', $dishType))
            ->assertStatus(422)
            ->assertJson(['status' => 'failed']);

        $this->assertNotSoftDeleted('dish_types', ['dish_type_id' => $dishType->dish_type_id]);

        // Once its dishes are soft deleted the type can go too.
        // Qualified: dish_id exists on both sides of the pivot join.
        Dish::deleteDish((int) $dishType->dishes()->value('dishes.dish_id'));

        $this->actingAs($this->user())->deleteJson(route('dish-types.destroy', $dishType))->assertOk();

        $this->assertSoftDeleted('dish_types', ['dish_type_id' => $dishType->dish_type_id]);
    }

    public function test_index_renders_the_table_and_both_drawers(): void
    {
        $this->actingAs($this->user())->get(route('dish-types.index'))
            ->assertOk()
            ->assertSee('id="dish-types-table"', false)
            ->assertSee('>#</th>', false)
            ->assertSee('data-view-mode="table"', false)
            ->assertSee('data-view-mode="card"', false)
            ->assertSee('id="dish-types-cards"', false)
            ->assertSee('id="create-dish-type-form"', false)
            ->assertSee('id="edit-dish-type-form"', false);
    }
}
