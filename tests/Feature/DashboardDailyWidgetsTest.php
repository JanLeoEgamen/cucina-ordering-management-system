<?php

namespace Tests\Feature;

use App\Models\Discount;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dashboard;
use App\Support\Dashboard\CheckWidgets;
use App\Support\Dashboard\CounterWidgets;
use App\Support\Dashboard\MyWidgets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// What each daily-use dashboard widget shows: the personal ones (own rows only), the counter ones
// and the checks.
class DashboardDailyWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private ?OrderSession $session = null;

    private array $lines = [];

    private array $methods = [];

    private int $number = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday at noon, so "today", "last week" and the months are fixed.
        $this->travelTo(Carbon::parse('2026-11-26 12:00:00'));
    }

    private function window(): OrderSession
    {
        return $this->session ??= OrderSession::factory()->create();
    }

    // The live session's line for a dish of this name (created once per name).
    private function dish(string $name, int $available = 20, int $reserved = 0): SessionDish
    {
        return $this->lines[$name] ??= SessionDish::factory()->create([
            'session_id' => $this->window()->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => $name, 'is_active' => true])->dish_id,
            'available_stock' => $available,
            'reserved_stock' => $reserved,
        ]);
    }

    private function method(string $name): int
    {
        return $this->methods[$name] ??= (int) PaymentMethod::factory()->create(['method_name' => $name, 'is_active' => true])->method_id;
    }

    /**
     * An order with its lines ([dish name => plates]) and its payment.
     */
    private function order(User $user, array $dishes = ['Adobo' => 1], array $order = [], string $method = 'GCash', array $payment = []): Order
    {
        $placed = Order::factory()->create($order + [
            'session_id' => $this->window()->session_id,
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-' . str_pad((string) ++$this->number, 4, '0', STR_PAD_LEFT),
            'order_date' => now(),
            'total_amount' => 100,
        ]);

        foreach ($dishes as $name => $quantity) {
            OrderItem::factory()->create([
                'order_id' => $placed->order_id,
                'session_dish_id' => $this->dish($name)->session_dish_id,
                'quantity' => $quantity,
            ]);
        }

        $state = $order['payment_status'] ?? 'pending';

        Payment::factory()->create($payment + [
            'order_id' => $placed->order_id,
            'payment_method_id' => $this->method($method),
            'amount' => $placed->total_amount,
            'payment_status' => $state,
        ]);

        return $placed;
    }

    private function customer(): User
    {
        return $this->userWith('view menu', 'view my orders');
    }

    /* -------- Permissions, for every widget -------- */

    // A dashboard on which every widget has something to show to $user.
    private function fillEverything(User $user): void
    {
        $other = User::factory()->create();

        $this->order($user, ['Adobo' => 2], ['order_status' => 'completed', 'payment_status' => 'verified']);
        $this->order($user, ['Adobo' => 2], ['order_status' => 'completed', 'payment_status' => 'verified']);
        $this->order($user, ['Adobo' => 1], ['ready_at' => now()->subHour()], PaymentMethod::CASH_ON_PICKUP);
        $this->order($other, ['Adobo' => 1]);

        // Three finished sessions in which the person's favourite ran out (the sell-out alert),
        // a dish on today's menu they never had, and a discount to pick.
        foreach ([3, 2, 1] as $ago) {
            $day = now()->subDays($ago)->toDateString();
            $past = OrderSession::factory()->create(['order_date' => $day]);
            $line = SessionDish::factory()->create(['session_id' => $past->session_id, 'dish_id' => $this->dish('Adobo')->dish_id, 'available_stock' => 0, 'reserved_stock' => 5]);
            $done = Order::factory()->create(['session_id' => $past->session_id, 'user_id' => $user->id, 'order_date' => $day . ' 10:00:00', 'order_status' => 'completed', 'order_number' => 'ORD-PAST-' . ++$this->number]);
            OrderItem::factory()->create(['order_id' => $done->order_id, 'session_dish_id' => $line->session_dish_id, 'quantity' => 1]);
        }

        $this->dish('Never tried');
        Setting::set('discounts.mode', 'orders');
        Discount::factory()->create();
    }

    public function test_a_super_admin_gets_every_widget_and_the_page_draws_them(): void
    {
        $admin = $this->superAdmin();
        $this->fillEverything($admin);

        $widgets = Dashboard::widgetsFor($admin);

        $this->assertEqualsCanonicalizing(array_keys(config('dashboard.widgets')), array_keys($widgets));

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Needs attention', 'At the counter', 'Today so far', 'Live session stock', 'Cash to collect', 'Ready, not picked up'])
            ->assertSeeInOrder(['For you', 'Your active orders', 'Pickup checklist', 'Your usual order', 'Your favourites', 'Your spending', 'Popular this week'])
            ->assertSeeInOrder(['Checks', 'System health', 'Setup checklist']);
    }

    public function test_taking_a_widgets_permission_away_removes_that_widget_and_only_it(): void
    {
        $this->seedPermissions();
        $admin = $this->superAdmin();
        $this->fillEverything($admin);

        foreach (config('dashboard.widgets') as $key => $widget) {
            $user = $this->userWith(...array_values(array_diff(Role::PERMISSIONS, [$widget['permission']])));
            // The personal widgets need this person's own orders.
            $this->fillEverything($user);

            $shown = array_keys(Dashboard::widgetsFor($user));

            $this->assertNotContains($key, $shown, $key);
            $this->assertCount(count(config('dashboard.widgets')) - 1, $shown, $key);
        }
    }

    public function test_a_widget_without_any_of_the_pages_it_is_made_of_is_not_given(): void
    {
        $this->seedPermissions();

        foreach (config('dashboard.widgets') as $key => $widget) {
            if (empty($widget['requires'])) {
                continue;
            }

            $user = $this->userWith($widget['permission']);
            $this->fillEverything($user);

            $this->assertArrayNotHasKey($key, Dashboard::widgetsFor($user), $key);
        }
    }

    public function test_every_widget_has_its_can_block_in_the_view(): void
    {
        $view = file_get_contents(resource_path('views/dashboard.blade.php'));

        foreach (config('dashboard.widgets') as $key => $widget) {
            $this->assertStringContainsString("@can('{$widget['permission']}')", $view, $key);
            $this->assertStringContainsString("\$widgets['{$key}']", $view, $key);
        }
    }

    public function test_a_person_with_no_widget_sees_no_section_heading(): void
    {
        $this->actingAs($this->userWith('view menu', 'view my orders'))->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('At the counter')
            ->assertDontSee('For you')
            ->assertDontSee('Checks');
    }

    public function test_a_new_customer_gets_only_the_empty_active_orders_card(): void
    {
        $this->seedPermissions();
        $user = User::factory()->create();
        $user->assignRole(Role::CUSTOMER);

        $this->assertSame(['my-orders'], array_keys(Dashboard::widgetsFor($user)));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('You have no active orders.')
            ->assertDontSee('At the counter')
            ->assertDontSee('Your spending');
    }

    /* -------- Personal -------- */

    public function test_the_personal_widgets_never_show_another_persons_orders(): void
    {
        $me = $this->customer();
        $other = $this->customer();

        $this->order($other, ['Sisig' => 3], ['order_status' => 'completed', 'payment_status' => 'verified', 'total_amount' => 900]);
        $this->order($other, ['Sisig' => 3], ['order_status' => 'completed', 'payment_status' => 'verified', 'total_amount' => 900]);
        $this->order($other, ['Sisig' => 1], ['total_amount' => 500], PaymentMethod::CASH_ON_PICKUP);

        $this->assertSame(['orders' => [], 'more' => 0], MyWidgets::orders($me));
        $this->assertNull(MyWidgets::pickup($me));
        $this->assertNull(MyWidgets::favorites($me));
        $this->assertNull(MyWidgets::usual($me));
        $this->assertNull(MyWidgets::spending($me));

        // And the other way round the cache does not leak one person's rows to the next.
        $this->assertCount(1, MyWidgets::orders($other)['orders']);
        $this->assertSame([], MyWidgets::orders($me)['orders']);
    }

    public function test_active_orders_put_the_ones_needing_the_customer_first(): void
    {
        $me = $this->customer();

        $this->order($me, order: ['order_number' => 'CHECKING']);
        $this->order($me, order: ['order_number' => 'READY', 'ready_at' => now()], method: PaymentMethod::CASH_ON_PICKUP);
        $this->order($me, order: ['order_number' => 'REJECTED', 'payment_status' => 'rejected']);
        $this->order($me, order: ['order_number' => 'DONE', 'order_status' => 'completed', 'payment_status' => 'verified']);
        $cancelled = $this->order($me, order: ['order_number' => 'GONE', 'order_status' => 'cancelled', 'payment_status' => 'rejected']);

        $orders = MyWidgets::orders($me)['orders'];

        $this->assertSame(['REJECTED', 'READY', 'CHECKING'], array_column($orders, 'number'));
        $this->assertSame(['Payment rejected', 'Ready for pick up', 'Checking your payment'], array_column($orders, 'label'));
        // The link finds the page of My Orders the card is on.
        $this->assertStringContainsString('?order=', $orders[0]['url']);
        $this->assertNotContains($cancelled->order_number, array_column($orders, 'number'));
    }

    public function test_only_five_active_orders_are_listed_and_the_rest_counted(): void
    {
        $me = $this->customer();

        foreach (range(1, 7) as $ignored) {
            $this->order($me);
        }

        $data = MyWidgets::orders($me);

        $this->assertCount(5, $data['orders']);
        $this->assertSame(2, $data['more']);
    }

    public function test_the_pickup_checklist_adds_up_only_the_cash_still_owed(): void
    {
        $me = $this->customer();

        $this->order($me, order: ['order_number' => 'CASH-READY', 'total_amount' => 150, 'ready_at' => now()], method: PaymentMethod::CASH_ON_PICKUP);
        $this->order($me, order: ['total_amount' => 100, 'discount_status' => 'pending'], method: PaymentMethod::CASH_ON_PICKUP);
        $this->order($me, order: ['total_amount' => 400, 'payment_status' => 'verified']);
        $this->order($me, order: ['total_amount' => 999, 'payment_status' => 'rejected'], method: PaymentMethod::CASH_ON_PICKUP);

        $data = MyWidgets::pickup($me);

        $this->assertSame(250.0, $data['cashDue']);
        $this->assertSame(2, $data['cashOrders']);
        $this->assertSame(['CASH-READY'], array_column($data['ready'], 'number'));
        $this->assertSame(2, $data['notReady']);
        $this->assertTrue($data['priceMayChange']);
    }

    public function test_favourites_count_plates_across_rice_lines_and_say_what_is_left(): void
    {
        $me = $this->customer();
        $this->dish('Adobo', 4);
        $this->dish('Sisig', 0);

        $first = $this->order($me, ['Adobo' => 2, 'Sisig' => 1], ['order_status' => 'completed']);
        // The same order has a second Adobo line: without rice.
        OrderItem::factory()->create(['order_id' => $first->order_id, 'session_dish_id' => $this->dish('Adobo')->session_dish_id, 'quantity' => 3, 'no_rice' => true]);
        $this->order($me, ['Sisig' => 1]);
        $this->order($me, ['Kare-kare' => 9], ['order_status' => 'cancelled']);

        $data = MyWidgets::favorites($me);

        $this->assertSame(['Adobo', 'Sisig'], array_column($data['dishes'], 'dish_name'));
        $this->assertSame([5, 2], array_column($data['dishes'], 'plates'));
        $this->assertSame([1, 2], array_column($data['dishes'], 'orders'));
        $this->assertSame([4, 0], array_column($data['dishes'], 'left'));
        $this->assertTrue($data['menuOpen']);

        // Without the menu permission nothing is said about the menu.
        $blind = MyWidgets::favorites(tap($this->userWith('view my orders'), fn (User $user) => $this->order($user)));
        $this->assertFalse($blind['menuOpen']);
        $this->assertSame([null], array_column($blind['dishes'], 'left'));
    }

    public function test_the_usual_order_needs_the_same_dishes_and_quantities_twice(): void
    {
        $me = $this->customer();
        $this->dish('Adobo', 1);

        $this->order($me, ['Adobo' => 2, 'Sisig' => 1], ['order_status' => 'completed']);
        $this->order($me, ['Adobo' => 1, 'Sisig' => 1], ['order_status' => 'completed']);

        $this->assertNull(MyWidgets::usual($me));

        $this->order($me, ['Sisig' => 1, 'Adobo' => 2], ['order_status' => 'completed']);

        $usual = MyWidgets::usual($me);

        $this->assertSame(2, $usual['times']);
        $this->assertSame(['Adobo' => 2, 'Sisig' => 1], array_column($usual['lines'], 'quantity', 'dish_name'));
        // Only 1 Adobo is left and the usual order wants 2.
        $this->assertSame(['Adobo'], $usual['short']);
    }

    public function test_spending_counts_completed_orders_by_month_and_only_approved_savings(): void
    {
        $me = $this->customer();
        $done = ['order_status' => 'completed', 'payment_status' => 'verified'];

        $this->order($me, order: $done + ['total_amount' => 80, 'discount_status' => 'approved', 'discount_amount' => 20]);
        $this->order($me, order: $done + ['total_amount' => 50, 'order_date' => '2026-10-31 23:00:00', 'discount_status' => 'rejected', 'discount_amount' => 0]);
        $this->order($me, order: $done + ['total_amount' => 30, 'order_date' => '2026-06-01 00:00:00']);
        // Outside the six months, still pending, and cancelled: none of them counts.
        $this->order($me, order: $done + ['total_amount' => 1000, 'order_date' => '2026-05-31 12:00:00']);
        $this->order($me, order: ['total_amount' => 1000]);
        $this->order($me, order: ['total_amount' => 1000, 'order_status' => 'cancelled']);

        $data = MyWidgets::spending($me);

        $this->assertSame(['Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'], array_column($data['months'], 'label'));
        $this->assertSame([30.0, 0.0, 0.0, 0.0, 50.0, 80.0], array_column($data['months'], 'total'));
        $this->assertSame(80.0, $data['thisMonth']);
        $this->assertSame(50.0, $data['lastMonth']);
        $this->assertSame(20.0, $data['saved']);
    }

    public function test_popular_lists_only_dishes_two_different_people_ordered_this_week(): void
    {
        $me = $this->customer();
        $other = $this->customer();

        $this->order($me, ['Adobo' => 1, 'Sisig' => 1]);
        $this->order($other, ['Adobo' => 1]);
        // One person ordering a dish many times is not "popular", and would give them away.
        $this->order($me, ['Sisig' => 20]);
        // Last week's and cancelled orders do not count.
        $this->order($other, ['Lechon' => 5], ['order_date' => now()->subDays(7)]);
        $this->order($me, ['Lechon' => 5]);
        $this->order($other, ['Sisig' => 5], ['order_status' => 'cancelled']);

        $data = MyWidgets::popular($me);

        $this->assertSame(['Adobo'], array_column($data['dishes'], 'dish_name'));
        // Names and whether it is on the menu: no counts, nobody's name.
        $this->assertSame(['dish_name', 'left'], array_keys($data['dishes'][0]));
    }

    /* -------- Counter -------- */

    public function test_attention_leaves_cash_out_and_counts_what_holds_plates(): void
    {
        $someone = User::factory()->create();
        $staff = $this->userWith('view payments', 'review discounts', 'view orders');

        $this->order($someone);
        $this->order($someone, method: PaymentMethod::CASH_ON_PICKUP);
        $this->order($someone, order: ['discount_status' => 'pending'], method: PaymentMethod::CASH_ON_PICKUP);
        $this->order($someone, ['Adobo' => 4], ['payment_status' => 'rejected']);
        $ended = OrderSession::factory()->ended()->create();
        $this->order($someone, ['Adobo' => 2], ['session_id' => $ended->session_id], PaymentMethod::CASH_ON_PICKUP);

        $lines = CounterWidgets::attention($staff)['lines'];

        $this->assertSame([1, 1, 1, 1], array_column($lines, 'count'));
        $this->assertSame(['payment to verify', 'discount to review', 'order with a rejected payment', 'order still open after its session ended'], array_column($lines, 'label'));
        $this->assertSame(['4 plates held', '2 plates held'], array_values(array_filter(array_column($lines, 'detail'))));
        $this->assertSame(route('payments.index'), $lines[0]['url']);
    }

    public function test_each_attention_line_needs_its_own_permission(): void
    {
        $someone = User::factory()->create();
        $this->order($someone);
        $this->order($someone, order: ['discount_status' => 'pending'], method: PaymentMethod::CASH_ON_PICKUP);

        $reviewer = CounterWidgets::attention($this->userWith('review discounts'))['lines'];

        $this->assertSame(['discount to review'], array_column($reviewer, 'label'));
        // No link to a page they may not open.
        $this->assertSame([null], array_column($reviewer, 'url'));

        $this->assertSame([], CounterWidgets::attention($this->userWith('view menu'))['lines']);
    }

    public function test_unclaimed_lists_the_longest_waiting_first_and_nothing_when_none(): void
    {
        $staff = $this->userWith('view orders');
        $someone = User::factory()->create();
        $this->order($someone);

        $this->assertNull(CounterWidgets::unclaimed($staff));

        $this->order($someone, order: ['order_number' => 'NEWER', 'ready_at' => now()->subMinutes(5)]);
        $this->order($someone, order: ['order_number' => 'OLDER', 'ready_at' => now()->subHours(2)]);
        $this->order($someone, order: ['ready_at' => now(), 'order_status' => 'completed']);

        $data = CounterWidgets::unclaimed($staff);

        $this->assertSame(['OLDER', 'NEWER'], array_column($data['orders'], 'number'));
        $this->assertSame('2 hours ago', $data['orders'][0]['waiting']);
        $this->assertSame(2, $data['total']);
    }

    public function test_cash_expected_is_the_open_unpaid_cash(): void
    {
        $someone = User::factory()->create();
        $cash = PaymentMethod::CASH_ON_PICKUP;

        $this->order($someone, order: ['total_amount' => 100], method: $cash);
        $this->order($someone, order: ['total_amount' => 60.5, 'ready_at' => now(), 'discount_status' => 'pending'], method: $cash);
        $this->order($someone, order: ['total_amount' => 900]);
        $this->order($someone, order: ['total_amount' => 900, 'payment_status' => 'rejected'], method: $cash);
        $this->order($someone, order: ['total_amount' => 900, 'order_status' => 'completed', 'payment_status' => 'verified'], method: $cash);

        $this->assertSame(
            ['total' => 160.5, 'orders' => 2, 'readyTotal' => 60.5, 'readyOrders' => 1, 'undecided' => 1],
            CounterWidgets::cash($this->userWith())
        );
    }

    public function test_today_is_compared_with_last_week_up_to_the_same_time(): void
    {
        $someone = User::factory()->create();
        $done = ['order_status' => 'completed', 'payment_status' => 'verified'];

        $this->order($someone, order: $done + ['total_amount' => 200, 'order_date' => '2026-11-26 09:00:00'], payment: ['verified_at' => '2026-11-26 09:30:00']);
        $this->order($someone, order: ['total_amount' => 100, 'order_date' => '2026-11-26 11:00:00']);
        $this->order($someone, order: ['total_amount' => 700, 'order_status' => 'cancelled', 'order_date' => '2026-11-26 10:00:00']);
        // Last Thursday before noon counts, after noon does not.
        $this->order($someone, order: $done + ['total_amount' => 100, 'order_date' => '2026-11-19 11:59:00'], payment: ['verified_at' => '2026-11-19 11:59:30']);
        $this->order($someone, order: $done + ['total_amount' => 5000, 'order_date' => '2026-11-19 18:00:00'], payment: ['verified_at' => '2026-11-19 18:10:00']);
        // Another day entirely.
        $this->order($someone, order: ['total_amount' => 5000, 'order_date' => '2026-11-25 09:00:00']);

        $data = CounterWidgets::today($this->userWith());
        $tiles = collect($data['tiles'])->keyBy('label');

        $this->assertSame([2, 1, 100], [$tiles['Orders']['value'], $tiles['Orders']['before'], $tiles['Orders']['change']]);
        $this->assertSame([300.0, 100.0, 200], [$tiles['Order value']['value'], $tiles['Order value']['before'], $tiles['Order value']['change']]);
        $this->assertSame([150.0, 100.0, 50], [$tiles['Average order']['value'], $tiles['Average order']['before'], $tiles['Average order']['change']]);
        $this->assertSame([200.0, 100.0, 100], [$tiles['Collected']['value'], $tiles['Collected']['before'], $tiles['Collected']['change']]);
        $this->assertSame([200.0, 100.0, 1], [$data['sales'], $data['expected'], $data['cancelled']]);
        $this->assertSame('Thu, Nov 19', $data['comparedWith']);
    }

    public function test_today_gives_no_percentage_against_nothing(): void
    {
        $data = CounterWidgets::today($this->userWith());

        $this->assertSame([null, null, null, null], array_column($data['tiles'], 'change'));
    }

    public function test_the_live_session_widget_puts_what_is_running_out_first(): void
    {
        $this->dish('Plenty', 20, 5);
        $this->dish('Gone', 0, 8);
        $this->dish('Almost', 5, 1);
        $hidden = $this->dish('Hidden', 9, 0);
        $held = $this->dish('Pulled', 3, 2);
        DB::table('session_dishes')->whereIn('session_dish_id', [$hidden->session_dish_id, $held->session_dish_id])->update(['is_available' => false]);

        $data = CounterWidgets::liveSession($this->userWith());

        // Off the menu with nothing ordered is left out; with plates held it stays.
        $this->assertSame(['Gone', 'Almost', 'Plenty', 'Pulled'], array_column($data['dishes'], 'dish_name'));
        $this->assertSame(['sold_out', 'low', 'ok', 'off'], array_column($data['dishes'], 'state'));
        $this->assertSame(16, $data['ordered']);
        $this->assertSame(44, $data['prepared']);
        $this->assertNull($data['url']);
    }

    public function test_the_live_session_widget_is_not_drawn_without_a_live_session(): void
    {
        $this->assertNull(CounterWidgets::liveSession($this->userWith()));
    }

    /* -------- Checks -------- */

    public function test_system_health_counts_failed_and_stuck_jobs(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->userWith();

        $this->assertSame(['mode' => 'database', 'failed' => 0, 'stuck' => 0, 'oldestMinutes' => null], CheckWidgets::health($user));

        $job = ['queue' => 'notifications', 'payload' => '{}', 'attempts' => 0, 'created_at' => now()->getTimestamp()];
        DB::table('jobs')->insert([
            $job + ['reserved_at' => null, 'available_at' => now()->subMinutes(30)->getTimestamp()],
            // Just queued, delayed for later, and being worked on: none is stuck.
            $job + ['reserved_at' => null, 'available_at' => now()->subMinute()->getTimestamp()],
            $job + ['reserved_at' => null, 'available_at' => now()->addHour()->getTimestamp()],
            $job + ['reserved_at' => now()->getTimestamp(), 'available_at' => now()->subHour()->getTimestamp()],
        ]);
        DB::table('failed_jobs')->insert(['uuid' => 'a', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'secret@example.com refused', 'failed_at' => now()]);

        // The answer is kept for a minute (no query on every dashboard load), then read again.
        $this->assertSame(0, CheckWidgets::health($user)['failed']);
        $this->travel(CheckWidgets::FRESH_FOR_SECONDS + 1)->seconds();

        // 30 minutes old when it was made, and the clock has moved on by a minute since.
        $this->assertSame(['mode' => 'database', 'failed' => 1, 'stuck' => 1, 'oldestMinutes' => 31], CheckWidgets::health($user));

        $this->actingAs($this->userWith('view dashboard-system-health'))->get(route('dashboard'))
            ->assertSee('1 job is waiting too long')
            ->assertSee('1 failed job')
            ->assertDontSee('secret@example.com');
    }

    public function test_system_health_says_when_the_queue_is_not_a_queue(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame(['mode' => 'sync'], CheckWidgets::health($this->userWith()));
    }

    public function test_the_setup_checklist_shows_each_gap_only_to_who_can_fix_it(): void
    {
        $admin = $this->userWith('view dishes', 'view users', 'view payment-methods', 'view discounts');
        $admin->assignRole(Role::create(['name' => 'Setter', 'guard_name' => 'web']));

        $this->assertSame([], CheckWidgets::setup($admin)['lines']);

        Dish::factory()->create(['price' => 0, 'image' => null, 'is_active' => true]);
        Dish::factory()->create(['price' => 0, 'image' => null, 'is_active' => false]);
        User::factory()->create(['company_id' => null]);
        PaymentMethod::factory()->create(['method_name' => 'Maya', 'account_number' => null, 'qr_image' => null, 'is_active' => true]);
        PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP, 'account_number' => null, 'qr_image' => null, 'is_active' => true]);
        Discount::factory()->create(['valid_until' => now()->addDays(7)->toDateString()]);
        Discount::factory()->create(['valid_until' => now()->addDays(8)->toDateString()]);
        Discount::factory()->create(['valid_until' => now()->subDay()->toDateString()]);

        $lines = CheckWidgets::setup($admin)['lines'];
        $counts = array_column($lines, 'count', 'label');

        $this->assertSame(1, $counts['dish priced at zero']);
        $this->assertSame(1, $counts['dish without a picture']);
        $this->assertSame(1, $counts['account with no company']);
        // The user just made has no role either.
        $this->assertSame(1, $counts['account with no role (it cannot open anything)']);
        $this->assertSame(1, $counts['payment method with no account number or QR']);
        $this->assertSame(1, $counts['discount ends within a week']);

        $cook = CheckWidgets::setup($this->userWith('view dishes'))['lines'];

        $this->assertSame([route('dishes.index'), route('dishes.index')], array_column($cook, 'url'));
    }

    /* -------- The upgrade -------- */

    public function test_the_upgrade_hands_out_the_widgets_without_touching_other_grants(): void
    {
        $this->seedPermissions();

        // What THAT migration hands out (later widgets have migrations of their own).
        $new = collect([
            'today', 'live-session', 'cash-expected', 'unclaimed', 'system-health', 'setup-checklist',
            'my-orders', 'my-pickup', 'my-usual', 'my-favorites', 'my-spending', 'popular-dishes',
        ])->map(fn (string $widget) => 'view dashboard-' . $widget);
        DB::table('permissions')->whereIn('name', $new)->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // A customised ordering role, and a cashier.
        $intern = Role::create(['name' => 'Intern', 'guard_name' => 'web']);
        $intern->givePermissionTo('view my orders');
        $cashier = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo(['view payments', 'view orders']);
        $before = Role::permissionIds((int) Role::findByName(Role::CUSTOMER)->id);

        $migration = require database_path('migrations/2026_11_26_100200_grant_daily_dashboard_widgets.php');
        $migration->up();
        $migration->up();

        $this->assertEqualsCanonicalizing(
            ['view my orders', 'view dashboard-my-orders', 'view dashboard-my-pickup', 'view dashboard-my-usual', 'view dashboard-my-favorites', 'view dashboard-my-spending'],
            $intern->fresh()->permissions->pluck('name')->all()
        );
        $this->assertEqualsCanonicalizing(['view payments', 'view orders'], $cashier->fresh()->permissions->pluck('name')->all());
        $this->assertTrue(Role::findByName(Role::SUPER_ADMIN)->hasAllPermissions($new->all()));

        $customer = Role::findByName(Role::CUSTOMER);
        $this->assertTrue($customer->hasPermissionTo('view dashboard-popular-dishes'));
        $this->assertFalse($customer->hasPermissionTo('view dashboard-today'));
        // Nothing it had is taken away.
        $this->assertSame([], array_diff($before, Role::permissionIds((int) $customer->id)));
    }

    public function test_the_daily_upgrade_does_nothing_on_a_database_without_roles(): void
    {
        $migration = require database_path('migrations/2026_11_26_100200_grant_daily_dashboard_widgets.php');
        $migration->up();

        $this->assertDatabaseMissing('permissions', ['name' => 'view dashboard-today']);
    }

    /* -------- The cancel reason, shown to staff -------- */

    public function test_staff_see_who_cancelled_and_why_and_the_customer_does_not(): void
    {
        $customer = $this->userWith('view my orders');
        $order = $this->order($customer);
        $staff = $this->userWith('view orders', 'cancel orders');

        $this->actingAs($staff)->postJson(route('orders.cancel', $order->order_id), ['reason' => 'Sold at the <counter>'])->assertOk();

        $row = $this->actingAs($staff)->getJson(route('orders.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data.0');

        // The feed escapes its text; order-popup.js decodes it into textContent.
        $this->assertSame('Sold at the &lt;counter&gt;', $row['cancel_reason']);
        $this->assertSame(e($staff->name), $row['cancelled_by_name']);
        $this->assertNotNull($row['cancelled_at']);

        $this->actingAs($staff)->get(route('orders.index'))->assertSee('id="order-details-cancelled"', false);

        $this->actingAs($customer)->get(route('my-orders.index'))
            ->assertOk()
            ->assertDontSee('Sold at the')
            ->assertDontSee('cancel_reason');
    }
}
