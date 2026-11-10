<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DishTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        // The CRUD routes are permission gated (see DishController::middleware()).
        return $this->superAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'dish_types' => [DishType::factory()->create()->dish_type_id],
            'dish_name' => 'Grilled Chicken',
            'description' => 'Charcoal grilled',
            'image' => UploadedFile::fake()->image('dish.jpg'),
            'price' => '12.50',
            'calories' => 480,
            'main_ingredients' => 'Chicken, spices',
            'is_active' => '1',
        ], $overrides);
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

    public function test_guests_cannot_reach_the_dish_pages(): void
    {
        $this->get(route('dishes.index'))->assertRedirect(route('login'));
        $this->getJson(route('dishes.index'))->assertUnauthorized();
    }

    public function test_the_table_feed_lists_live_dishes_with_their_action_urls(): void
    {
        $type = DishType::factory()->create(['type_name' => 'Mains']);
        $live = Dish::factory()->create(['dish_name' => 'Live Dish']);
        $live->dishTypes()->attach($type->dish_type_id);
        $gone = Dish::factory()->create(['dish_name' => 'Gone Dish']);
        $gone->dishTypes()->attach($type->dish_type_id);
        $gone->delete();

        $rows = $this->feed('dishes.index')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Live Dish', $rows[0]['dish_name']);
        $this->assertSame([['dish_type_id' => $type->dish_type_id, 'type_name' => 'Mains']], $rows[0]['dish_types']);
        $this->assertSame($live->image, $rows[0]['image']);

        // Status and actions are rendered server side, so the URLs travel with them.
        $this->assertStringContainsString('<span', $rows[0]['is_active']);
        $this->assertStringContainsString(__('Active'), $rows[0]['is_active']);
        $this->assertStringContainsString(route('dishes.edit', $live->dish_id), $rows[0]['action']);
        $this->assertStringContainsString(route('dishes.update', $live->dish_id), $rows[0]['action']);
        $this->assertStringContainsString(route('dishes.destroy', $live->dish_id), $rows[0]['action']);
        $this->assertStringContainsString('data-row-action="view"', $rows[0]['action']);
        $this->assertStringContainsString('data-view-url="' . route('dishes.edit', $live->dish_id) . '"', $rows[0]['action']);

        $this->assertArrayNotHasKey('update_url', $rows[0]);
        $this->assertArrayNotHasKey('delete_url', $rows[0]);
        $this->assertArrayNotHasKey('restore_url', $rows[0]);
    }

    public function test_the_edit_endpoint_returns_the_record_for_the_drawer(): void
    {
        $type = DishType::factory()->create(['type_name' => 'Mains']);
        $dish = Dish::factory()->create(['dish_name' => 'Editable']);
        $dish->dishTypes()->attach($type->dish_type_id);

        $this->actingAs($this->user())
            ->getJson(route('dishes.edit', $dish->dish_id))
            ->assertOk()
            ->assertJsonPath('dish_name', 'Editable')
            ->assertJsonPath('dish_types.0.type_name', 'Mains')
            ->assertJsonStructure([
                'dish_id', 'dish_name', 'description', 'dish_types',
                'image', 'price', 'calories', 'main_ingredients', 'is_active',
            ]);
    }

    public function test_the_edit_endpoint_carries_the_status_badge_for_the_view_drawer(): void
    {
        $dish = Dish::factory()->create(['dish_name' => 'Hidden', 'is_active' => false]);

        $response = $this->actingAs($this->user())
            ->getJson(route('dishes.edit', $dish->dish_id))
            ->assertOk();

        $this->assertStringContainsString('data-status="inactive"', $response->json('status'));
        $this->assertStringContainsString(__('Inactive'), $response->json('status'));
    }

    public function test_the_edit_endpoint_and_the_writes_answer_404_for_missing_dishes(): void
    {
        $user = $this->user();
        $dish = Dish::factory()->create(['dish_name' => 'Doomed']);
        $id = $dish->dish_id;
        $dish->delete();

        $this->actingAs($user)->getJson(route('dishes.edit', $id))->assertNotFound();
        $this->actingAs($user)->put(route('dishes.update', $id), $this->payload())->assertNotFound();
        $this->actingAs($user)->deleteJson(route('dishes.destroy', $id))->assertNotFound();

        $this->assertSoftDeleted('dishes', ['dish_id' => $id]);
        $this->assertDatabaseHas('dishes', ['dish_id' => $id, 'dish_name' => 'Doomed']);
    }

    public function test_index_renders_the_table_and_both_drawers(): void
    {
        $this->actingAs($this->user())->get(route('dishes.index'))
            ->assertOk()
            ->assertSee('id="dishes-table"', false)
            ->assertSee('>#</th>', false)
            ->assertSee('>Image</th>', false)
            ->assertSee('data-view-mode="table"', false)
            ->assertSee('data-view-mode="card"', false)
            ->assertSee('id="dishes-cards"', false)
            ->assertSee('id="create-dish-form"', false)
            ->assertSee('id="edit-dish-form"', false);
    }

    public function test_a_dish_can_be_created(): void
    {
        $response = $this->actingAs($this->user())
            ->post(route('dishes.store'), $this->payload())
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertStringContainsString('created', $response->json('message'));
        $this->assertDatabaseHas('dishes', ['dish_name' => 'Grilled Chicken', 'is_active' => true]);
    }

    public function test_a_dish_can_carry_several_dish_types(): void
    {
        $main = DishType::factory()->create(['type_name' => 'Mains']);
        $salad = DishType::factory()->create(['type_name' => 'Salads']);

        $this->actingAs($this->user())
            ->post(route('dishes.store'), $this->payload([
                'dish_name' => 'Mixed Plate',
                'dish_types' => [$main->dish_type_id, $salad->dish_type_id],
            ]))
            ->assertOk();

        $dish = Dish::getAll()->firstWhere('dish_name', 'Mixed Plate');

        $this->assertCount(2, $dish->dish_types);
        $this->assertSame(['Mains', 'Salads'], array_column($dish->dish_types, 'type_name'));
    }

    public function test_updating_a_dish_replaces_its_dish_types(): void
    {
        $main = DishType::factory()->create(['type_name' => 'Mains']);
        $salad = DishType::factory()->create(['type_name' => 'Salads']);

        $dish = Dish::factory()->create(['dish_name' => 'Switcher']);
        $dish->dishTypes()->attach($main->dish_type_id);

        $this->actingAs($this->user())
            ->put(route('dishes.update', $dish), $this->payload(['dish_types' => [$salad->dish_type_id]]))
            ->assertOk();

        $this->assertSame(['Salads'], array_column(Dish::typesOfDish($dish->dish_id), 'type_name'));
    }

    public function test_an_unchecked_toggle_stores_an_inactive_dish(): void
    {
        // The availability toggle posts nothing for the checkbox and "0" for its
        // hidden companion, which is exactly what this covers.
        $payload = $this->payload(['is_active' => '0']);

        $this->actingAs($this->user())->post(route('dishes.store'), $payload)->assertOk();

        $this->assertDatabaseHas('dishes', ['dish_name' => 'Grilled Chicken', 'is_active' => false]);

        // ...and a form that posts no is_active key at all behaves the same.
        $withoutKey = $this->payload(['dish_name' => 'No Toggle']);
        unset($withoutKey['is_active']);

        $this->actingAs($this->user())->post(route('dishes.store'), $withoutKey)->assertOk();

        $this->assertDatabaseHas('dishes', ['dish_name' => 'No Toggle', 'is_active' => false]);
    }

    public function test_a_dish_can_be_updated(): void
    {
        $dish = Dish::factory()->create(['dish_name' => 'Old Name', 'is_active' => true]);

        $this->actingAs($this->user())
            ->put(route('dishes.update', $dish), $this->payload([
                'dish_name' => 'New Name',
                'is_active' => '0',
            ]))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $dish->refresh();

        $this->assertSame('New Name', $dish->dish_name);
        $this->assertFalse($dish->is_active);
    }

    public function test_destroy_soft_deletes_the_dish(): void
    {
        $dish = Dish::factory()->create();

        $this->actingAs($this->user())
            ->deleteJson(route('dishes.destroy', $dish))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $this->assertSoftDeleted('dishes', ['dish_id' => $dish->dish_id]);
        $this->assertDatabaseHas('dishes', ['dish_id' => $dish->dish_id]);
        $this->assertSame([], $this->feed('dishes.index')->json('data'));
    }

    /**
     * @return list<array{0: string, 1: mixed, 2: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'dish_name missing' => ['dish_name', null, 'dish_name'],
            'dish_name too long' => ['dish_name', str_repeat('a', 256), 'dish_name'],
            'unknown dish type' => ['dish_types', [99999], 'dish_types.0'],
            'price missing' => ['price', null, 'price'],
            'price not numeric' => ['price', 'free', 'price'],
            'price negative' => ['price', '-1', 'price'],
            'calories negative' => ['calories', -5, 'calories'],
            'calories not an integer' => ['calories', 'lots', 'calories'],
            'image not an image' => ['image', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'), 'image'],
            'image too large' => ['image', UploadedFile::fake()->image('large.jpg')->size(2500), 'image'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_rejects_invalid_input(string $field, mixed $value, string $expectedError): void
    {
        $payload = $this->payload([$field => $value]);

        $this->actingAs($this->user())
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('dishes.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($expectedError);

        $this->assertDatabaseCount('dishes', 0);
    }

    public function test_nullable_columns_may_be_omitted(): void
    {
        $payload = array_diff_key($this->payload(), array_flip(['description', 'image', 'calories', 'main_ingredients']));

        $this->actingAs($this->user())->post(route('dishes.store'), $payload)->assertOk();

        $this->assertDatabaseHas('dishes', ['dish_name' => 'Grilled Chicken', 'calories' => null, 'image' => null]);
    }

    public function test_a_dish_image_is_stored_on_the_public_disk(): void
    {
        $this->actingAs($this->user())
            ->post(route('dishes.store'), $this->payload())
            ->assertOk();

        $dish = DB::table('dishes')->where('dish_name', 'Grilled Chicken')->first();

        $this->assertNotNull($dish->image);
        $this->assertStringStartsWith('dishes/', $dish->image);
        $this->assertTrue(Storage::disk('public')->exists($dish->image));
    }

    public function test_updating_a_dish_replaces_its_image_and_deletes_the_previous_one(): void
    {
        $this->actingAs($this->user())
            ->post(route('dishes.store'), $this->payload())
            ->assertOk();

        $dishId = DB::table('dishes')->where('dish_name', 'Grilled Chicken')->value('dish_id');
        $previousImage = DB::table('dishes')->where('dish_id', $dishId)->value('image');

        $this->assertTrue(Storage::disk('public')->exists($previousImage));

        $this->actingAs($this->user())
            ->put(route('dishes.update', $dishId), $this->payload(['image' => UploadedFile::fake()->image('replacement.jpg')]))
            ->assertOk();

        $this->assertFalse(Storage::disk('public')->exists($previousImage));
        $this->assertTrue(Storage::disk('public')->exists(DB::table('dishes')->where('dish_id', $dishId)->value('image')));
    }

    public function test_deleting_a_dish_removes_its_image_file(): void
    {
        $this->actingAs($this->user())
            ->post(route('dishes.store'), $this->payload())
            ->assertOk();

        $dishId = DB::table('dishes')->where('dish_name', 'Grilled Chicken')->value('dish_id');
        $image = DB::table('dishes')->where('dish_id', $dishId)->value('image');

        $this->assertTrue(Storage::disk('public')->exists($image));

        $this->actingAs($this->user())
            ->delete(route('dishes.destroy', $dishId))
            ->assertOk();

        $this->assertFalse(Storage::disk('public')->exists($image));
    }
}
