<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\Role;
use App\Support\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The dashboard's widgets (config/dashboard.php): each is behind its own permission, both on the
// page and, more importantly, where its data is read.
class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPayment(): void
    {
        $order = Order::factory()->create(['session_id' => OrderSession::factory()->create()->session_id]);
        Payment::factory()->create(['order_id' => $order->order_id, 'payment_status' => 'pending']);
    }

    public function test_every_widget_is_complete_and_its_permission_exists(): void
    {
        $widgets = config('dashboard.widgets');

        $this->assertNotEmpty($widgets);

        foreach ($widgets as $key => $widget) {
            $this->assertContains($widget['permission'], Role::PERMISSIONS, $key);
            $this->assertEmpty(array_diff($widget['requires'] ?? [], Role::PERMISSIONS), $key);
            $this->assertTrue(view()->exists($widget['view']), $key);
            $this->assertTrue(is_callable($widget['data']), $key);
            $this->assertContains($widget['row'], Role::PERMISSION_SECTIONS['Dashboard and analytics'], $key);
        }
    }

    public function test_a_person_with_the_widget_and_its_page_sees_it(): void
    {
        $this->pendingPayment();
        $user = $this->userWith('view dashboard-attention', 'view payments');

        $this->assertSame([1], array_column(Dashboard::widgetsFor($user)['attention']['data']['lines'], 'count'));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Needs attention')
            ->assertSee('payment to verify');
    }

    public function test_without_the_widget_permission_nothing_is_drawn_or_read(): void
    {
        $this->pendingPayment();
        // They may open the Payments page, but were not given the widget.
        $user = $this->userWith('view payments');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('widgets', [])
            ->assertDontSee('Needs attention')
            // The "Jump to" tile and the sidebar still lead there.
            ->assertSee(route('payments.index'), false);
    }

    public function test_the_widget_permission_alone_does_not_reveal_the_payments(): void
    {
        $this->pendingPayment();
        $user = $this->userWith('view dashboard-attention');

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('widgets', [])
            ->assertDontSee('Needs attention')
            ->assertDontSee(route('payments.index'), false);

        $this->assertEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'from "payments"')));
    }

    public function test_the_session_banner_stays_for_everyone_and_guests_are_sent_to_sign_in(): void
    {
        $this->actingAs($this->userWith())->get(route('dashboard'))->assertOk()->assertSee('No session is running');

        auth()->logout();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_the_customer_role_gets_no_staff_widget(): void
    {
        $this->seedPermissions();

        $this->assertNotContains('view dashboard-attention', Role::CUSTOMER_PERMISSIONS);
    }

    public function test_the_roles_picker_lists_the_widgets_as_chips_of_one_row(): void
    {
        $this->seedPermissions();

        $section = collect(Role::permissionGroups())->firstWhere('label', 'Dashboard and analytics');

        // The Analytics page's rows follow the dashboard's (AnalyticsTest looks at those).
        $this->assertSame(['Dashboard', 'My Dashboard', 'System Checks'], array_slice(array_column($section['resources'], 'label'), 0, 3));
        $this->assertSame(
            // In the order of Role::PERMISSIONS: Insights came later.
            ['Attention', 'Today', 'Live Session', 'Cash Expected', 'Unclaimed Orders', 'Insights'],
            array_column($section['resources'][0]['permissions'], 'action')
        );
        // The full name is what the picker's search and its "View only" button read.
        $this->assertSame('View Dashboard Attention', $section['resources'][0]['permissions'][0]['name']);
    }

    public function test_the_upgrade_gives_the_widget_to_the_roles_that_saw_the_card(): void
    {
        $this->seedPermissions();

        // An existing database from before the widget: no such permission yet.
        DB::table('permissions')->where('name', 'view dashboard-attention')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $cashier = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo('view payments');
        $cook = Role::create(['name' => 'Cook', 'guard_name' => 'web']);
        $cook->givePermissionTo('view dishes');

        $migration = require database_path('migrations/2026_11_26_100100_grant_dashboard_attention_permission.php');
        $migration->up();
        // Running it twice changes nothing.
        $migration->up();

        $this->assertTrue($cashier->fresh()->hasPermissionTo('view dashboard-attention'));
        $this->assertTrue(Role::findByName(Role::SUPER_ADMIN)->hasPermissionTo('view dashboard-attention'));
        $this->assertFalse($cook->fresh()->hasPermissionTo('view dashboard-attention'));
        $this->assertFalse(Role::findByName(Role::CUSTOMER)->hasPermissionTo('view dashboard-attention'));
    }

    public function test_the_upgrade_does_nothing_on_a_database_without_roles(): void
    {
        $migration = require database_path('migrations/2026_11_26_100100_grant_dashboard_attention_permission.php');
        $migration->up();

        $this->assertDatabaseMissing('permissions', ['name' => 'view dashboard-attention']);
    }
}
