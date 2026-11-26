<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\SessionDish;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Analytics\KitchenWidgets;
use App\Support\Analytics\MoneyWidgets;
use App\Support\Analytics\Period;
use App\Support\Analytics\SalesWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SellsDishes;
use Tests\TestCase;

// The Analytics page: who gets which widget, the date range, and what every widget adds up.
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;
    use SellsDishes;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday at noon.
        $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));
    }

    /* -------- The date range -------- */

    public function test_the_presets_cover_whole_days(): void
    {
        foreach ([
            'today' => ['2026-11-26', '2026-11-26'],
            '7d' => ['2026-11-20', '2026-11-26'],
            '30d' => ['2026-10-28', '2026-11-26'],
            'month' => ['2026-11-01', '2026-11-26'],
            'last-month' => ['2026-10-01', '2026-10-31'],
        ] as $key => $days) {
            $period = $this->range($key);

            $this->assertSame($days, [$period->fromDate(), $period->toDate()], $key);
        }
    }

    public function test_each_range_is_compared_with_a_like_one_up_to_the_same_time(): void
    {
        $previous = fn (string $key) => array_values(array_intersect_key($this->range($key)->previous(), array_flip(['startAt', 'endAt'])));

        // Today: the same weekday last week, up to now.
        $this->assertSame(['2026-11-19 00:00:00', '2026-11-19 12:00:00'], $previous('today'));
        $this->assertSame(['2026-11-13 00:00:00', '2026-11-19 12:00:00'], $previous('7d'));
        $this->assertSame(['2026-10-01 00:00:00', '2026-10-26 12:00:00'], $previous('month'));
        // A finished range is compared with whole days.
        $this->assertSame(['2026-09-01 00:00:00', '2026-09-30 23:59:59'], $previous('last-month'));

        $custom = $this->between('2026-11-01', '2026-11-10')->previous();

        $this->assertSame(['2026-10-22 00:00:00', '2026-10-31 23:59:59'], [$custom['startAt'], $custom['endAt']]);
    }

    public function test_a_range_that_makes_no_sense_reads_as_the_default_and_says_so(): void
    {
        foreach ([
            ['range' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-01'],
            ['range' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-02'],
            ['range' => 'custom', 'from' => '2024-01-01', 'to' => '2026-01-01'],
            ['range' => 'custom', 'from' => 'yesterday', 'to' => '2026-11-01'],
            ['range' => 'custom', 'from' => ['a'], 'to' => '2026-11-01'],
            ['range' => 'custom'],
            ['range' => 'custom', 'from' => '2027-01-01', 'to' => '2027-01-02'],
        ] as $input) {
            $period = Period::fromInput($input);

            $this->assertSame('30d', $period->key, json_encode($input));
            $this->assertTrue($period->refused, json_encode($input));
        }

        $this->assertSame('30d', Period::fromInput(['range' => 'forever'])->key);
        $this->assertSame('30d', Period::fromInput(['range' => ['x']])->key);
        // An end in the future is cut to today; a whole leap year is allowed.
        $this->assertSame('2026-11-26', $this->between('2026-11-20', '2030-01-01')->toDate());
        $this->assertSame(366, $this->between('2024-01-01', '2024-12-31')->days());
    }

    /* -------- Who may see what -------- */

    public function test_every_widget_is_complete_and_has_its_can_block(): void
    {
        $view = file_get_contents(resource_path('views/analytics/index.blade.php'));

        foreach (config('analytics.widgets') as $key => $widget) {
            $this->assertContains($widget['permission'], Role::PERMISSIONS, $key);
            $this->assertEmpty(array_diff($widget['requires'] ?? [], Role::PERMISSIONS), $key);
            $this->assertArrayHasKey($widget['tab'], config('analytics.tabs'), $key);
            $this->assertTrue(view()->exists($widget['view']), $key);
            $this->assertTrue(is_callable($widget['data']), $key);
            $this->assertContains($widget['row'], Role::PERMISSION_SECTIONS['Dashboard and analytics'], $key);
        }

        // One gated loop draws them all: every widget sits in an @can of its own permission.
        $this->assertStringContainsString('@canany(\App\Support\Analytics::permissions())', $view);
        $this->assertStringContainsString("@can(\$widget['permission'])", $view);

        $this->assertNotContains('view analytics-sales-summary', Role::CUSTOMER_PERMISSIONS);
        $this->assertSame([], array_intersect(Analytics::permissions(), Role::CUSTOMER_PERMISSIONS));
    }

    public function test_guests_and_people_without_any_analytics_permission_are_turned_away(): void
    {
        $this->get(route('analytics.index'))->assertRedirect(route('login'));

        $this->actingAs($this->userWith('view orders', 'view payments'))->get(route('analytics.index'))->assertForbidden();

        // The widget permission without the page it lists rows of leaves nothing to show.
        $this->actingAs($this->userWith('view analytics-money-held'))->get(route('analytics.index'))->assertForbidden();
    }

    public function test_one_permission_opens_the_page_with_that_widget_alone(): void
    {
        $this->order('2026-11-25 10:00:00');

        $user = $this->userWith('view analytics-rice');

        $this->actingAs($user)->get(route('analytics.index'))
            ->assertOk()
            ->assertViewHas('tab', 'kitchen')
            ->assertViewHas('tabs', ['kitchen' => 'Kitchen'])
            ->assertViewHas('widgets', fn (array $widgets) => array_keys($widgets) === ['rice'])
            ->assertDontSee('Session scorecard');

        // Asking for another tab opens theirs, and reads nothing of the other.
        $this->actingAs($user)->get(route('analytics.index', ['tab' => 'sales']))
            ->assertViewHas('tab', 'kitchen')
            ->assertDontSee('Order status');
    }

    public function test_taking_a_permission_away_removes_that_widget_only(): void
    {
        $this->seedPermissions();

        foreach (config('analytics.widgets') as $key => $widget) {
            $user = $this->userWith(...array_values(array_diff(Role::PERMISSIONS, [$widget['permission']])));
            $shown = array_keys(Analytics::widgetsFor($user, $widget['tab'], $this->range()));
            $inTab = collect(config('analytics.widgets'))->where('tab', $widget['tab'])->count();

            $this->assertNotContains($key, $shown, $key);
            $this->assertCount($inTab - 1, $shown, $key);
        }
    }

    public function test_every_tab_renders_empty_and_with_data(): void
    {
        $admin = $this->superAdmin();

        foreach (array_keys(config('analytics.tabs')) as $tab) {
            $this->actingAs($admin)->get(route('analytics.index', ['tab' => $tab]))->assertOk();
        }

        $this->dish('Adobo', '2026-11-25', 0, 5, ['includes_rice' => true]);
        $this->order('2026-11-25 10:00:00', lines: [['Adobo', 3], ['Adobo', 2, true]], payment: ['reference_number' => 'REF-123']);
        $this->order('2026-11-25 11:00:00', 'cancelled', lines: [['Adobo', 1]], payment: ['reference_number' => 'ref-123'], order: ['cancel_reason' => 'Sold out']);
        $this->order('2026-11-26 09:00:00', 'pending', payment: ['payment_status' => 'verified', 'verified_at' => '2026-11-26 09:10:00']);

        $this->actingAs($admin)->get(route('analytics.index', ['tab' => 'sales']))->assertOk()
            ->assertSeeInOrder(['Summary', 'Sales per day', 'When people order', 'Order status', 'Sales by payment method', 'Basket size', 'Cancellations'])
            ->assertSee('data-chart=', false)->assertSee('Show as table')->assertSee('Sold out');
        $this->actingAs($admin)->get(route('analytics.index', ['tab' => 'money']))->assertOk()
            ->assertSeeInOrder(['Collections', 'Who verified it', 'Discounts', 'Review times', 'Repeated reference numbers', 'REF-123', 'Paid, not completed']);
        $this->actingAs($admin)->get(route('analytics.index', ['tab' => 'kitchen']))->assertOk()
            ->assertSeeInOrder(['Preparation suggestions', 'Dish sales', 'Leftover plates', 'Sell-out speed', 'Rice', 'Demand by weekday', 'Worth bringing back', 'Losing interest', 'New dishes', 'Ordered together', 'Session scorecard']);
        $this->actingAs($admin)->get(route('analytics.index', ['tab' => 'customers']))->assertOk()
            ->assertSeeInOrder(['Who ordered', 'Do they come back?', 'Never ordered', 'Orders by company', 'Company participation', 'Lapsed customers', 'Top customers']);
    }

    public function test_the_chart_script_marker_sits_outside_the_area_soft_navigation_swaps(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('analytics.index'))->assertOk()->getContent();

        $page = new \DOMDocument;
        @$page->loadHTML($html);
        $find = new \DOMXPath($page);

        $this->assertSame(1, $find->query('//*[@data-page-module="analytics"]')->length);
        // Inside #page-root the marker is moved away before soft-nav.js reads it: no charts.
        $this->assertSame(0, $find->query('//*[@id="page-root"]//*[@data-page-module]')->length);
    }

    public function test_the_filter_keeps_the_tab_and_the_refusal_is_said(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('analytics.index', ['tab' => 'money', 'range' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-01']))
            ->assertOk()
            ->assertSee('That date range cannot be used')
            ->assertSee(route('analytics.index', ['tab' => 'money', 'range' => '7d']))
            ->assertSee('name="tab" value="money"', false);
    }

    public function test_the_sidebar_and_dashboard_link_to_it_only_with_a_usable_permission(): void
    {
        $url = route('analytics.index');

        $this->actingAs($this->userWith('view analytics-rice'))->get(route('dashboard'))->assertSee($url, false);
        $this->actingAs($this->userWith('view orders'))->get(route('dashboard'))->assertDontSee($url, false);
        $this->actingAs($this->userWith('view analytics-money-held'))->get(route('dashboard'))->assertDontSee($url, false);
    }

    public function test_the_roles_picker_lists_them_on_a_few_rows(): void
    {
        $this->seedPermissions();

        $section = collect(Role::permissionGroups())->firstWhere('label', 'Dashboard and analytics');
        $rows = collect($section['resources'])->keyBy('label');

        $this->assertCount(7, $rows['Sales Analytics']['permissions']);
        $this->assertCount(5, $rows['Money Analytics']['permissions']);
        $this->assertCount(11, $rows['Kitchen Analytics']['permissions']);
        $this->assertCount(7, $rows['Customer Analytics']['permissions']);
        $this->assertSame(['Export'], array_column($rows['Analytics']['permissions'], 'action'));
    }

    public function test_the_upgrade_gives_them_to_super_admin_only(): void
    {
        $this->seedPermissions();
        // What THAT migration hands out (later widgets have one of their own).
        $first = array_map(fn (string $widget) => 'view analytics-' . $widget, [
            'sales-summary', 'sales-trend', 'order-status', 'payment-methods', 'basket-size', 'cancelled-value',
            'collections', 'duplicate-references', 'money-held', 'dish-sales', 'sell-through', 'sellout-speed',
            'rice', 'session-scorecard',
        ]);
        DB::table('permissions')->whereIn('name', $first)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $cashier = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo(['view payments', 'view orders']);

        $migration = require database_path('migrations/2026_11_26_100300_grant_analytics_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Role::findByName(Role::SUPER_ADMIN)->hasAllPermissions($first));
        $this->assertCount(2, $cashier->fresh()->permissions);
        $this->assertSame([], array_intersect(Analytics::permissions(), Role::findByName(Role::CUSTOMER)->permissions->pluck('name')->all()));
    }

    /* -------- Sales -------- */

    public function test_the_summary_counts_completed_sales_and_compares_like_with_like(): void
    {
        $this->order('2026-11-26 09:00:00', 'completed', 300);
        $this->order('2026-11-26 10:00:00', 'pending', 100);
        $this->order('2026-11-26 11:00:00', 'cancelled', 999);
        $this->order('2026-11-19 11:00:00', 'completed', 100);
        // After the time it is now, a week ago: not compared.
        $this->order('2026-11-19 15:00:00', 'completed', 5000);

        $data = SalesWidgets::summary(new User, $this->range('today'));
        $tiles = collect($data['tiles'])->keyBy('label');

        $this->assertSame([300.0, 100.0, 200], [$tiles['Sales']['value'], $tiles['Sales']['before'], $tiles['Sales']['change']]);
        $this->assertSame([2, 1], [$tiles['Orders']['value'], $tiles['Orders']['before']]);
        $this->assertSame([200.0, 100.0], [$tiles['Average order']['value'], $tiles['Average order']['before']]);
        $this->assertSame([300.0, 100.0], [$tiles['Collected']['value'], $tiles['Collected']['before']]);
        $this->assertSame(100.0, $data['pending']);
        $this->assertSame('Nov 19, 2026', $data['comparedWith']);
    }

    public function test_the_trend_has_every_day_and_switches_to_weeks_and_months(): void
    {
        $this->order('2026-11-24 10:00:00', 'completed', 50);
        $this->order('2026-11-24 23:59:59', 'completed', 25);
        $this->order('2026-11-26 10:00:00', 'pending', 80);
        $this->order('2026-11-25 10:00:00', 'cancelled', 999);

        $days = SalesWidgets::trend(new User, $this->range('7d'));

        $this->assertSame('day', $days['unit']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 75.0, 0.0, 0.0], array_column($days['rows'], 'sales'));
        $this->assertSame(80.0, $days['rows'][6]['pending']);
        $this->assertSame([2, 0, 1], array_column(array_slice($days['rows'], 4), 'orders'));
        $this->assertSame(array_column($days['rows'], 'sales'), $days['chart']['datasets'][0]['data']);

        $weeks = SalesWidgets::trend(new User, $this->between('2026-08-12', '2026-11-26'));

        $this->assertSame('week', $weeks['unit']);
        // The first bar starts where the range does (a Wednesday), not on the Monday before.
        $this->assertSame('Aug 12', $weeks['rows'][0]['label']);
        $this->assertSame(75.0, end($weeks['rows'])['sales']);

        $months = SalesWidgets::trend(new User, $this->between('2026-01-01', '2026-11-26'));

        $this->assertSame('month', $months['unit']);
        $this->assertCount(11, $months['rows']);
        $this->assertSame(75.0, $months['rows'][10]['sales']);
    }

    public function test_status_and_payment_methods(): void
    {
        $this->order('2026-11-25 09:00:00', 'completed', 300, 'GCash');
        $this->order('2026-11-25 10:00:00', 'completed', 100, PaymentMethod::CASH_ON_PICKUP);
        $this->order('2026-11-25 11:00:00', 'pending', 50, PaymentMethod::CASH_ON_PICKUP);
        $this->order('2026-11-25 12:00:00', 'cancelled', 20, 'GCash');

        $status = SalesWidgets::status(new User, $this->range());

        $this->assertSame(4, $status['placed']);
        $this->assertSame([2, 1, 1], array_column($status['statuses'], 'orders'));
        $this->assertSame([50.0, 25.0, 25.0], array_column($status['statuses'], 'share'));

        $methods = SalesWidgets::methods(new User, $this->range())['methods'];

        $this->assertSame(['GCash', PaymentMethod::CASH_ON_PICKUP], array_column($methods, 'name'));
        $this->assertSame([300.0, 100.0], array_column($methods, 'sales'));
        $this->assertSame([1, 2], array_column($methods, 'orders'));
        $this->assertSame([100, 33], array_column($methods, 'width'));
    }

    public function test_basket_sizes_add_up_the_lines_of_each_order(): void
    {
        $this->order('2026-11-25 09:00:00', 'completed', 100, lines: [['Adobo', 1]]);
        $this->order('2026-11-25 10:00:00', 'completed', 200, lines: [['Adobo', 2], ['Sisig', 2]]);
        $this->order('2026-11-25 11:00:00', 'pending', 600, lines: [['Sisig', 7]]);
        $this->order('2026-11-25 12:00:00', 'cancelled', 999, lines: [['Sisig', 9]]);

        $data = SalesWidgets::basket(new User, $this->range());

        $this->assertSame(3, $data['orders']);
        $this->assertSame(4.0, $data['averagePlates']);
        $this->assertSame(300.0, $data['averageAmount']);
        $this->assertSame([1, 0, 0, 1, 1], array_column($data['sizes'], 'orders'));
    }

    public function test_cancellations_say_who_and_why(): void
    {
        $customer = User::factory()->create();
        $staff = User::factory()->create();

        $this->order('2026-11-25 09:00:00', 'cancelled', 100, lines: [['Adobo', 2]], order: ['user_id' => $customer->id, 'cancelled_by' => $customer->id]);
        $this->order('2026-11-25 10:00:00', 'cancelled', 200, lines: [['Adobo', 1], ['Sisig', 1]], order: ['cancelled_by' => $staff->id, 'cancel_reason' => 'No show']);
        $this->order('2026-11-25 11:00:00', 'cancelled', 300, order: ['cancelled_by' => $staff->id, 'cancel_reason' => 'No show']);
        $this->order('2026-11-25 12:00:00', 'cancelled', 400);
        $this->order('2026-11-25 13:00:00', 'completed', 999);

        $data = SalesWidgets::cancelled(new User, $this->range());

        $this->assertSame([4, 1000.0, 4], [$data['orders'], $data['amount'], $data['plates']]);
        $this->assertSame(['By the customer' => 1, 'By staff' => 2, 'Not recorded' => 1], array_column($data['who'], 'orders', 'label'));
        $this->assertSame([['reason' => 'No show', 'orders' => 2]], $data['reasons']);
    }

    /* -------- Money -------- */

    public function test_collections_follow_the_day_verified_and_set_cash_apart(): void
    {
        $maria = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Cruz']);
        $cash = PaymentMethod::CASH_ON_PICKUP;
        $this->method($cash);

        $this->order('2026-11-24 09:00:00', total: 100, method: $cash, payment: ['verified_by' => $maria->id, 'verified_at' => '2026-11-25 08:00:00']);
        $this->order('2026-11-25 09:00:00', total: 250, method: 'GCash', payment: ['verified_by' => $maria->id]);
        $this->order('2026-11-25 10:00:00', total: 40, method: $cash);
        // Placed long before the range, verified inside it: collected in it.
        $this->order('2026-08-01 10:00:00', total: 5, method: 'GCash', payment: ['verified_at' => '2026-11-26 10:00:00']);
        $this->order('2026-11-25 11:00:00', 'pending', 999);

        $data = MoneyWidgets::collections(new User, $this->range('7d'));

        $this->assertSame([395.0, 140.0, 4], [$data['total'], $data['cash'], $data['payments']]);
        // The colour follows the method's place among all methods, oldest first.
        $this->assertSame([$cash, 'GCash'], $data['methods']);
        $this->assertSame([1, 2], array_column($data['chart']['datasets'], 'color'));
        // The table lists the newest day first, the chart the oldest: 100 + 250 + 40 on the 25th.
        $this->assertSame([5.0, 390.0], array_column($data['days'], 'total'));
        $this->assertSame(['Nov 25', 'Nov 26'], $data['chart']['labels']);
        $this->assertSame([[140.0, 0.0], [250.0, 5.0]], array_column($data['chart']['datasets'], 'data'));
        $this->assertSame(['Maria Cruz' => 350.0, 'Not recorded' => 45.0], array_column($data['people'], 'total', 'name'));
        $this->assertSame(['Maria Cruz' => 100.0, 'Not recorded' => 40.0], array_column($data['people'], 'cash', 'name'));
    }

    public function test_methods_past_the_palette_are_folded_into_other(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $index => $name) {
            $this->method($name);
            $this->order('2026-11-25 0' . $index . ':00:00', total: 10, method: $name);
        }

        $data = MoneyWidgets::collections(new User, $this->range());

        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'Other'], $data['methods']);
        $this->assertSame(['Other' => 'other'], array_column(array_slice($data['chart']['datasets'], 5), 'color', 'label'));
        $this->assertSame([20.0], $data['chart']['datasets'][5]['data']);
    }

    public function test_repeated_references_ignore_case_and_spaces_and_reach_back_in_time(): void
    {
        $same = User::factory()->create();
        $ref = fn (?string $number) => ['reference_number' => $number];

        // Used long ago and again inside the range, by two people.
        $this->order('2026-01-05 10:00:00', payment: $ref('ab 12345'));
        $this->order('2026-11-25 10:00:00', 'pending', payment: $ref('AB12345'));
        // One customer twice.
        $this->order('2026-11-24 10:00:00', order: ['user_id' => $same->id], payment: $ref('999999'));
        $this->order('2026-11-24 11:00:00', 'cancelled', order: ['user_id' => $same->id], payment: $ref('999999'));
        // Repeated, but both before the range; single; too short; empty.
        $this->order('2026-01-01 10:00:00', payment: $ref('OLD-1111'));
        $this->order('2026-01-02 10:00:00', payment: $ref('OLD-1111'));
        $this->order('2026-11-25 11:00:00', payment: $ref('ONCE-2222'));
        $this->order('2026-11-25 12:00:00', payment: $ref('NA'));
        $this->order('2026-11-25 12:30:00', payment: $ref('n a'));
        $this->order('2026-11-25 13:00:00', payment: $ref(null));
        $this->order('2026-11-25 14:00:00', payment: $ref(null));

        $data = MoneyWidgets::references($this->userWith('view payments'), $this->range());

        // Two different customers first.
        $this->assertSame(['AB12345', '999999'], array_column($data['groups'], 'reference'));
        $this->assertSame([2, 1], array_column($data['groups'], 'customers'));
        $this->assertCount(2, $data['groups'][0]['orders']);
        $this->assertFalse($data['more']);
        $this->assertSame(route('payments.index'), $data['ordersUrl']);
    }

    public function test_money_held_is_verified_money_on_open_orders_whatever_the_range(): void
    {
        $this->order('2026-01-05 10:00:00', 'pending', 120, payment: ['payment_status' => 'verified']);
        $this->order('2026-11-25 10:00:00', 'pending', 80);
        $this->order('2026-11-25 11:00:00', 'completed', 999);

        $data = MoneyWidgets::held($this->userWith('view orders'), $this->range('today'));

        $this->assertSame([1, 120.0], [$data['orders'], $data['amount']]);
        $this->assertSame(route('orders.index'), $data['rows'][0]['url']);
    }

    /* -------- Kitchen -------- */

    public function test_dish_sales_rank_best_sellers_and_the_slow_ones_per_session_offered(): void
    {
        foreach (['B', 'C', 'D', 'E', 'F'] as $index => $name) {
            $this->dish($name, '2026-11-25', 10, $index + 2);
        }

        // "Rare" was offered once and sold 1; "A" was offered twice and sold 2 + 0.
        $this->dish('Rare', '2026-11-25', 10, 1);
        $this->dish('A', '2026-11-25', 10, 2);
        $this->dish('A', '2026-11-24', 10, 0);
        $type = DishType::factory()->create(['type_name' => 'Viand']);
        DB::table('dish_dish_types')->insert(['dish_id' => Dish::query()->where('dish_name', 'F')->value('dish_id'), 'dish_type_id' => $type->dish_type_id]);

        $this->order('2026-11-25 10:00:00', lines: [['F', 6, false, 300], ['B', 2], ['A', 2]]);
        $this->order('2026-11-25 10:30:00', 'cancelled', lines: [['Rare', 50]]);

        $data = KitchenWidgets::dishSales(new User, $this->range());

        $this->assertSame(10, $data['plates']);
        $this->assertSame(['F', 'A', 'B'], array_column($data['top'], 'dish_name'));
        $this->assertSame(300.0, $data['top'][0]['amount']);
        // Per session offered: Rare 1, A 1 (over two sessions: listed first), B 2 ...
        $this->assertSame(['A', 'Rare', 'B', 'C', 'D'], array_column($data['slow'], 'dish_name'));
        $this->assertSame([['type' => 'Viand', 'plates' => 6, 'width' => 100]], $data['types']);
    }

    public function test_leftovers_count_only_ended_sessions_and_plates_really_offered(): void
    {
        $this->dish('Adobo', '2026-11-24', 4, 6);
        $this->dish('Adobo', '2026-11-25', 0, 10);
        $this->dish('Sisig', '2026-11-25', 9, 1);
        // Taken off the menu with 2 ordered: those count, its untouched stock does not.
        $pulled = $this->dish('Pulled', '2026-11-25', 8, 2);
        DB::table('session_dishes')->where('session_dish_id', $pulled->session_dish_id)->update(['is_available' => false]);
        // Still running (09:00-17:00, it is noon) and not started yet.
        $this->dish('Adobo', '2026-11-26', 50, 0);
        $this->dish('Adobo', '2026-11-27', 50, 0);

        $data = KitchenWidgets::sellThrough(new User, $this->between('2026-11-20', '2026-11-26'));

        $this->assertSame(2, $data['sessions']);
        $this->assertSame([32, 19, 13, 59], [$data['prepared'], $data['ordered'], $data['left'], $data['rate']]);
        $this->assertSame(['Sisig', 'Adobo', 'Pulled'], array_column($data['dishes'], 'dish_name'));
        $this->assertSame([9, 4, 0], array_column($data['dishes'], 'left'));
        $this->assertSame([10, 80, 100], array_column($data['dishes'], 'rate'));
    }

    public function test_sellout_speed_takes_the_middle_time_of_the_last_order(): void
    {
        foreach (['2026-11-23' => '09:30:00', '2026-11-24' => '10:15:00', '2026-11-25' => '13:00:00'] as $day => $time) {
            $this->dish('Adobo', $day, 0, 5);
            $this->order($day . ' 09:05:00', lines: [['Adobo', 1]]);
            $this->order($day . ' ' . $time, lines: [['Adobo', 4]]);
            // A later cancelled order does not move the time.
            $this->order($day . ' 16:00:00', 'cancelled', lines: [['Adobo', 1]]);
        }

        $this->dish('Sisig', '2026-11-25', 3, 5);
        $this->dish('Sisig', '2026-11-24', 0, 2);
        $this->order('2026-11-24 11:30:00', lines: [['Sisig', 2]]);
        // Zero stock from the start is not "sold out".
        $this->dish('Empty', '2026-11-25', 0, 0);

        $data = KitchenWidgets::selloutSpeed(new User, $this->range());

        $this->assertSame(['Adobo', 'Sisig'], array_column($data['dishes'], 'dish_name'));
        $this->assertSame([100, 50], array_column($data['dishes'], 'rate'));
        $this->assertSame([75, 150], array_column($data['dishes'], 'minutes'));
        $this->assertSame(['1 h 15 min', '2 h 30 min'], array_column($data['dishes'], 'after'));
    }

    public function test_the_scorecard_has_a_line_per_started_session(): void
    {
        $this->dish('Adobo', '2026-11-25', 0, 5);
        $this->dish('Sisig', '2026-11-25', 5, 0);
        $this->dish('Adobo', '2026-11-26', 7, 3);
        $this->dish('Adobo', '2026-11-27', 10, 0);
        $this->order('2026-11-25 10:00:00', 'completed', 200);
        $this->order('2026-11-25 11:00:00', 'pending', 50);
        $this->order('2026-11-25 12:00:00', 'cancelled', 999);

        $data = KitchenWidgets::scorecard($this->userWith('view order-sessions'), $this->between('2026-11-20', '2026-11-26'));

        $this->assertSame(['Thu, Nov 26', 'Wed, Nov 25'], array_column($data['sessions'], 'date'));
        [$running, $ended] = $data['sessions'];
        $this->assertSame([false, null, 30], [$running['ended'], $running['left'], $running['rate']]);
        $this->assertSame([2, 200.0, 10, 5, 50, 1, 5], [$ended['orders'], $ended['sales'], $ended['prepared'], $ended['ordered'], $ended['rate'], $ended['soldOut'], $ended['left']]);
        $this->assertNotNull($ended['url']);
    }

    public function test_rice_counts_dishes_that_come_with_it(): void
    {
        $this->dish('Adobo', '2026-11-25', 10, 0, ['includes_rice' => true]);
        $this->dish('Cake', '2026-11-25', 10, 0, ['includes_rice' => false]);
        $this->dish('Adobo', '2026-11-24', 10, 0);

        $this->order('2026-11-25 10:00:00', lines: [['Adobo', 3], ['Adobo', 1, true], ['Cake', 9]]);
        $this->order('2026-11-24 10:00:00', 'pending', lines: [['Adobo', 4]]);
        $this->order('2026-11-24 11:00:00', 'cancelled', lines: [['Adobo', 20, true]]);

        $data = KitchenWidgets::rice(new User, $this->range());

        $this->assertSame([7, 1, 13, 4, 2], [$data['with'], $data['without'], $data['rate'], $data['perSession'], $data['sessions']]);
        $this->assertSame([['dish_name' => 'Adobo', 'with' => 7, 'without' => 1, 'rate' => 13]], $data['dishes']);
    }

    public function test_a_dish_unticked_since_keeps_its_plates_with_rice(): void
    {
        $this->dish('Adobo', '2026-11-25', 10, 0, ['includes_rice' => false]);
        $this->order('2026-11-25 10:00:00', lines: [['Adobo', 3], ['Adobo', 1, true]]);

        $data = KitchenWidgets::rice(new User, $this->range());

        // Not "100% without": the three plates with rice are still counted.
        $this->assertSame([3, 1, 25], [$data['with'], $data['without'], $data['rate']]);
    }

    public function test_a_preset_range_follows_new_orders(): void
    {
        $this->order('2026-11-25 09:00:00', 'completed', 100);
        $this->assertSame(1, SalesWidgets::status(new User, $this->range())['placed']);

        $this->order('2026-11-25 10:00:00', 'completed', 100);

        $this->assertSame(2, SalesWidgets::status(new User, $this->range())['placed']);
    }
}
