<?php

namespace Tests\Feature;

use App\Models\BulkOrder;
use App\Models\BulkSession;
use App\Models\BulkSessionDish;
use App\Models\Dish;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The staff side of Bulk Orders: the Bulk Sessions page, its feed, create / edit /
// end / delete, the overlap and minimum rules, and who may do what.
class BulkSessionTest extends TestCase
{
    use RefreshDatabase;

    private function ajax(): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest'];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Christmas trays',
            'order_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'min_quantity' => 20,
            'dish_ids' => [Dish::factory()->create()->dish_id],
        ], $overrides);
    }

    /* -------- the limits of System Configuration -------- */

    public function test_a_session_minimum_must_stay_inside_the_system_limits(): void
    {
        Setting::setBulkLimits(15, 100);
        $this->actingAs($this->superAdmin());

        $this->postJson(route('bulk-sessions.store'), $this->payload(['min_quantity' => 14]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['min_quantity' => 101]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');

        $this->assertDatabaseCount('bulk_sessions', 0);

        // Both ends are allowed.
        $this->postJson(route('bulk-sessions.store'), $this->payload(['min_quantity' => 15]))->assertOk();
        $this->assertSame(1, BulkSession::query()->count());
    }

    public function test_the_new_session_drawer_starts_from_the_system_minimum(): void
    {
        Setting::setBulkLimits(35, 200);

        $this->actingAs($this->userWith('view bulk-sessions', 'create bulk-sessions'))
            ->get(route('bulk-sessions.index'))
            ->assertOk()
            ->assertSee('data-default-min="35"', false)
            ->assertSee('max="200"', false);
    }

    /* -------- access -------- */

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('bulk-sessions.index'))->assertRedirect(route('login'));
        $this->post(route('bulk-sessions.store'), [])->assertRedirect(route('login'));
    }

    public function test_every_action_needs_its_own_permission(): void
    {
        $session = BulkSession::factory()->create();
        $nobody = $this->userWith('view menu');

        $this->actingAs($nobody);
        $this->get(route('bulk-sessions.index'))->assertForbidden();
        $this->get(route('bulk-sessions.show', $session->bulk_session_id))->assertForbidden();
        $this->post(route('bulk-sessions.store'), $this->payload())->assertForbidden();
        $this->getJson(route('bulk-sessions.edit', $session->bulk_session_id))->assertForbidden();
        $this->putJson(route('bulk-sessions.update', $session->bulk_session_id), $this->payload())->assertForbidden();
        $this->postJson(route('bulk-sessions.end', $session->bulk_session_id))->assertForbidden();
        $this->deleteJson(route('bulk-sessions.destroy', $session->bulk_session_id))->assertForbidden();

        // View alone is not edit / delete / end.
        $viewer = $this->userWith('view bulk-sessions');
        $this->actingAs($viewer);
        $this->get(route('bulk-sessions.index'))->assertOk();
        $this->postJson(route('bulk-sessions.store'), $this->payload())->assertForbidden();
        $this->deleteJson(route('bulk-sessions.destroy', $session->bulk_session_id))->assertForbidden();
    }

    /* -------- page and feed -------- */

    public function test_the_page_renders_the_table_and_both_drawers(): void
    {
        $this->actingAs($this->userWith('view bulk-sessions', 'create bulk-sessions'))
            ->get(route('bulk-sessions.index'))
            ->assertOk()
            ->assertSee('id="bulk-sessions-table"', false)
            ->assertSee('id="create-bulk-session-form"', false)
            ->assertSee('id="edit-bulk-session-form"', false)
            ->assertSee('name="min_quantity"', false)
            // No stock boxes: a bulk session has no stock.
            ->assertDontSee('name="stocks[', false)
            ->assertDontSee('loaded_stocks', false)
            ->assertSee('data-no-export', false);
    }

    public function test_the_feed_lists_sessions_with_counts_status_and_buttons_per_permission(): void
    {
        $creator = $this->userWith('view bulk-sessions', 'edit bulk-sessions', 'end bulk-sessions', 'delete bulk-sessions');
        $session = BulkSession::factory()->create(['created_by' => $creator->id, 'title' => 'Fiesta <b>trays</b>', 'min_quantity' => 15]);
        BulkSessionDish::factory()->create(['bulk_session_id' => $session->bulk_session_id]);
        BulkOrder::factory()->create(['bulk_session_id' => $session->bulk_session_id]);

        $row = $this->actingAs($creator)
            ->getJson(route('bulk-sessions.index'), $this->ajax())
            ->assertOk()
            ->json('data.0');

        $this->assertSame(15, (int) $row['min_quantity']);
        $this->assertSame(1, (int) $row['dishes_count']);
        $this->assertSame(1, (int) $row['orders_count']);
        $this->assertStringContainsString('data-status="live"', $row['status']);
        // The title is typed by staff: it must come back escaped.
        $this->assertStringNotContainsString('<b>', $row['title']);
        $this->assertStringContainsString('data-row-action="edit"', $row['action']);
        $this->assertStringContainsString('data-row-action="end"', $row['action']);
        $this->assertStringContainsString('data-row-action="delete"', $row['action']);

        // Somebody else sees no End button (only the creator closes it) and no
        // buttons they hold no permission for.
        $other = $this->userWith('view bulk-sessions', 'end bulk-sessions');
        $row = $this->actingAs($other)
            ->getJson(route('bulk-sessions.index'), $this->ajax())
            ->json('data.0');

        $this->assertStringNotContainsString('data-row-action="end"', $row['action']);
        $this->assertStringNotContainsString('data-row-action="edit"', $row['action']);
        $this->assertStringNotContainsString('data-row-action="delete"', $row['action']);
        $this->assertStringContainsString(route('bulk-sessions.show', $session->bulk_session_id), $row['action']);
    }

    public function test_the_show_page_lists_the_facts_and_dishes_without_stock(): void
    {
        $session = BulkSession::factory()->create(['title' => 'Office party', 'min_quantity' => 30]);
        BulkSessionDish::factory()->create([
            'bulk_session_id' => $session->bulk_session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Pork Sisig', 'price' => 150])->dish_id,
        ]);

        $this->actingAs($this->userWith('view bulk-sessions'))
            ->get(route('bulk-sessions.show', $session->bulk_session_id))
            ->assertOk()
            ->assertSee('Office party')
            ->assertSee('Pork Sisig')
            ->assertSee('₱150.00')
            ->assertSee('30')
            ->assertDontSee('Stock Left')
            ->assertDontSee('id="end-session"', false);

        $this->actingAs($this->userWith('view bulk-sessions'))
            ->get(route('bulk-sessions.show', 999999))
            ->assertNotFound();
    }

    public function test_only_the_creator_with_the_permission_gets_the_end_button_on_the_page(): void
    {
        $creator = $this->userWith('view bulk-sessions', 'end bulk-sessions');
        $session = BulkSession::factory()->create(['created_by' => $creator->id]);

        $this->actingAs($creator)->get(route('bulk-sessions.show', $session->bulk_session_id))
            ->assertSee('id="end-session"', false);

        $this->actingAs($this->userWith('view bulk-sessions', 'end bulk-sessions'))
            ->get(route('bulk-sessions.show', $session->bulk_session_id))
            ->assertDontSee('id="end-session"', false);
    }

    /* -------- create / update -------- */

    public function test_a_bulk_session_is_created_with_its_dishes_and_minimum(): void
    {
        $admin = $this->superAdmin();
        $dishes = Dish::factory()->count(2)->create();

        $this->actingAs($admin)
            ->postJson(route('bulk-sessions.store'), $this->payload([
                'dish_ids' => $dishes->pluck('dish_id')->all(),
            ]))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $session = BulkSession::getAll()->first();

        $this->assertSame('Christmas trays', $session->title);
        $this->assertSame(20, (int) $session->min_quantity);
        $this->assertSame($admin->id, (int) $session->created_by);
        $this->assertSame(2, (int) $session->dishes_count);
        $this->assertSame('scheduled', $session->status);
    }

    public function test_the_title_is_optional(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('bulk-sessions.store'), $this->payload(['title' => null]))
            ->assertOk();

        $this->assertNull(BulkSession::getAll()->first()->title);
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->superAdmin());

        $this->postJson(route('bulk-sessions.store'), $this->payload(['min_quantity' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['min_quantity' => 'many']))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['min_quantity' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['dish_ids' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('dish_ids');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['dish_ids' => [987654]]))
            ->assertStatus(422)->assertJsonValidationErrors('dish_ids.0');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['start_time' => '10:00', 'end_time' => '09:00']))
            ->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['order_date' => 'someday']))
            ->assertStatus(422)->assertJsonValidationErrors('order_date');
        $this->postJson(route('bulk-sessions.store'), $this->payload(['title' => str_repeat('x', 121)]))
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $this->assertSame(0, BulkSession::getAll()->count());
    }

    public function test_two_open_windows_cannot_overlap_but_back_to_back_and_other_days_can(): void
    {
        $admin = $this->superAdmin();
        $date = now()->addDays(5)->toDateString();
        BulkSession::factory()->create(['order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);

        $this->actingAs($admin);

        $this->postJson(route('bulk-sessions.store'), $this->payload([
            'order_date' => $date, 'start_time' => '11:00', 'end_time' => '14:00',
        ]))->assertStatus(422)->assertJsonValidationErrors('start_time');

        $this->postJson(route('bulk-sessions.store'), $this->payload([
            'order_date' => $date, 'start_time' => '12:00', 'end_time' => '14:00',
        ]))->assertOk();

        $this->postJson(route('bulk-sessions.store'), $this->payload([
            'order_date' => now()->addDays(6)->toDateString(), 'start_time' => '09:00', 'end_time' => '12:00',
        ]))->assertOk();

        $this->assertSame(3, BulkSession::getAll()->count());
    }

    public function test_an_ended_session_does_not_block_a_new_window(): void
    {
        $date = now()->addDays(2)->toDateString();
        $old = BulkSession::factory()->create(['order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        BulkSession::endSession($old->bulk_session_id);

        $this->actingAs($this->superAdmin())
            ->postJson(route('bulk-sessions.store'), $this->payload(['order_date' => $date]))
            ->assertOk();
    }

    public function test_an_edit_can_keep_its_own_window_but_not_move_onto_another(): void
    {
        $date = now()->addDays(4)->toDateString();
        $first = BulkSession::factory()->create(['order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
        BulkSession::factory()->create(['order_date' => $date, 'start_time' => '13:00:00', 'end_time' => '16:00:00']);

        $this->actingAs($this->superAdmin());

        // Same window, new minimum: not an overlap with itself.
        $this->putJson(route('bulk-sessions.update', $first->bulk_session_id), $this->payload([
            'order_date' => $date, 'start_time' => '09:00', 'end_time' => '12:00', 'min_quantity' => 50,
        ]))->assertOk();

        $this->assertSame(50, (int) BulkSession::findById($first->bulk_session_id)->min_quantity);

        // Stretched onto the other session's window: refused, nothing changed.
        $this->putJson(route('bulk-sessions.update', $first->bulk_session_id), $this->payload([
            'order_date' => $date, 'start_time' => '09:00', 'end_time' => '14:00', 'min_quantity' => 70,
        ]))->assertStatus(422)->assertJsonValidationErrors('start_time');

        $this->assertSame(50, (int) BulkSession::findById($first->bulk_session_id)->min_quantity);
    }

    public function test_update_replaces_the_dishes_and_returns_404_for_a_missing_session(): void
    {
        $admin = $this->superAdmin();
        $a = Dish::factory()->create();
        $b = Dish::factory()->create();
        $session = BulkSession::factory()->create();
        BulkSession::syncDishes($session->bulk_session_id, [$a->dish_id]);

        $this->actingAs($admin)
            ->putJson(route('bulk-sessions.update', $session->bulk_session_id), $this->payload([
                'dish_ids' => [$b->dish_id],
            ]))
            ->assertOk();

        $this->assertSame([$b->dish_id], array_column(BulkSession::dishesOfSession($session->bulk_session_id), 'dish_id'));

        $this->putJson(route('bulk-sessions.update', 999999), $this->payload())->assertNotFound();
        $this->getJson(route('bulk-sessions.edit', 999999))->assertNotFound();
    }

    public function test_the_edit_drawer_gets_the_session_with_its_dishes(): void
    {
        $session = BulkSession::factory()->create(['min_quantity' => 12]);
        $dish = Dish::factory()->create();
        BulkSession::syncDishes($session->bulk_session_id, [$dish->dish_id]);

        $this->actingAs($this->userWith('edit bulk-sessions'))
            ->getJson(route('bulk-sessions.edit', $session->bulk_session_id))
            ->assertOk()
            ->assertJsonPath('min_quantity', 12)
            ->assertJsonPath('dishes.0.dish_id', $dish->dish_id);
    }

    /* -------- end / delete -------- */

    public function test_only_the_creator_can_end_a_session_and_only_once(): void
    {
        $creator = $this->superAdmin();
        $session = BulkSession::factory()->create(['created_by' => $creator->id]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('bulk-sessions.end', $session->bulk_session_id))
            ->assertForbidden();
        $this->assertSame('live', BulkSession::findById($session->bulk_session_id)->status);

        $this->actingAs($creator)
            ->postJson(route('bulk-sessions.end', $session->bulk_session_id))
            ->assertOk();
        $this->assertSame('ended', BulkSession::findById($session->bulk_session_id)->status);

        $this->postJson(route('bulk-sessions.end', $session->bulk_session_id))->assertStatus(422);
        $this->postJson(route('bulk-sessions.end', 999999))->assertNotFound();
    }

    public function test_a_session_without_orders_is_deleted_and_one_with_orders_is_not(): void
    {
        $admin = $this->superAdmin();
        $empty = BulkSession::factory()->create();
        $busy = BulkSession::factory()->create();
        BulkOrder::factory()->create(['bulk_session_id' => $busy->bulk_session_id]);

        $this->actingAs($admin);

        $this->deleteJson(route('bulk-sessions.destroy', $busy->bulk_session_id))->assertStatus(422);
        $this->assertTrue(BulkSession::existsById($busy->bulk_session_id));

        $this->deleteJson(route('bulk-sessions.destroy', $empty->bulk_session_id))->assertOk();
        $this->assertFalse(BulkSession::existsById($empty->bulk_session_id));
        $this->deleteJson(route('bulk-sessions.destroy', $empty->bulk_session_id))->assertNotFound();
    }

    /* -------- the rest of the app -------- */

    public function test_the_nav_and_the_dashboard_link_to_it_only_with_the_permission(): void
    {
        $this->actingAs($this->userWith('view bulk-sessions'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('bulk-sessions.index'));

        $this->actingAs($this->userWith('view menu'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('bulk-sessions.index'));
    }

    public function test_regular_order_sessions_are_untouched_by_a_live_bulk_session(): void
    {
        BulkSession::factory()->create();

        $this->assertNull(\App\Models\OrderSession::live());
    }
}
