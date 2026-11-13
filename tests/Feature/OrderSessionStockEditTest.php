<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Editing a live session while customers order. The stock box of the edit
// drawer shows what was left when it was opened; saving must apply only the
// admin's change, never write that old number back over the live stock.
class OrderSessionStockEditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: OrderSession, 1: SessionDish}
     */
    private function sessionWithStock(int $stock = 10): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create()->dish_id,
            'available_stock' => $stock,
        ]);

        return [$session, $line];
    }

    private function save(OrderSession $session, SessionDish $line, int $typed, ?int $loaded)
    {
        return $this->putJson(route('order-sessions.update', $session->session_id), [
            'order_date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'dish_ids' => [$line->dish_id],
            'stocks' => [$line->dish_id => $typed],
            'loaded_stocks' => [$line->dish_id => $loaded ?? ''],
        ]);
    }

    private function stockOf(SessionDish $line): int
    {
        return (int) SessionDish::query()->find($line->session_dish_id)->available_stock;
    }

    // Customers take plates after the drawer was opened.
    private function sell(SessionDish $line, int $plates): void
    {
        $this->assertTrue(SessionDish::reserve((int) $line->session_dish_id, $plates));
    }

    public function test_saving_without_touching_the_stock_keeps_the_orders_placed_meanwhile(): void
    {
        [$session, $line] = $this->sessionWithStock(10);

        // The drawer was opened at 10, then 3 + 4 plates were ordered.
        $this->sell($line, 3);
        $this->sell($line, 4);

        $this->actingAs($this->superAdmin());
        $this->save($session, $line, typed: 10, loaded: 10)->assertOk();

        $this->assertSame(3, $this->stockOf($line));
    }

    public function test_adding_plates_adds_to_the_stock_as_it_is_now(): void
    {
        [$session, $line] = $this->sessionWithStock(10);
        $this->sell($line, 7);

        $this->actingAs($this->superAdmin());
        $this->save($session, $line, typed: 15, loaded: 10)->assertOk();

        $this->assertSame(8, $this->stockOf($line)); // 3 + 5
    }

    public function test_cutting_the_stock_cannot_go_below_zero(): void
    {
        [$session, $line] = $this->sessionWithStock(10);
        $this->sell($line, 7);

        $this->actingAs($this->superAdmin());
        $this->save($session, $line, typed: 0, loaded: 10)->assertOk();

        $this->assertSame(0, $this->stockOf($line)); // 3 - 10 clamps to 0
    }

    public function test_lowering_the_stock_takes_that_many_off_what_is_left(): void
    {
        [$session, $line] = $this->sessionWithStock(10);
        $this->sell($line, 4);

        $this->actingAs($this->superAdmin());
        $this->save($session, $line, typed: 8, loaded: 10)->assertOk();

        $this->assertSame(4, $this->stockOf($line)); // 6 - 2
    }

    public function test_reserved_stock_is_never_touched_by_an_edit(): void
    {
        [$session, $line] = $this->sessionWithStock(10);
        $this->sell($line, 4);

        $this->actingAs($this->superAdmin());
        $this->save($session, $line, typed: 20, loaded: 10)->assertOk();

        $this->assertSame(4, (int) SessionDish::query()->find($line->session_dish_id)->reserved_stock);
    }

    public function test_without_a_loaded_value_the_typed_number_is_the_stock(): void
    {
        [$session, $line] = $this->sessionWithStock(10);

        $this->actingAs($this->superAdmin());
        $this->save($session, $line, typed: 25, loaded: null)->assertOk();

        $this->assertSame(25, $this->stockOf($line));
    }

    public function test_a_dish_added_in_the_edit_gets_the_typed_stock(): void
    {
        [$session, $line] = $this->sessionWithStock(10);
        $other = Dish::factory()->create();
        $this->sell($line, 2);

        $this->actingAs($this->superAdmin());
        $this->putJson(route('order-sessions.update', $session->session_id), [
            'order_date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'dish_ids' => [$line->dish_id, $other->dish_id],
            'stocks' => [$line->dish_id => 10, $other->dish_id => 6],
            'loaded_stocks' => [$line->dish_id => 10, $other->dish_id => ''],
        ])->assertOk();

        $added = SessionDish::query()->where('session_id', $session->session_id)->where('dish_id', $other->dish_id)->first();

        $this->assertSame(8, $this->stockOf($line));
        $this->assertSame(6, (int) $added->available_stock);
    }

    public function test_a_dish_taken_out_and_put_back_gets_the_typed_stock(): void
    {
        [$session, $line] = $this->sessionWithStock(10);
        $keep = Dish::factory()->create();
        $this->actingAs($this->superAdmin());

        // First save: the session offers $keep only, so $line leaves it.
        $this->putJson(route('order-sessions.update', $session->session_id), [
            'order_date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'dish_ids' => [$keep->dish_id],
            'stocks' => [$keep->dish_id => 5],
        ])->assertOk();

        // Second save: it comes back; nothing live to protect, so the typed number rules.
        $this->save($session, $line, typed: 12, loaded: null)->assertOk();

        $this->assertSame(12, $this->stockOf($line));
    }

    public function test_the_drawer_posts_what_it_loaded_for_every_dish(): void
    {
        [, $line] = $this->sessionWithStock(10);

        $this->actingAs($this->superAdmin())
            ->get(route('order-sessions.index'))
            ->assertOk()
            ->assertSee('name="loaded_stocks[' . $line->dish_id . ']"', false);
    }

    public function test_a_negative_loaded_value_is_refused(): void
    {
        [$session, $line] = $this->sessionWithStock(10);

        $this->actingAs($this->superAdmin());
        $this->save($session, $line, typed: 5, loaded: -3)
            ->assertStatus(422)
            ->assertJsonValidationErrors('loaded_stocks.' . $line->dish_id);

        $this->assertSame(10, $this->stockOf($line));
    }

    public function test_a_new_session_ignores_loaded_values(): void
    {
        $dish = Dish::factory()->create();

        $this->actingAs($this->superAdmin())
            ->postJson(route('order-sessions.store'), [
                'order_date' => now()->toDateString(),
                'start_time' => '00:00',
                'end_time' => '23:59',
                'dish_ids' => [$dish->dish_id],
                'stocks' => [$dish->dish_id => 9],
                'loaded_stocks' => [$dish->dish_id => 500],
            ])
            ->assertOk();

        $this->assertSame(9, (int) SessionDish::query()->where('dish_id', $dish->dish_id)->value('available_stock'));
    }
}
