<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\OrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSessionIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_offers_table_card_and_calendar_views(): void
    {
        $this->actingAs($this->userWith('view order-sessions'))
            ->get(route('order-sessions.index'))
            ->assertOk()
            ->assertSee('data-view-mode="table"', false)
            ->assertSee('data-view-mode="card"', false)
            ->assertSee('data-view-mode="calendar"', false)
            ->assertSee('id="order-sessions-cards"', false)
            ->assertSee('id="order-sessions-calendar"', false)
            ->assertSee('data-cal-nav="prev"', false)
            ->assertSee('data-cal-nav="today"', false)
            ->assertSee('data-cal-nav="next"', false);
    }

    public function test_every_session_row_carries_what_the_calendar_reads(): void
    {
        $session = OrderSession::factory()->create();

        $row = $this->actingAs($this->userWith('view order-sessions'))
            ->getJson(route('order-sessions.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data.0');

        // The calendar places a chip by its date and times, colours it by the
        // status badge, and links it with the View link of the row.
        $this->assertSame(substr((string) $session->order_date, 0, 10), substr($row['order_date'], 0, 10));
        $this->assertArrayHasKey('start_time', $row);
        $this->assertArrayHasKey('end_time', $row);
        $this->assertArrayHasKey('orders_count', $row);
        $this->assertMatchesRegularExpression('/data-status="(live|scheduled|ended)"/', $row['status']);
        $this->assertStringContainsString('href="' . route('order-sessions.show', $session->session_id) . '"', $row['action']);
    }

    public function test_the_dish_list_can_be_filtered_by_type_and_by_offered(): void
    {
        $mains = DishType::factory()->create(['type_name' => 'Mains']);
        $drinks = DishType::factory()->create(['type_name' => 'Drinks']);
        DishType::factory()->create(['type_name' => 'Unused']);

        $adobo = Dish::factory()->create(['dish_name' => 'Adobo']);
        $adobo->dishTypes()->attach($mains->dish_type_id);
        $tea = Dish::factory()->create(['dish_name' => 'Iced Tea']);
        $tea->dishTypes()->attach($drinks->dish_type_id);

        $html = $this->actingAs($this->userWith('view order-sessions'))
            ->get(route('order-sessions.index'))
            ->assertOk()
            ->assertSee('x-model="types"', false)
            ->assertSee('x-model="show"', false)
            ->assertSee(__('Not offered'))
            ->getContent();

        // Both the create and the edit list carry the filters.
        $this->assertSame(2, substr_count($html, 'x-model="types"'));

        // Only types some dish has are offered; each dish row is filtered by its id.
        $this->assertStringContainsString('>Mains</option>', $html);
        $this->assertStringContainsString('>Drinks</option>', $html);
        $this->assertStringNotContainsString('>Unused</option>', $html);
        $this->assertStringContainsString('isShown(' . $adobo->dish_id . ')', $html);
        $this->assertStringContainsString('isShown(' . $tea->dish_id . ')', $html);
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->userWith())->get(route('order-sessions.index'))->assertForbidden();
    }
}
