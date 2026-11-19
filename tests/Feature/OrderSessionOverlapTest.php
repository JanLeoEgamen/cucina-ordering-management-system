<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Known-issues F3: the menu offers ONE live order session, so two open windows may not
// overlap. Same rule as the pre-order sessions (PreOrderSessionTest), plus the legacy-data case.
class OrderSessionOverlapTest extends TestCase
{
    use RefreshDatabase;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->date = now()->addDays(3)->toDateString();
        $this->actingAs($this->superAdmin());
    }

    private function payload(array $overrides = []): array
    {
        $dishId = Dish::factory()->create()->dish_id;

        return array_merge([
            'order_date' => $this->date,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'dish_ids' => [$dishId],
            'stocks' => [$dishId => 10],
        ], $overrides);
    }

    private function openSession(string $start, string $end, ?string $date = null): OrderSession
    {
        return OrderSession::factory()->create([
            'order_date' => $date ?? $this->date,
            'start_time' => $start . ':00',
            'end_time' => $end . ':00',
        ]);
    }

    public function test_a_new_window_that_overlaps_an_open_session_is_refused(): void
    {
        $this->openSession('09:00', '12:00');

        $this->postJson(route('order-sessions.store'), $this->payload(['start_time' => '11:00', 'end_time' => '14:00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time');

        // Inside, around and identical windows clash too.
        foreach ([['09:30', '10:30'], ['08:00', '13:00'], ['09:00', '12:00']] as [$start, $end]) {
            $this->postJson(route('order-sessions.store'), $this->payload(['start_time' => $start, 'end_time' => $end]))
                ->assertStatus(422);
        }

        $this->assertSame(1, OrderSession::getAll()->count());
    }

    public function test_back_to_back_and_other_days_are_allowed(): void
    {
        $this->openSession('09:00', '12:00');

        $this->postJson(route('order-sessions.store'), $this->payload(['start_time' => '12:00', 'end_time' => '14:00']))
            ->assertOk();
        $this->postJson(route('order-sessions.store'), $this->payload(['order_date' => now()->addDays(4)->toDateString()]))
            ->assertOk();

        $this->assertSame(3, OrderSession::getAll()->count());
    }

    public function test_an_ended_or_deleted_session_does_not_block_a_new_window(): void
    {
        $ended = $this->openSession('09:00', '17:00');
        OrderSession::endSession($ended->session_id);
        $deleted = $this->openSession('09:00', '17:00');
        OrderSession::deleteSession($deleted->session_id);

        $this->postJson(route('order-sessions.store'), $this->payload())->assertOk();
    }

    public function test_an_edit_can_keep_its_own_window_but_not_move_onto_another(): void
    {
        $first = $this->openSession('09:00', '12:00');
        $this->openSession('13:00', '16:00');

        // Same window: it does not clash with itself.
        $this->putJson(route('order-sessions.update', $first->session_id), $this->payload())->assertOk();

        // Moved onto the other session.
        $this->putJson(route('order-sessions.update', $first->session_id), $this->payload(['start_time' => '11:00', 'end_time' => '14:00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time');

        // Moved into free time.
        $this->putJson(route('order-sessions.update', $first->session_id), $this->payload(['start_time' => '07:00', 'end_time' => '08:00']))
            ->assertOk();
    }

    public function test_sessions_that_already_overlapped_can_still_be_edited_without_moving_them(): void
    {
        // Made before the guard existed (the factory writes straight to the table).
        $one = $this->openSession('09:00', '12:00');
        $this->openSession('10:00', '13:00');

        $this->putJson(route('order-sessions.update', $one->session_id), $this->payload())->assertOk();

        // But moving it, still onto the other one, is refused.
        $this->putJson(route('order-sessions.update', $one->session_id), $this->payload(['start_time' => '09:00', 'end_time' => '11:00']))
            ->assertStatus(422);
    }

    public function test_an_ended_session_can_be_edited_whatever_is_open_around_it(): void
    {
        $ended = $this->openSession('09:00', '12:00');
        OrderSession::endSession($ended->session_id);
        $this->openSession('13:00', '16:00');

        $this->putJson(route('order-sessions.update', $ended->session_id), $this->payload(['start_time' => '11:00', 'end_time' => '14:00']))
            ->assertOk();
    }
}
