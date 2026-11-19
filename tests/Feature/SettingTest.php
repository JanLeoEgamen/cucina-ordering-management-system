<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// System configuration: the default start / end time of a new order session, and the
// minimum / maximum plates of a pre-order.
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

    public function test_an_editor_gets_the_unsaved_change_indicator_and_a_viewer_does_not(): void
    {
        // The wording the script shows for "Edited" / "Was" / "Undo" and the counter.
        $this->actingAs($this->userWith('view settings', 'edit settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('id="settings-changes"', false)
            ->assertSee('data-label-edited="Edited"', false)
            ->assertSee('data-label-was="Was"', false)
            ->assertSee('data-label-undo="Undo"', false)
            ->assertSee('data-label-changes=":n unsaved changes"', false);

        $this->actingAs($this->userWith('view settings'))->get(route('settings.index'))
            ->assertOk()->assertDontSee('id="settings-changes"', false);
    }

    /* -------- pre-order limits -------- */

    public function test_the_pre_order_limits_default_to_a_minimum_of_ten_and_no_maximum(): void
    {
        $this->assertSame(['min' => 10, 'max' => null], Setting::preOrderLimits());
    }

    public function test_the_page_shows_the_pre_order_limits(): void
    {
        Setting::setPreOrderLimits(25, 400);

        $this->actingAs($this->userWith('view settings'))->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="pre_order_min_quantity"', false)
            ->assertSee('name="pre_order_max_quantity"', false)
            ->assertSee('value="25"', false)
            ->assertSee('value="400"', false);
    }

    public function test_saving_stores_the_pre_order_limits_and_an_empty_maximum_means_no_limit(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['pre_order_min_quantity' => 20, 'pre_order_max_quantity' => 300]))
            ->assertOk();
        $this->assertSame(['min' => 20, 'max' => 300], Setting::preOrderLimits());

        // The form sends an empty box when the maximum is cleared.
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['pre_order_min_quantity' => 20, 'pre_order_max_quantity' => '']))
            ->assertOk();
        $this->assertSame(['min' => 20, 'max' => null], Setting::preOrderLimits());
    }

    public function test_customer_cancel_is_off_until_switched_on_and_saved_from_the_page(): void
    {
        $admin = $this->superAdmin();

        $this->assertFalse(Setting::customerCancel());
        $this->actingAs($admin)->get(route('settings.index'))->assertOk()->assertSee('name="customer_cancel"', false);

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['customer_cancel' => 1]))->assertOk();
        $this->assertTrue(Setting::customerCancel());
        $this->actingAs($admin)->get(route('settings.index'))->assertSee('id="customer-cancel"', false);

        // A save that leaves it out changes nothing...
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload())->assertOk();
        $this->assertTrue(Setting::customerCancel());

        // ...and the form always sends a 0 for an un-ticked switch.
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['customer_cancel' => 0]))->assertOk();
        $this->assertFalse(Setting::customerCancel());

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['customer_cancel' => 'maybe']))->assertStatus(422);
    }

    public function test_saving_without_the_pre_order_fields_leaves_the_limits_alone(): void
    {
        Setting::setPreOrderLimits(30, 90);

        $this->actingAs($this->superAdmin())->putJson(route('settings.update'), $this->payload())->assertOk();

        $this->assertSame(['min' => 30, 'max' => 90], Setting::preOrderLimits());
    }

    public function test_the_pre_order_limits_are_validated(): void
    {
        $admin = $this->superAdmin();

        foreach ([0, -5, 'many', '', 100001] as $bad) {
            $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['pre_order_min_quantity' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors('pre_order_min_quantity');
        }

        // The maximum cannot be below the minimum (equal is fine).
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['pre_order_min_quantity' => 50, 'pre_order_max_quantity' => 49]))
            ->assertStatus(422)->assertJsonValidationErrors('pre_order_max_quantity');
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['pre_order_min_quantity' => 50, 'pre_order_max_quantity' => 'lots']))
            ->assertStatus(422)->assertJsonValidationErrors('pre_order_max_quantity');
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['pre_order_min_quantity' => 50, 'pre_order_max_quantity' => 50]))
            ->assertOk();

        // A refused save changes nothing, not even the times (08:30 is the last good save).
        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['session_start_time' => '05:00', 'pre_order_min_quantity' => 0]))
            ->assertStatus(422);
        $this->assertSame('08:30', Setting::get(Setting::SESSION_START_TIME));
    }

    public function test_a_damaged_maximum_reads_as_no_limit(): void
    {
        Setting::setPreOrderLimits(10, null);
        Setting::set(Setting::PRE_ORDER_MAX_QUANTITY, 'abc');
        $this->assertNull(Setting::preOrderLimits()['max']);

        // A maximum below the minimum would make ordering impossible: ignored.
        Setting::set(Setting::PRE_ORDER_MAX_QUANTITY, '5');
        $this->assertNull(Setting::preOrderLimits()['max']);

        Setting::set(Setting::PRE_ORDER_MIN_QUANTITY, '0');
        $this->assertSame(10, Setting::preOrderLimits()['min']);
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
