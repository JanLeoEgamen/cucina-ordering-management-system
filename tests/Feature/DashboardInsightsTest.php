<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dashboard;
use App\Support\Dashboard\CheckWidgets;
use App\Support\Dashboard\InsightWidgets;
use App\Support\Dashboard\MyWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SellsDishes;
use Tests\TestCase;

// The later dashboard widgets: smart insights, sign-in security and the customer's sell-out
// alert, new dishes and discounts.
class DashboardInsightsTest extends TestCase
{
    use RefreshDatabase;
    use SellsDishes;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday at noon.
        $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));
    }

    private function sentences(User $user): array
    {
        return array_column(InsightWidgets::insights($user)['insights'] ?? [], 'text');
    }

    /* -------- Smart insights -------- */

    public function test_a_dish_that_keeps_selling_out_or_staying_behind_is_pointed_out(): void
    {
        foreach (['2026-11-20' => 0, '2026-11-23' => 0, '2026-11-24' => 4, '2026-11-25' => 0] as $day => $left) {
            $this->dish('Chicken Adobo', $day, $left, 10);
        }
        // 15 of 20 left in each of its last three; an older good day does not matter.
        $this->dish('Pinakbet', '2026-11-20', 0, 20);
        foreach (['2026-11-23', '2026-11-24', '2026-11-25'] as $day) {
            $this->dish('Pinakbet', $day, 15, 5);
        }
        // Two sessions are not a trend.
        $this->dish('Sinigang', '2026-11-24', 0, 5);
        $this->dish('Sinigang', '2026-11-25', 0, 5);

        $both = $this->sentences($this->userWith('view analytics-sellout-speed', 'view analytics-sell-through'));

        $this->assertSame([
            'Chicken Adobo sold out in 3 of its last 4 sessions. Consider preparing more.',
            'Pinakbet had more than 40% left over in each of its last 3 sessions (45 of 60 plates). Consider preparing less.',
        ], $both);

        // Each rule needs its own data.
        $this->assertCount(1, $this->sentences($this->userWith('view analytics-prep-suggestions')));
        $this->assertStringContainsString('Pinakbet', $this->sentences($this->userWith('view analytics-sell-through'))[0]);
        $this->assertSame([], $this->sentences($this->userWith('view payments')));
    }

    public function test_it_warns_when_no_session_is_planned_for_tomorrow(): void
    {
        $user = $this->userWith('view order-sessions');

        $this->assertSame(['No order session is scheduled for tomorrow.'], $this->sentences($user));

        OrderSession::factory()->create(['order_date' => '2026-11-27', 'start_time' => '09:00:00', 'end_time' => '17:00:00']);

        $this->assertNull(InsightWidgets::insights($user));
    }

    public function test_it_counts_what_has_waited_more_than_half_an_hour(): void
    {
        $this->order('2026-11-26 11:00:00', 'pending');
        // Twenty minutes: not yet. Cash is verified by completing.
        $this->order('2026-11-26 11:40:00', 'pending');
        $this->order('2026-11-26 09:00:00', 'pending', method: 'Cash on Pick up', order: ['discount_status' => 'pending']);

        $this->assertSame(['1 payment has waited more than 30 minutes to be verified.'], $this->sentences($this->userWith('view payments')));

        $reviewer = InsightWidgets::insights($this->userWith('review discounts'))['insights'];

        $this->assertSame(['1 discount ID has waited more than 30 minutes for a review.'], array_column($reviewer, 'text'));
        $this->assertSame([null], array_column($reviewer, 'url'));
    }

    public function test_a_clear_drop_in_a_weeks_sales_is_said_only_with_enough_orders(): void
    {
        $user = $this->userWith('view analytics-sales-summary');

        foreach (range(1, 9) as $ignored) {
            $this->order('2026-11-13 10:00:00', 'completed', 100);
        }
        $this->order('2026-11-20 10:00:00', 'completed', 450);
        // Today is not part of either week.
        $this->order('2026-11-26 10:00:00', 'completed', 9000);

        // Nine orders the week before are too few to compare with.
        $this->assertNull(InsightWidgets::insights($user));

        $this->order('2026-11-14 10:00:00', 'completed', 100);

        $this->assertSame(['Sales of the last 7 days are 55% lower than the 7 days before (₱450.00 against ₱1,000.00).'], $this->sentences($user));
    }

    public function test_the_insights_card_needs_its_permission_and_one_source(): void
    {
        $this->assertArrayHasKey('insights', Dashboard::widgetsFor($this->userWith('view dashboard-insights', 'view order-sessions')));
        $this->assertArrayNotHasKey('insights', Dashboard::widgetsFor($this->userWith('view order-sessions')));
        $this->assertArrayNotHasKey('insights', Dashboard::widgetsFor($this->userWith('view dashboard-insights')));

        $this->actingAs($this->userWith('view dashboard-insights', 'view order-sessions'))->get(route('dashboard'))
            ->assertOk()->assertSee('Worth a look')->assertSee('No order session is scheduled for tomorrow.');
    }

    /* -------- Security -------- */

    public function test_security_counts_a_days_sign_in_trouble_without_naming_anyone(): void
    {
        $row = fn (string $action, string $at, string $ip) => ['event' => 'auth', 'action' => $action, 'description' => 'x', 'user_email' => 'target@example.com', 'outcome' => 'failed', 'ip' => $ip, 'created_at' => $at];

        DB::table('audit_logs')->insert([
            $row('auth.failed', '2026-11-26 11:00:00', '10.0.0.1'),
            $row('auth.failed', '2026-11-26 11:01:00', '10.0.0.1'),
            $row('auth.failed', '2026-11-25 13:00:00', '10.0.0.2'),
            $row('auth.lockout', '2026-11-26 11:02:00', '10.0.0.1'),
            $row('auth.failed', '2026-11-25 11:00:00', '10.0.0.3'),
            $row('auth.login', '2026-11-26 11:03:00', '10.0.0.4'),
        ]);

        $data = CheckWidgets::security(new User);

        $this->assertSame([true, 3, 1, 2], [$data['enabled'], $data['failed'], $data['lockouts'], $data['addresses']]);

        $this->actingAs($this->userWith('view dashboard-security', 'view audit-logs'))->get(route('dashboard'))
            ->assertOk()->assertSee('failed sign-ins in the last 24 hours')->assertDontSee('target@example.com');

        config(['audit.enabled' => false]);

        $this->assertSame(['enabled' => false], CheckWidgets::security(new User));
    }

    /* -------- For the customer -------- */

    public function test_the_sellout_alert_is_about_the_persons_own_favourites(): void
    {
        $me = $this->userWith('view menu', 'view my orders');

        $this->assertNull(MyWidgets::selloutAlert($me));

        foreach (['2026-11-23' => 0, '2026-11-24' => 0, '2026-11-25' => 6] as $day => $left) {
            $this->dish('Favourite', $day, $left, 10);
            $this->dish('Someone elses', $day, 0, 10);
        }
        $this->order('2026-11-23 10:30:00', lines: [['Favourite', 2]], order: ['user_id' => $me->id]);
        $this->order('2026-11-24 11:00:00', lines: [['Favourite', 1]], order: ['user_id' => $me->id]);
        $this->order('2026-11-23 10:00:00', lines: [['Someone elses', 1]]);

        $data = MyWidgets::selloutAlert($me);

        $this->assertSame(['Favourite'], array_column($data['dishes'], 'dish_name'));
        // Sold out at 10:30 and 11:00 after a 09:00 start: the middle is 1 h 45 min.
        $this->assertSame([2, 3, '1 h 45 min', null], [$data['dishes'][0]['soldOut'], $data['dishes'][0]['offered'], $data['dishes'][0]['after'], $data['dishes'][0]['left']]);
    }

    public function test_new_to_you_lists_untried_dishes_on_todays_menu_with_stock(): void
    {
        $me = $this->userWith('view menu', 'view my orders');
        $this->dish('Tried', '2026-11-26', 5, 0);
        $this->dish('Untried', '2026-11-26', 7, 0);
        $this->dish('Gone already', '2026-11-26', 0, 4);

        // No history: everything would be new, so nothing is said.
        $this->assertNull(MyWidgets::newDishes($me));

        $this->order('2026-11-25 10:00:00', lines: [['Tried', 1]], order: ['user_id' => $me->id]);

        // The live session is today's 09:00-17:00 one.
        $this->assertSame(['dishes' => [['dish_name' => 'Untried', 'left' => 7]], 'more' => 0], MyWidgets::newDishes($me));

        $this->assertNull(MyWidgets::newDishes($this->userWith('view my orders')));
    }

    public function test_discounts_follow_the_switch_the_window_and_what_the_person_may_order(): void
    {
        $me = $this->userWith('view menu');
        Discount::factory()->create(['discount_name' => 'Open', 'requires_proof' => false]);
        Discount::factory()->create(['discount_name' => 'Ends soon', 'valid_until' => '2026-11-30']);
        Discount::factory()->create(['discount_name' => 'Not yet', 'valid_from' => '2026-12-01']);
        Discount::factory()->create(['discount_name' => 'Over', 'valid_until' => '2026-11-25']);
        Discount::factory()->inactive()->create(['discount_name' => 'Off']);

        $this->assertNull(MyWidgets::discounts($me), 'discounts are switched off by default');

        Setting::set('discounts.mode', 'orders');

        $data = MyWidgets::discounts($me);

        $this->assertSame(['Ends soon', 'Open'], array_column($data['discounts'], 'name'));
        $this->assertSame([true, false], array_column($data['discounts'], 'endsSoon'));
        $this->assertSame([true, false], array_column($data['discounts'], 'needsId'));
        $this->assertSame('orders', $data['kinds']);

        // Offered on pre-orders only: nothing for someone who cannot place one.
        Setting::set('discounts.mode', 'pre_orders');

        $this->assertNull(MyWidgets::discounts($me));
        $this->assertSame('pre-orders', MyWidgets::discounts($this->userWith('view pre-order-menu'))['kinds']);
    }
}
