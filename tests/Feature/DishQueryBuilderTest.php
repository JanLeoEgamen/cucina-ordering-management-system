<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The query builder layer of Models\Dish / Models\DishType.
 *
 * These methods replace what Eloquent's `create()` / `update()` / `delete()`
 * used to do, so the soft delete convention, the timestamps and the column
 * whitelist are asserted here rather than assumed.
 */
class DishQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function dishType(string $name = 'Mains'): DishType
    {
        return DishType::factory()->create(['type_name' => $name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return array_merge([
            'dish_types' => [$this->dishType()->dish_type_id],
            'dish_name' => 'Query Builder Dish',
            'description' => 'Inserted without Eloquent',
            'image' => 'https://example.com/dish.jpg',
            'price' => '9.99',
            'calories' => 250,
            'main_ingredients' => 'Rice, beans',
            'is_active' => true,
        ], $overrides);
    }

    public function test_create_dish_inserts_a_row_with_timestamps_and_returns_its_id(): void
    {
        $id = Dish::createDish($this->attributes());

        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('dishes', ['dish_id' => $id, 'dish_name' => 'Query Builder Dish']);

        $row = DB::table('dishes')->where('dish_id', $id)->first();

        $this->assertNotNull($row->created_at);
        $this->assertSame($row->created_at, $row->updated_at);
        $this->assertNull($row->deleted_at);
    }

    public function test_create_dish_stores_availability_as_a_zero_or_one(): void
    {
        $on = Dish::createDish($this->attributes(['dish_name' => 'On', 'is_active' => true]));
        $off = Dish::createDish($this->attributes(['dish_name' => 'Off', 'is_active' => false]));
        $absent = Dish::createDish($this->attributes(['dish_name' => 'Absent', 'is_active' => null]));

        $this->assertSame([1, 0, 0], [
            (int) DB::table('dishes')->where('dish_id', $on)->value('is_active'),
            (int) DB::table('dishes')->where('dish_id', $off)->value('is_active'),
            (int) DB::table('dishes')->where('dish_id', $absent)->value('is_active'),
        ]);
    }

    public function test_create_dish_drops_columns_outside_the_whitelist(): void
    {
        $id = Dish::createDish($this->attributes([
            'dish_id' => 424242,
            'deleted_at' => '2020-01-01 00:00:00',
            'created_at' => '2020-01-01 00:00:00',
            'not_a_column' => 'ignored',
        ]));

        $row = DB::table('dishes')->where('dish_id', $id)->first();

        $this->assertNotSame(424242, $id);
        $this->assertNull($row->deleted_at);
        $this->assertStringStartsWith(now()->format('Y-m-d'), (string) $row->created_at);
    }

    public function test_update_dish_returns_the_number_of_changed_rows(): void
    {
        $id = Dish::createDish($this->attributes());

        // Move the clock on so the refreshed `updated_at` is a real change.
        $this->travel(1)->minutes();

        $this->assertSame(1, Dish::updateDish($id, ['dish_name' => 'Renamed', 'is_active' => false]));
        $this->assertDatabaseHas('dishes', ['dish_id' => $id, 'dish_name' => 'Renamed', 'is_active' => false]);

        // A deleted dish is never written to again.
        Dish::deleteDish($id);
        $this->travel(1)->minutes();

        $this->assertSame(0, Dish::updateDish($id, ['dish_name' => 'Nope']));
        $this->assertDatabaseMissing('dishes', ['dish_id' => $id, 'dish_name' => 'Nope']);
    }

    public function test_update_dish_stamps_updated_at_and_ignores_deleted_rows(): void
    {
        $id = Dish::createDish($this->attributes());
        $created = DB::table('dishes')->where('dish_id', $id)->value('created_at');

        $this->travel(2)->minutes();
        Dish::updateDish($id, ['dish_name' => 'Later']);

        $row = DB::table('dishes')->where('dish_id', $id)->first();
        $this->assertTrue($row->updated_at > $created);

        Dish::deleteDish($id);

        $this->assertSame(0, Dish::updateDish($id, ['dish_name' => 'Too late']));
        $this->assertSame('Later', DB::table('dishes')->where('dish_id', $id)->value('dish_name'));
    }

    public function test_delete_dish_soft_deletes_it_and_reports_double_deletes(): void
    {
        $id = Dish::createDish($this->attributes());

        $this->assertTrue(Dish::deleteDish($id));
        $this->assertSoftDeleted('dishes', ['dish_id' => $id]);

        $row = DB::table('dishes')->where('dish_id', $id)->first();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame($row->deleted_at, $row->updated_at);

        $this->assertFalse(Dish::deleteDish($id));
        $this->assertFalse(Dish::deleteDish(999999));
    }

    public function test_listing_returns_live_dishes_with_their_type_name(): void
    {
        $type = $this->dishType('Desserts');
        Dish::createDish($this->attributes(['dish_types' => [$type->dish_type_id], 'dish_name' => 'Cake']));
        $gone = Dish::createDish($this->attributes(['dish_types' => [$type->dish_type_id], 'dish_name' => 'Pie']));
        Dish::deleteDish($gone);

        $rows = Dish::getAll();

        $this->assertCount(1, $rows);
        $this->assertSame('Cake', $rows[0]->dish_name);
        $this->assertSame('https://example.com/dish.jpg', $rows[0]->image);
        $this->assertSame('Desserts', $rows[0]->dish_types[0]['type_name']);
        $this->assertSame(1, (int) $rows[0]->is_active);
    }

    public function test_listing_falls_back_to_na_when_the_dish_type_is_deleted(): void
    {
        $type = $this->dishType('Doomed');
        Dish::createDish($this->attributes(['dish_types' => [$type->dish_type_id]]));

        DishType::deleteDishType($type->dish_type_id);

        $rows = Dish::getAll();

        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]->dish_types);
    }

    public function test_find_by_id_and_exists_for_dish_type(): void
    {
        $type = $this->dishType();
        $id = Dish::createDish($this->attributes(['dish_types' => [$type->dish_type_id]]));

        $this->assertNotNull(Dish::findById($id));
        $this->assertSame('Mains', Dish::typesOfDish($id)[0]['type_name']);
        $this->assertTrue(Dish::existsById($id));
        $this->assertTrue(Dish::existsForDishType($type->dish_type_id));

        Dish::deleteDish($id);

        $this->assertNull(Dish::findById($id));
        $this->assertFalse(Dish::existsById($id));
        $this->assertFalse(Dish::existsForDishType($type->dish_type_id));
        $this->assertNull(Dish::findById(999999));
        $this->assertFalse(Dish::existsById(999999));
    }

    public function test_dish_type_crud_and_active_options(): void
    {
        $id = DishType::createDishType(['type_name' => 'Starters', 'description' => 'Small plates', 'is_active' => true]);
        $this->assertDatabaseHas('dish_types', ['dish_type_id' => $id, 'type_name' => 'Starters']);

        DishType::updateDishType($id, ['type_name' => 'Appetisers', 'is_active' => false]);
        $this->assertSame('Appetisers', DB::table('dish_types')->where('dish_type_id', $id)->value('type_name'));

        $other = DishType::createDishType(['type_name' => 'Aaa first', 'is_active' => true]);
        $gone = DishType::createDishType(['type_name' => 'Gone', 'is_active' => true]);
        DishType::deleteDishType($gone);

        // getAll(): live types only, ordered by name.
        $this->assertSame(['Aaa first', 'Appetisers'], DishType::getAll()->pluck('type_name')->all());
        $this->assertTrue(DishType::existsById($other));
        $this->assertFalse(DishType::existsById(999999));
        $this->assertSame('Aaa first', DishType::findById($other)->type_name);

        // activeOptions(): the inactive type is filtered out.
        $this->assertSame(['Aaa first'], DishType::activeOptions()->pluck('type_name')->all());
        $this->assertSame('Aaa first', (string) DishType::activeOptions()->first()->type_name);

        $this->assertTrue(DishType::deleteDishType($other));
        $this->assertFalse(DishType::deleteDishType($other));
        $this->assertSame(0, DishType::updateDishType($gone, ['type_name' => 'Too late']));
    }
}
