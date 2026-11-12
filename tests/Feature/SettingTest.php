<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// System configuration: the default start / end time of a new order session.
class SettingTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + ['session_start_time' => '08:30', 'session_end_time' => '13:00'];
    }

    public function test_the_defaults_apply_until_something_is_saved(): void
    {
        $this->assertSame(['start_time' => '09:00', 'end_time' => '17:00'], Setting::sessionWindow());
        $this->assertNull(Setting::get('no.such.key'));
    }

    public function test_the_page_shows_the_saved_times_to_those_who_may_view_it(): void
    {
        Setting::setSessionWindow('07:00', '11:30');

        $this->actingAs($this->userWith('view settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('System Configuration')
            ->assertSee('value="07:00"', false)
            ->assertSee('value="11:30"', false)
            // Read only without edit settings: no Save button.
            ->assertDontSee('id="settings-submit"', false);

        $this->actingAs($this->userWith('view settings', 'edit settings'))->get(route('settings.index'))
            ->assertOk()->assertSee('id="settings-submit"', false);

        $this->actingAs($this->userWith('view order-sessions'))->get(route('settings.index'))->assertForbidden();
    }

    public function test_saving_replaces_the_times_and_needs_edit_settings(): void
    {
        $this->actingAs($this->userWith('view settings'))
            ->putJson(route('settings.update'), $this->payload())->assertForbidden();

        $this->assertSame('09:00', Setting::get(Setting::SESSION_START_TIME));

        $this->actingAs($this->userWith('edit settings'))
            ->putJson(route('settings.update'), $this->payload())
            ->assertOk()->assertJsonPath('status', 'success');

        $this->assertSame(['start_time' => '08:30', 'end_time' => '13:00'], Setting::sessionWindow());

        // Saving again replaces the row: still one row per key.
        $this->actingAs($this->superAdmin())->putJson(route('settings.update'), $this->payload(['session_end_time' => '14:00']))->assertOk();

        $this->assertSame('14:00', Setting::get(Setting::SESSION_END_TIME));
        $this->assertDatabaseCount('settings', 2);
    }

    public function test_the_times_are_validated(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['session_start_time' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('session_start_time');

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['session_start_time' => 'noon']))
            ->assertStatus(422)->assertJsonValidationErrors('session_start_time');

        // The end must be after the start.
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['session_start_time' => '13:00', 'session_end_time' => '13:00']))
            ->assertStatus(422)->assertJsonValidationErrors('session_end_time');

        $this->assertSame('09:00', Setting::get(Setting::SESSION_START_TIME));
    }

    public function test_a_new_order_session_starts_from_the_configured_times(): void
    {
        Setting::setSessionWindow('06:15', '10:45');

        $this->actingAs($this->userWith('view order-sessions', 'create order-sessions'))
            ->get(route('order-sessions.index'))
            ->assertOk()
            ->assertSee('data-default-start="06:15"', false)
            ->assertSee('data-default-end="10:45"', false);
    }

    public function test_the_sidebar_and_dashboard_link_to_the_page_only_with_the_permission(): void
    {
        $this->actingAs($this->userWith('view settings'))->get(route('dashboard'))
            ->assertOk()->assertSee(route('settings.index'), false);

        $this->actingAs($this->userWith('view users'))->get(route('dashboard'))
            ->assertOk()->assertDontSee(route('settings.index'), false);
    }
}
