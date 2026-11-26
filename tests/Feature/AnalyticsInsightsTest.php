<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Dish;
use App\Models\Role;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Analytics\CustomerWidgets;
use App\Support\Analytics\Export;
use App\Support\Analytics\KitchenWidgets;
use App\Support\Analytics\MoneyWidgets;
use App\Support\Analytics\PlanningWidgets;
use App\Support\Analytics\SalesWidgets;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SellsDishes;
use Tests\TestCase;

// The later analytics widgets (order times, discounts, review times, planning, customers) and the
// CSV export. The numbers asserted are worked out by hand from the rows each test makes.
class AnalyticsInsightsTest extends TestCase
{
    use RefreshDatabase;
    use SellsDishes;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday at noon.
        $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));
    }

    // One dish offered in one ended 09:00-17:00 session per day, with [left, ordered] stock.
    private function history(string $name, array $days, array $attributes = []): void
    {
        foreach ($days as $day => [$left, $ordered]) {
            $this->dish($name, $day, $left, $ordered, $attributes);
        }
    }

    private function person(string $first = 'Ana', array $attributes = []): User
    {
        return User::factory()->create($attributes + ['first_name' => $first, 'last_name' => 'Cruz']);
    }

    private function by(User $user, string $at, string $status = 'completed', float $total = 100): void
    {
        $this->order($at, $status, $total, order: ['user_id' => $user->id]);
    }

    /* -------- Sales and money -------- */

    public function test_order_times_by_hour_and_weekday(): void
    {
        $this->assertTrue(SalesWidgets::times(new User, $this->range())['empty']);

        $this->order('2026-11-25 09:15:00');
        $this->order('2026-11-25 09:59:59', 'pending');
        $this->order('2026-11-24 11:00:00');
        $this->order('2026-11-24 09:30:00', 'cancelled');

        $data = SalesWidgets::times(new User, $this->range('7d'));

        $this->assertSame(['09:00', '10:00', '11:00'], array_column($data['hours'], 'label'));
        $this->assertSame([2, 0, 1], array_column($data['hours'], 'orders'));
        $this->assertSame(['Tue', 'Wed'], $data['days']);
        $this->assertSame([0, 2], $data['hours'][0]['byDay']);
        $this->assertSame(['label' => '09:00', 'orders' => 2, 'share' => 67], $data['busiest']);
        $this->assertSame('count', $data['chart']['format']);
    }

    public function test_discounts_are_totals_and_given_means_approved_on_completed_orders(): void
    {
        $with = fn (string $name, string $status, float $amount) => ['discount_name' => $name, 'discount_status' => $status, 'discount_amount' => $amount];

        $this->order('2026-11-25 09:00:00', order: $with('Senior', 'approved', 20));
        $this->order('2026-11-25 09:10:00', 'pending', order: $with('Senior', 'approved', 30));
        $this->order('2026-11-25 09:20:00', order: $with('Senior', 'rejected', 0));
        $this->order('2026-11-25 09:30:00', 'pending', order: $with('Senior', 'pending', 15));
        $this->order('2026-11-25 09:40:00', 'cancelled', order: $with('Senior', 'approved', 500));
        $this->order('2026-11-25 09:50:00', order: $with('Staff', 'approved', 45));
        $this->order('2026-11-25 10:00:00');

        $data = MoneyWidgets::discounts(new User, $this->range());

        $this->assertSame([65.0, 5, 1], [$data['given'], $data['orders'], $data['rejected']]);
        $this->assertSame(
            ['name' => 'Staff', 'orders' => 1, 'approved' => 1, 'rejected' => 0, 'pending' => 0, 'given' => 45.0],
            $data['discounts'][0]
        );
        $this->assertSame(
            ['name' => 'Senior', 'orders' => 4, 'approved' => 2, 'rejected' => 1, 'pending' => 1, 'given' => 20.0],
            $data['discounts'][1]
        );
    }

    public function test_review_times_leave_out_cash_and_cancelled_orders(): void
    {
        $paid = fn (string $decided) => ['created_at' => '2026-11-25 10:00:00', 'verified_at' => '2026-11-25 ' . $decided, 'payment_status' => 'verified'];

        $this->order('2026-11-25 10:00:00', payment: $paid('10:03:00'));
        $this->order('2026-11-25 10:00:00', payment: $paid('10:10:00'));
        $this->order('2026-11-25 10:00:00', 'pending', payment: ['payment_status' => 'rejected'] + $paid('11:30:00'));
        $this->order('2026-11-25 10:00:00', method: 'Cash on Pick up', payment: $paid('15:00:00'));
        $this->order('2026-11-25 10:00:00', 'cancelled', payment: $paid('16:00:00'));
        // Only the discount of this one: its payment is still waiting.
        $this->order('2026-11-25 08:00:00', 'pending', order: ['discount_status' => 'approved', 'discount_decided_at' => '2026-11-25 08:40:00']);

        [$payments, $discounts] = MoneyWidgets::reviewTimes(new User, $this->range())['groups'];

        $this->assertSame([3, 34, 90], [$payments['decisions'], $payments['average'], $payments['longest']]);
        $this->assertSame([1, 1, 0, 1], array_column($payments['bands'], 'decisions'));
        $this->assertSame([1, 40, 40], [$discounts['decisions'], $discounts['average'], $discounts['longest']]);
        $this->assertSame([0, 0, 1, 0], array_column($discounts['bands'], 'decisions'));
    }

    public function test_a_wait_of_exactly_a_bands_limit_stays_in_that_band(): void
    {
        // Several clock times: a sum in fractions of a day lands a hair over the limit on some.
        foreach (['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '16:00'] as $start) {
            foreach ([5, 15, 60] as $minutes) {
                $from = Carbon::parse('2026-11-25 ' . $start . ':00');
                $this->order($from->toDateTimeString(), payment: ['payment_status' => 'verified', 'created_at' => $from->toDateTimeString(), 'verified_at' => $from->copy()->addMinutes($minutes)->toDateTimeString()]);
            }
        }

        $payments = MoneyWidgets::reviewTimes(new User, $this->range())['groups'][0];

        $this->assertSame([7, 7, 7, 0], array_column($payments['bands'], 'decisions'));
        $this->assertSame(60, $payments['longest']);
    }

    /* -------- Planning -------- */

    public function test_prep_suggestions_add_a_buffer_and_more_for_what_sells_out(): void
    {
        $steady = ['2026-11-23' => [10, 10], '2026-11-24' => [10, 10], '2026-11-25' => [10, 10]];
        $this->history('Adobo', $steady);
        $this->history('Sisig', ['2026-11-23' => [0, 20], '2026-11-24' => [0, 20], '2026-11-25' => [5, 20]]);
        $this->history('Twice only', array_slice($steady, 0, 2));
        $this->history('Deleted since', $steady);
        DB::table('dishes')->where('dish_name', 'Deleted since')->update(['deleted_at' => now()]);
        // Seven sessions: the oldest (100 ordered) is outside the newest six.
        $this->history('Capped', ['2026-11-16' => [0, 100]] + array_fill_keys(['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-23', '2026-11-24'], [5, 5]));
        // Today's session is still running and does not count.
        $this->dish('Adobo', '2026-11-26', 0, 90);

        $data = PlanningWidgets::prep(new User, $this->range('today'));
        $dishes = array_column($data['dishes'], null, 'dish_name');

        // 10 x 1.1 is 11, not 12: the floating point tail is rounded off first.
        $this->assertSame([11, 10.0, 20.0, false, -9], [$dishes['Adobo']['suggested'], $dishes['Adobo']['ordered'], $dishes['Adobo']['prepared'], $dishes['Adobo']['demandHigher'], $dishes['Adobo']['change']]);
        $this->assertSame([24, true, 2], [$dishes['Sisig']['suggested'], $dishes['Sisig']['demandHigher'], $dishes['Sisig']['soldOut']]);
        $this->assertSame([6, 6, 5.0], [$dishes['Capped']['sessions'], $dishes['Capped']['suggested'], $dishes['Capped']['ordered']]);
        $this->assertArrayNotHasKey('Deleted since', $dishes);
        $this->assertArrayNotHasKey('Twice only', $dishes);
        $this->assertSame(1, $data['tooFew']);
    }

    public function test_a_dish_that_often_runs_out_is_never_suggested_below_what_ran_out(): void
    {
        // Ran out at 12 plates four times; two quiet days with a big stock pull the average to 9.
        $this->history('Stew', array_fill_keys(['2026-11-20', '2026-11-23', '2026-11-24', '2026-11-25'], [0, 12])
            + array_fill_keys(['2026-11-18', '2026-11-19'], [37, 3]));

        $dish = PlanningWidgets::prep(new User, $this->range())['dishes'][0];

        // Average ordered 9 + 20% would be 11, less than what was not enough: 12 + 20% = 15.
        $this->assertSame([9.0, true, 15], [$dish['ordered'], $dish['demandHigher'], $dish['suggested']]);
    }

    public function test_weekday_demand_averages_each_day_a_dish_was_offered(): void
    {
        $this->history('Adobo', ['2026-11-17' => [10, 6], '2026-11-24' => [10, 4], '2026-11-25' => [10, 9]]);
        $this->history('Sisig', ['2026-11-25' => [10, 2]]);

        $data = PlanningWidgets::weekdays(new User, $this->range());
        $dishes = array_column($data['dishes'], null, 'dish_name');

        $this->assertSame(['Tue', 'Wed'], $data['days']);
        $this->assertSame([5.0, 9.0], $dishes['Adobo']['averages']);
        $this->assertSame(1, $dishes['Adobo']['best']);
        $this->assertSame([null, 2.0], $dishes['Sisig']['averages']);
        $this->assertNull($dishes['Sisig']['best']);
    }

    public function test_bring_back_lists_good_sellers_that_are_away_and_not_planned(): void
    {
        $old = ['2026-11-02' => [5, 10], '2026-11-03' => [5, 10]];
        $this->history('Star', $old);
        $this->history('Meh', ['2026-11-02' => [5, 1], '2026-11-03' => [5, 1]]);
        $this->history('Recent', ['2026-11-02' => [5, 10], '2026-11-24' => [5, 10]]);
        $this->history('Planned', $old);
        $this->dish('Planned', '2026-11-30', 20, 0);

        $data = PlanningWidgets::bringBack(new User, $this->range());

        $this->assertSame(['Star'], array_column($data['dishes'], 'dish_name'));
        $this->assertSame([10.0, 2, 'Nov 3', 23], [$data['dishes'][0]['average'], $data['dishes'][0]['sessions'], $data['dishes'][0]['last'], $data['dishes'][0]['daysAgo']]);
    }

    public function test_menu_fatigue_compares_the_newer_half_with_the_older(): void
    {
        $days = ['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-23'];
        $this->history('Tired', array_combine($days, [[5, 10], [5, 10], [5, 10], [5, 5], [5, 5], [5, 5]]));
        $this->history('Steady', array_fill_keys($days, [5, 8]));
        $this->history('Short', array_combine(array_slice($days, 0, 5), [[5, 10], [5, 10], [5, 1], [5, 1], [5, 1]]));

        $data = PlanningWidgets::fatigue(new User, $this->range());

        $this->assertSame([['dish_name' => 'Tired', 'sessions' => 6, 'before' => 10.0, 'now' => 5.0, 'drop' => 50]], $data['dishes']);
    }

    public function test_new_dishes_are_judged_beside_the_average_dish(): void
    {
        $days = ['2026-11-23', '2026-11-24', '2026-11-25'];
        $this->history('Fresh', array_fill_keys($days, [8, 12]), ['created_at' => '2026-11-10 10:00:00']);
        $this->history('Old favourite', array_fill_keys($days, [16, 4]), ['created_at' => '2026-01-01 10:00:00']);
        $this->history('Just in', ['2026-11-25' => [12, 8]], ['created_at' => '2026-11-24 10:00:00']);

        $data = PlanningWidgets::newDishes(new User, $this->range());

        $this->assertSame(['Just in', 'Fresh'], array_column($data['dishes'], 'dish_name'));
        $this->assertSame(['early', 'above'], array_column($data['dishes'], 'verdict'));
        // (12 x 3 + 4 x 3 + 8) / 7 = 8 plates for the average dish.
        $this->assertSame(8.0, $data['usual']);
        $this->assertSame([12.0, 60, 50], [$data['dishes'][1]['average'], $data['dishes'][1]['rate'], $data['dishes'][1]['against']]);
    }

    public function test_pairings_count_orders_once_and_need_three(): void
    {
        foreach (range(1, 3) as $ignored) {
            // The same order has two lines of A (with and without rice): still one pair.
            $this->order('2026-11-25 10:00:00', lines: [['A', 1], ['A', 1, true], ['B', 2]]);
        }

        $this->order('2026-11-25 10:00:00', lines: [['A', 1], ['C', 1]]);
        $this->order('2026-11-25 10:00:00', lines: [['A', 1], ['C', 1]]);
        $this->order('2026-11-25 10:00:00', 'cancelled', lines: [['A', 1], ['C', 1]]);

        $data = PlanningWidgets::pairings(new User, $this->range());

        $this->assertSame([['dishes' => 'A + B', 'orders' => 3, 'of' => 'B', 'with' => 'A', 'share' => 100]], $data['pairs']);
    }

    /* -------- Customers -------- */

    public function test_new_and_returning_customers_and_how_often(): void
    {
        [$back, $new, $often, $onlyCancelled] = [$this->person(), $this->person(), $this->person(), $this->person()];

        $this->by($back, '2026-09-01 10:00:00');
        $this->by($back, '2026-11-20 10:00:00');
        $this->by($back, '2026-11-21 10:00:00', 'pending');
        $this->by($new, '2026-11-21 10:00:00');
        foreach (['20', '21', '23', '24'] as $day) {
            $this->by($often, "2026-11-{$day} 11:00:00");
        }
        $this->by($onlyCancelled, '2026-11-24 10:00:00', 'cancelled');

        $data = CustomerWidgets::overview(new User, $this->range());

        $this->assertSame([3, 2, 1, 2.3], [$data['customers'], $data['new'], $data['returning'], $data['perCustomer']]);
        $this->assertSame([1, 1, 1, 0], array_column($data['buckets'], 'customers'));
    }

    public function test_repeat_rate_covers_only_months_whose_thirty_days_are_over(): void
    {
        [$soon, $late, $next] = [$this->person(), $this->person(), $this->person()];

        $this->by($soon, '2026-09-05 10:00:00');
        $this->by($soon, '2026-09-20 10:00:00');
        $this->by($late, '2026-09-10 10:00:00');
        $this->by($late, '2026-10-25 10:00:00');
        $this->by($next, '2026-09-28 10:00:00');
        $this->by($next, '2026-10-20 10:00:00');
        // A cancelled second order is not coming back.
        $this->by($late, '2026-09-12 10:00:00', 'cancelled');

        $data = CustomerWidgets::repeat(new User, $this->range());

        $this->assertSame(['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026'], array_column($data['months'], 'label'));
        $this->assertSame(['label' => 'Sep 2026', 'customers' => 3, 'returned' => 2, 'rate' => 67], $data['months'][3]);
        $this->assertNull($data['months'][0]['rate']);
        $this->assertFalse($data['empty']);
    }

    public function test_lapsed_customers_are_those_away_a_month_but_not_for_ever(): void
    {
        [$lapsed, $active, $longGone, $deleted] = [$this->person('Lara'), $this->person(), $this->person(), $this->person()];

        $this->by($lapsed, '2026-08-01 10:00:00');
        $this->by($lapsed, '2026-10-27 23:00:00');
        $this->by($active, '2026-08-01 10:00:00');
        $this->by($active, '2026-10-28 00:30:00');
        $this->by($longGone, '2026-04-01 10:00:00');
        $this->by($deleted, '2026-09-01 10:00:00');
        $deleted->delete();

        $data = CustomerWidgets::lapsed(new User, $this->range());

        $this->assertSame(1, $data['total']);
        $this->assertSame([['name' => 'Lara Cruz', 'orders' => 2, 'last' => 'Oct 27, 2026']], $data['rows']);
    }

    public function test_never_ordered_and_participation_count_accounts_that_can_order(): void
    {
        $this->seedPermissions();
        $acme = Company::factory()->create(['company_name' => 'Acme']);
        $make = function (string $created) use ($acme) {
            $user = $this->person('P', ['company_id' => $acme->company_id, 'created_at' => $created]);
            $user->assignRole(Role::CUSTOMER);

            return $user;
        };

        [$buys, $cancelledOnly, $justJoined] = [$make('2026-01-01 00:00:00'), $make('2026-01-01 00:00:00'), $make('2026-11-25 00:00:00')];
        $this->by($buys, '2026-11-25 10:00:00');
        $this->by($cancelledOnly, '2026-11-25 10:00:00', 'cancelled');

        $this->assertSame(
            ['accounts' => 3, 'never' => 2, 'older' => 1, 'share' => 67, 'graceDays' => 7],
            CustomerWidgets::never(new User, $this->range())
        );

        $this->assertSame(
            [['name' => 'Acme', 'registered' => 3, 'ordering' => 1, 'rate' => 33]],
            CustomerWidgets::participation(new User, $this->range())['companies']
        );
    }

    public function test_companies_and_top_customers_rank_by_sales(): void
    {
        $acme = Company::factory()->create(['company_name' => 'Acme']);
        $zen = Company::factory()->create(['company_name' => 'Zen']);
        $small = $this->person('Sam', ['company_id' => $acme->company_id]);
        $big = $this->person('Bea', ['company_id' => $zen->company_id]);

        $this->by($small, '2026-11-25 10:00:00', 'completed', 100);
        $this->by($small, '2026-11-25 11:00:00', 'pending', 900);
        $this->by($big, '2026-11-25 10:00:00', 'completed', 500);

        $companies = CustomerWidgets::companies(new User, $this->range())['companies'];

        $this->assertSame(['Zen', 'Acme'], array_column($companies, 'name'));
        $this->assertSame([500.0, 100.0], array_column($companies, 'sales'));
        $this->assertSame([1, 2], array_column($companies, 'orders'));

        $top = CustomerWidgets::top(new User, $this->range())['rows'];

        $this->assertSame(['Bea Cruz', 'Sam Cruz'], array_column($top, 'name'));
        $this->assertSame([1, 1], array_column($top, 'orders'));
    }

    public function test_the_widgets_that_name_customers_need_view_orders(): void
    {
        foreach (['view analytics-lapsed-customers', 'view analytics-top-customers'] as $permission) {
            $this->actingAs($this->userWith($permission))->get(route('analytics.index'))->assertForbidden();
            $this->actingAs($this->userWith($permission, 'view orders'))->get(route('analytics.index'))->assertOk();
        }
    }

    /* -------- Speed -------- */

    public function test_no_widget_makes_a_cache_key_from_typed_dates(): void
    {
        $this->dish('Adobo', '2026-11-04', 0, 5);
        $this->order('2026-11-04 10:00:00', lines: [['Adobo', 2]]);
        $admin = $this->superAdmin();

        $written = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written) {
            $written[] = $event->key;
        });

        // Days nothing else in the app would build a key from.
        $period = $this->between('2026-11-03', '2026-11-09');

        foreach (array_keys(config('analytics.tabs')) as $tab) {
            Analytics::widgetsFor($admin, $tab, $period);
        }

        // A file per typed pair of days would fill the cache folder.
        $this->assertSame([], array_values(array_filter($written, fn (string $key) => str_contains($key, '2026-11-03') || str_contains($key, '2026-11-09'))));
    }

    public function test_a_tab_asks_for_the_same_rows_only_once(): void
    {
        $this->dish('Adobo', '2026-11-04', 0, 5, ['includes_rice' => true]);
        $this->order('2026-11-04 10:00:00', lines: [['Adobo', 2]]);
        $admin = $this->superAdmin();
        // A typed range is not cached, so every repeat would be a query.
        $period = $this->between('2026-11-01', '2026-11-26');

        foreach (array_keys(config('analytics.tabs')) as $tab) {
            $seen = [];
            DB::flushQueryLog();
            DB::enableQueryLog();
            Analytics::widgetsFor($admin, $tab, $period);

            foreach (DB::getQueryLog() as $query) {
                $seen[] = $query['query'] . ' ' . json_encode($query['bindings']);
            }

            DB::disableQueryLog();
            $repeated = array_keys(array_filter(array_count_values($seen), fn (int $times) => $times > 1));

            $this->assertSame([], $repeated, $tab);
        }
    }

    public function test_the_shared_rows_are_forgotten_after_the_page(): void
    {
        $this->order('2026-11-25 09:00:00');
        $admin = $this->superAdmin();
        $placed = fn () => Analytics::widgetsFor($admin, 'sales', $this->between('2026-11-01', '2026-11-26'))['order-status']['data']['placed'];

        $this->assertSame(1, $placed());

        $this->order('2026-11-25 10:00:00');

        $this->assertSame(2, $placed());
    }

    /* -------- The CSV export -------- */

    private function csv(User $user, array $query): string
    {
        return $this->actingAs($user)->get(route('analytics.export', $query))->assertOk()->streamedContent();
    }

    public function test_exporting_needs_both_the_export_permission_and_the_widgets(): void
    {
        $this->get(route('analytics.export', ['widget' => 'rice']))->assertRedirect(route('login'));

        // The widget without the export right, and the other way round.
        $this->actingAs($this->userWith('view analytics-rice'))->get(route('analytics.export', ['widget' => 'rice']))->assertForbidden();
        $this->actingAs($this->userWith('export analytics', 'view analytics-pairings'))->get(route('analytics.export', ['widget' => 'rice']))->assertForbidden();
        $this->actingAs($this->userWith('export analytics'))->get(route('analytics.export', ['widget' => 'rice']))->assertForbidden();
        // A widget that names customers still needs its page.
        $this->actingAs($this->userWith('export analytics', 'view analytics-top-customers'))->get(route('analytics.export', ['widget' => 'top-customers']))->assertForbidden();

        $allowed = $this->userWith('export analytics', 'view analytics-rice');
        $this->actingAs($allowed)->get(route('analytics.export', ['widget' => 'nothing']))->assertNotFound();
        $this->actingAs($allowed)->get(route('analytics.export', ['widget' => ['rice']]))->assertNotFound();
        // The page takes a list where a word is expected just as calmly.
        $this->actingAs($allowed)->get(route('analytics.index', ['tab' => ['x']]))->assertOk();
        $this->actingAs($allowed)->get(route('analytics.export', ['widget' => 'rice']))->assertOk();
    }

    public function test_the_csv_link_shows_only_to_who_may_export(): void
    {
        $this->actingAs($this->userWith('view analytics-rice'))->get(route('analytics.index'))
            ->assertOk()->assertDontSee('analytics/export', false);

        $this->actingAs($this->userWith('view analytics-rice', 'export analytics'))->get(route('analytics.index', ['range' => '7d']))
            ->assertOk()->assertSee(e(route('analytics.export', ['widget' => 'rice', 'range' => '7d'])), false);
    }

    public function test_every_widget_exports(): void
    {
        $admin = $this->superAdmin();
        $this->dish('Adobo', '2026-11-25', 0, 5, ['includes_rice' => true]);
        $this->order('2026-11-25 10:00:00', lines: [['Adobo', 3], ['Adobo', 1, true]], payment: ['reference_number' => 'REF-9999']);
        $this->order('2026-11-25 11:00:00', lines: [['Adobo', 1]], payment: ['reference_number' => 'REF-9999'], order: ['discount_status' => 'approved', 'discount_name' => 'Staff', 'discount_amount' => 5]);

        foreach (array_keys(config('analytics.widgets')) as $key) {
            $csv = $this->csv($admin, ['widget' => $key]);

            // The UTF-8 mark Excel needs, then the range.
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, $key);
            $this->assertStringContainsString('Oct 28', $csv, $key);
        }
    }

    public function test_a_csv_carries_the_whole_list_and_does_not_leak_into_the_page(): void
    {
        foreach (range(1, KitchenWidgets::ROWS_SHOWN + 5) as $index) {
            $this->dish('Dish ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT), '2026-11-25', 3, 1);
        }

        $user = $this->userWith('view analytics-sell-through', 'export analytics');
        $shown = fn () => count(Analytics::widgetsFor($user, 'kitchen', $this->range())['sell-through']['data']['dishes']);

        $this->assertSame(KitchenWidgets::ROWS_SHOWN, $shown());
        $this->assertSame(20, substr_count($this->csv($user, ['widget' => 'sell-through']), '"Dish '));
        // The long list was not cached under the page's name.
        $this->assertSame(KitchenWidgets::ROWS_SHOWN, $shown());
    }

    public function test_a_typed_name_cannot_become_a_formula_and_the_export_is_on_the_audit_trail(): void
    {
        $this->assertSame("'=1+1", Export::cell('=1+1'));
        $this->assertSame("'@x", Export::cell('@x'));
        $this->assertSame('-5.5', Export::cell(-5.5));
        $this->assertSame('Ana', Export::cell('Ana'));

        $this->by($this->person('=HYPERLINK("http://x")'), '2026-11-25 10:00:00');
        $user = $this->userWith('view analytics-top-customers', 'view orders', 'export analytics');

        $csv = $this->csv($user, ['widget' => 'top-customers']);

        $this->assertStringContainsString('\'=HYPERLINK', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'analytics.export', 'user_id' => $user->id, 'description' => 'Exported an analytics report']);
    }

    public function test_a_new_widget_without_csv_tables_is_an_error_not_an_empty_file(): void
    {
        $this->expectException(\LogicException::class);

        Export::tables('brand-new', []);
    }

    /* -------- The upgrade -------- */

    public function test_the_upgrade_gives_names_money_and_export_to_super_admin_only(): void
    {
        $this->seedPermissions();
        $later = [
            'view dashboard-insights', 'view dashboard-security', 'view dashboard-my-sellout-alert',
            'view dashboard-my-new-dishes', 'view dashboard-my-discounts', 'export analytics',
            ...array_map(fn (string $widget) => 'view analytics-' . $widget, [
                'order-times', 'discounts', 'review-time', 'prep-suggestions', 'weekday-demand', 'bring-back',
                'menu-fatigue', 'new-dish-performance', 'pairings', 'customers', 'repeat-rate', 'never-ordered',
                'companies', 'company-participation', 'lapsed-customers', 'top-customers',
            ]),
        ];
        $this->assertCount(22, $later);
        DB::table('permissions')->whereIn('name', $later)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $intern = Role::create(['name' => 'Intern', 'guard_name' => 'web']);
        $intern->givePermissionTo(['view my orders']);
        $cashier = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo(['view orders', 'view payments']);

        $migration = require database_path('migrations/2026_11_26_100500_grant_insight_permissions.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Role::findByName(Role::SUPER_ADMIN)->hasAllPermissions($later));
        $this->assertCount(2, $cashier->fresh()->permissions);
        $this->assertEqualsCanonicalizing(
            ['view my orders', 'view dashboard-my-sellout-alert', 'view dashboard-my-new-dishes'],
            $intern->fresh()->permissions->pluck('name')->all()
        );
        $customer = Role::findByName(Role::CUSTOMER);
        $this->assertTrue($customer->hasPermissionTo('view dashboard-my-discounts'));
        $this->assertFalse($customer->hasPermissionTo('export analytics'));
        $this->assertSame([], array_values(array_intersect(Role::CUSTOMER_PERMISSIONS, Analytics::permissions())));
    }
}
