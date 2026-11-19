<?php

namespace Tests\Feature;

use App\Models\PreOrder;
use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Dish;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The staff side of Pre-Orders: the Pre-Order Sessions page, its feed, create / edit /
// end / delete, the overlap and minimum rules, and who may do what.
class PreOrderSessionTest extends TestCase
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
        Setting::setPreOrderLimits(15, 100);
        $this->actingAs($this->superAdmin());

        $this->postJson(route('pre-order-sessions.store'), $this->payload(['min_quantity' => 14]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['min_quantity' => 101]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');

        $this->assertDatabaseCount('pre_order_sessions', 0);

        // Both ends are allowed.
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['min_quantity' => 15]))->assertOk();
        $this->assertSame(1, PreOrderSession::query()->count());
    }

    public function test_the_new_session_drawer_starts_from_the_system_minimum(): void
    {
        Setting::setPreOrderLimits(35, 200);

        $this->actingAs($this->userWith('view pre-order-sessions', 'create pre-order-sessions'))
            ->get(route('pre-order-sessions.index'))
            ->assertOk()
            ->assertSee('data-default-min="35"', false)
            ->assertSee('max="200"', false);
    }

    /* -------- access -------- */

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('pre-order-sessions.index'))->assertRedirect(route('login'));
        $this->post(route('pre-order-sessions.store'), [])->assertRedirect(route('login'));
    }

    public function test_every_action_needs_its_own_permission(): void
    {
        $session = PreOrderSession::factory()->create();
        $nobody = $this->userWith('view menu');

        $this->actingAs($nobody);
        $this->get(route('pre-order-sessions.index'))->assertForbidden();
        $this->get(route('pre-order-sessions.show', $session->pre_order_session_id))->assertForbidden();
        $this->post(route('pre-order-sessions.store'), $this->payload())->assertForbidden();
        $this->getJson(route('pre-order-sessions.edit', $session->pre_order_session_id))->assertForbidden();
        $this->putJson(route('pre-order-sessions.update', $session->pre_order_session_id), $this->payload())->assertForbidden();
        $this->postJson(route('pre-order-sessions.end', $session->pre_order_session_id))->assertForbidden();
        $this->deleteJson(route('pre-order-sessions.destroy', $session->pre_order_session_id))->assertForbidden();

        // View alone is not edit / delete / end.
        $viewer = $this->userWith('view pre-order-sessions');
        $this->actingAs($viewer);
        $this->get(route('pre-order-sessions.index'))->assertOk();
        $this->postJson(route('pre-order-sessions.store'), $this->payload())->assertForbidden();
        $this->deleteJson(route('pre-order-sessions.destroy', $session->pre_order_session_id))->assertForbidden();
    }

    /* -------- page and feed -------- */

    public function test_the_page_renders_the_table_and_both_drawers(): void
    {
        $this->actingAs($this->userWith('view pre-order-sessions', 'create pre-order-sessions'))
            ->get(route('pre-order-sessions.index'))
            ->assertOk()
            ->assertSee('id="pre-order-sessions-table"', false)
            ->assertSee('id="create-pre-order-session-form"', false)
            ->assertSee('id="edit-pre-order-session-form"', false)
            ->assertSee('name="min_quantity"', false)
            // No stock boxes: a pre-order session has no stock.
            ->assertDontSee('name="stocks[', false)
            ->assertDontSee('loaded_stocks', false)
            ->assertSee('data-no-export', false);
    }

    public function test_the_feed_lists_sessions_with_counts_status_and_buttons_per_permission(): void
    {
        $creator = $this->userWith('view pre-order-sessions', 'edit pre-order-sessions', 'end pre-order-sessions', 'delete pre-order-sessions');
        $session = PreOrderSession::factory()->create(['created_by' => $creator->id, 'title' => 'Fiesta <b>trays</b>', 'min_quantity' => 15]);
        PreOrderSessionDish::factory()->create(['pre_order_session_id' => $session->pre_order_session_id]);
        PreOrder::factory()->create(['pre_order_session_id' => $session->pre_order_session_id]);

        $row = $this->actingAs($creator)
            ->getJson(route('pre-order-sessions.index'), $this->ajax())
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
        $other = $this->userWith('view pre-order-sessions', 'end pre-order-sessions');
        $row = $this->actingAs($other)
            ->getJson(route('pre-order-sessions.index'), $this->ajax())
            ->json('data.0');

        $this->assertStringNotContainsString('data-row-action="end"', $row['action']);
        $this->assertStringNotContainsString('data-row-action="edit"', $row['action']);
        $this->assertStringNotContainsString('data-row-action="delete"', $row['action']);
        $this->assertStringContainsString(route('pre-order-sessions.show', $session->pre_order_session_id), $row['action']);
    }

    public function test_the_show_page_lists_the_facts_and_dishes_without_stock(): void
    {
        $session = PreOrderSession::factory()->create(['title' => 'Office party', 'min_quantity' => 30]);
        PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $session->pre_order_session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Pork Sisig', 'price' => 150])->dish_id,
        ]);

        $this->actingAs($this->userWith('view pre-order-sessions'))
            ->get(route('pre-order-sessions.show', $session->pre_order_session_id))
            ->assertOk()
            ->assertSee('Office party')
            ->assertSee('Pork Sisig')
            ->assertSee('₱150.00')
            ->assertSee('30')
            ->assertDontSee('Stock Left')
            ->assertDontSee('id="end-session"', false);

        $this->actingAs($this->userWith('view pre-order-sessions'))
            ->get(route('pre-order-sessions.show', 999999))
            ->assertNotFound();
    }

    public function test_only_the_creator_with_the_permission_gets_the_end_button_on_the_page(): void
    {
        $creator = $this->userWith('view pre-order-sessions', 'end pre-order-sessions');
        $session = PreOrderSession::factory()->create(['created_by' => $creator->id]);

        $this->actingAs($creator)->get(route('pre-order-sessions.show', $session->pre_order_session_id))
            ->assertSee('id="end-session"', false);

        $this->actingAs($this->userWith('view pre-order-sessions', 'end pre-order-sessions'))
            ->get(route('pre-order-sessions.show', $session->pre_order_session_id))
            ->assertDontSee('id="end-session"', false);
    }

    /* -------- create / update -------- */

    public function test_a_pre_order_session_is_created_with_its_dishes_and_minimum(): void
    {
        $admin = $this->superAdmin();
        $dishes = Dish::factory()->count(2)->create();

        $this->actingAs($admin)
            ->postJson(route('pre-order-sessions.store'), $this->payload([
                'dish_ids' => $dishes->pluck('dish_id')->all(),
            ]))
            ->assertOk()
            ->assertJson(['status' => 'success']);

        $session = PreOrderSession::getAll()->first();

        $this->assertSame('Christmas trays', $session->title);
        $this->assertSame(20, (int) $session->min_quantity);
        $this->assertSame($admin->id, (int) $session->created_by);
        $this->assertSame(2, (int) $session->dishes_count);
        $this->assertSame('scheduled', $session->status);
    }

    public function test_the_title_is_optional(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('pre-order-sessions.store'), $this->payload(['title' => null]))
            ->assertOk();

        $this->assertNull(PreOrderSession::getAll()->first()->title);
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->superAdmin());

        $this->postJson(route('pre-order-sessions.store'), $this->payload(['min_quantity' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['min_quantity' => 'many']))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['min_quantity' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['dish_ids' => []]))
            ->assertStatus(422)->assertJsonValidationErrors('dish_ids');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['dish_ids' => [987654]]))
            ->assertStatus(422)->assertJsonValidationErrors('dish_ids.0');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['start_time' => '10:00', 'end_time' => '09:00']))
            ->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['order_date' => 'someday']))
            ->assertStatus(422)->assertJsonValidationErrors('order_date');
        $this->postJson(route('pre-order-sessions.store'), $this->payload(['title' => str_repeat('x', 121)]))
            ->assertStatus(422)->assertJsonValidationErrors('title');

        $this->assertSame(0, PreOrderSession::getAll()->count());
    }

    public function test_two_open_windows_cannot_overlap_but_back_to_back_and_other_days_can(): void
    {
        $admin = $this->superAdmin();
        $date = now()->addDays(5)->toDateString();
        PreOrderSession::factory()->create(['order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);

        $this->actingAs($admin);

        $this->postJson(route('pre-order-sessions.store'), $this->payload([
            'order_date' => $date, 'start_time' => '11:00', 'end_time' => '14:00',
        ]))->assertStatus(422)->assertJsonValidationErrors('start_time');

        $this->postJson(route('pre-order-sessions.store'), $this->payload([
            'order_date' => $date, 'start_time' => '12:00', 'end_time' => '14:00',
        ]))->assertOk();

        $this->postJson(route('pre-order-sessions.store'), $this->payload([
            'order_date' => now()->addDays(6)->toDateString(), 'start_time' => '09:00', 'end_time' => '12:00',
        ]))->assertOk();

        $this->assertSame(3, PreOrderSession::getAll()->count());
    }

    public function test_an_ended_session_does_not_block_a_new_window(): void
    {
        $date = now()->addDays(2)->toDateString();
        $old = PreOrderSession::factory()->create(['order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        PreOrderSession::endSession($old->pre_order_session_id);

        $this->actingAs($this->superAdmin())
            ->postJson(route('pre-order-sessions.store'), $this->payload(['order_date' => $date]))
            ->assertOk();
    }

    public function test_an_edit_can_keep_its_own_window_but_not_move_onto_another(): void
    {
        $date = now()->addDays(4)->toDateString();
        $first = PreOrderSession::factory()->create(['order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '12:00:00']);
        PreOrderSession::factory()->create(['order_date' => $date, 'start_time' => '13:00:00', 'end_time' => '16:00:00']);

        $this->actingAs($this->superAdmin());

        // Same window, new minimum: not an overlap with itself.
        $this->putJson(route('pre-order-sessions.update', $first->pre_order_session_id), $this->payload([
            'order_date' => $date, 'start_time' => '09:00', 'end_time' => '12:00', 'min_quantity' => 50,
        ]))->assertOk();

        $this->assertSame(50, (int) PreOrderSession::findById($first->pre_order_session_id)->min_quantity);

        // Stretched onto the other session's window: refused, nothing changed.
        $this->putJson(route('pre-order-sessions.update', $first->pre_order_session_id), $this->payload([
            'order_date' => $date, 'start_time' => '09:00', 'end_time' => '14:00', 'min_quantity' => 70,
        ]))->assertStatus(422)->assertJsonValidationErrors('start_time');

        $this->assertSame(50, (int) PreOrderSession::findById($first->pre_order_session_id)->min_quantity);
    }

    public function test_update_replaces_the_dishes_and_returns_404_for_a_missing_session(): void
    {
        $admin = $this->superAdmin();
        $a = Dish::factory()->create();
        $b = Dish::factory()->create();
        $session = PreOrderSession::factory()->create();
        PreOrderSession::syncDishes($session->pre_order_session_id, [$a->dish_id]);

        $this->actingAs($admin)
            ->putJson(route('pre-order-sessions.update', $session->pre_order_session_id), $this->payload([
                'dish_ids' => [$b->dish_id],
            ]))
            ->assertOk();

        $this->assertSame([$b->dish_id], array_column(PreOrderSession::dishesOfSession($session->pre_order_session_id), 'dish_id'));

        $this->putJson(route('pre-order-sessions.update', 999999), $this->payload())->assertNotFound();
        $this->getJson(route('pre-order-sessions.edit', 999999))->assertNotFound();
    }

    public function test_the_edit_drawer_gets_the_session_with_its_dishes(): void
    {
        $session = PreOrderSession::factory()->create(['min_quantity' => 12]);
        $dish = Dish::factory()->create();
        PreOrderSession::syncDishes($session->pre_order_session_id, [$dish->dish_id]);

        $this->actingAs($this->userWith('edit pre-order-sessions'))
            ->getJson(route('pre-order-sessions.edit', $session->pre_order_session_id))
            ->assertOk()
            ->assertJsonPath('min_quantity', 12)
            ->assertJsonPath('dishes.0.dish_id', $dish->dish_id);
    }

    /* -------- end / delete -------- */

    public function test_only_the_creator_can_end_a_session_and_only_once(): void
    {
        $creator = $this->superAdmin();
        $session = PreOrderSession::factory()->create(['created_by' => $creator->id]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('pre-order-sessions.end', $session->pre_order_session_id))
            ->assertForbidden();
        $this->assertSame('live', PreOrderSession::findById($session->pre_order_session_id)->status);

        $this->actingAs($creator)
            ->postJson(route('pre-order-sessions.end', $session->pre_order_session_id))
            ->assertOk();
        $this->assertSame('ended', PreOrderSession::findById($session->pre_order_session_id)->status);

        $this->postJson(route('pre-order-sessions.end', $session->pre_order_session_id))->assertStatus(422);
        $this->postJson(route('pre-order-sessions.end', 999999))->assertNotFound();
    }

    public function test_a_session_without_orders_is_deleted_and_one_with_orders_is_not(): void
    {
        $admin = $this->superAdmin();
        $empty = PreOrderSession::factory()->create();
        $busy = PreOrderSession::factory()->create();
        PreOrder::factory()->create(['pre_order_session_id' => $busy->pre_order_session_id]);

        $this->actingAs($admin);

        $this->deleteJson(route('pre-order-sessions.destroy', $busy->pre_order_session_id))->assertStatus(422);
        $this->assertTrue(PreOrderSession::existsById($busy->pre_order_session_id));

        $this->deleteJson(route('pre-order-sessions.destroy', $empty->pre_order_session_id))->assertOk();
        $this->assertFalse(PreOrderSession::existsById($empty->pre_order_session_id));
        $this->deleteJson(route('pre-order-sessions.destroy', $empty->pre_order_session_id))->assertNotFound();
    }

    /* -------- the rest of the app -------- */

    public function test_the_nav_and_the_dashboard_link_to_it_only_with_the_permission(): void
    {
        $this->actingAs($this->userWith('view pre-order-sessions'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('pre-order-sessions.index'));

        $this->actingAs($this->userWith('view menu'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('pre-order-sessions.index'));
    }

    public function test_regular_order_sessions_are_untouched_by_a_live_pre_order_session(): void
    {
        PreOrderSession::factory()->create();

        $this->assertNull(\App\Models\OrderSession::live());
    }
}
