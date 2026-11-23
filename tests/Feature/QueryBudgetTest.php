<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// With the database on another machine every query is a network trip, so the hot paths (read on
// every request, or polled every second by the bell) are kept off the database. These tests pin
// that, and that the caches are never stale after a write.
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function queriesDuring(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_settings_are_read_with_one_query_and_never_stale(): void
    {
        Setting::set(Setting::APP_NAME, 'First');
        Cache::flush();

        $this->assertSame(1, $this->queriesDuring(function () {
            Setting::get(Setting::APP_NAME);
            Setting::get(Setting::CUSTOMER_CANCEL);
            Setting::emailNotifications();
            Setting::support();
        }));

        Setting::set(Setting::CUSTOMER_CANCEL, '1');

        $this->assertTrue(Setting::customerCancel(), 'a save is read back at once');
    }

    public function test_a_setting_saved_inside_a_transaction_is_cached_only_after_the_commit(): void
    {
        Setting::get(Setting::APP_NAME); // warms the cache

        DB::transaction(function () {
            Setting::setSessionWindow('09:00', '11:00');
            Setting::get(Setting::SESSION_START_TIME); // a read inside must not pin the old value
        });

        $this->assertSame('09:00', Setting::get(Setting::SESSION_START_TIME));
    }

    public function test_the_bell_poll_costs_no_notification_query_until_something_changes(): void
    {
        $user = $this->superAdmin();

        $this->actingAs($user)->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread', 0);

        $notificationQueries = function () use ($user) {
            $count = 0;
            DB::listen(function ($query) use (&$count) {
                if (str_contains($query->sql, 'user_notifications')) {
                    $count++;
                }
            });
            $this->actingAs($user)->getJson(route('notifications.feed', ['after' => 0]))->assertOk();

            return $count;
        };

        $this->assertSame(0, $notificationQueries());

        UserNotification::insertMany([['user_id' => $user->id, 'type' => 'test', 'title' => 'Hello']]);

        $this->actingAs($user)->getJson(route('notifications.feed', ['after' => 0]))
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('items.0.title', 'Hello');

        $this->assertTrue(UserNotification::markAllRead($user->id) === 1);

        $this->actingAs($user)->getJson(route('notifications.feed'))->assertJsonPath('unread', 0);
    }
    // A session used to cost ~3 queries per dish (an `exists` check, a lookup and an insert, each insert
    // committing on its own): ~100 for 30 dishes, which is what made "Add Session" take seconds.
    public function test_creating_a_session_costs_the_same_number_of_queries_for_any_number_of_dishes(): void
    {
        $admin = $this->superAdmin();
        $dishes = \App\Models\Dish::factory()->count(25)->create()->pluck('dish_id')->all();

        $post = function (array $ids) use ($admin) {
            $payload = [
                'order_date' => now()->addDay()->toDateString(), 'start_time' => '09:00', 'end_time' => '17:00',
                'dish_ids' => $ids, 'stocks' => array_fill_keys($ids, 10),
            ];

            return $this->queriesDuring(fn () => $this->actingAs($admin)->postJson(route('order-sessions.store'), $payload)->assertOk());
        };

        $post(array_slice($dishes, 0, 1)); // warm-up: the first request also loads the permissions
        DB::table('order_sessions')->delete();
        $few = $post(array_slice($dishes, 0, 2));
        DB::table('order_sessions')->delete();
        $many = $post($dishes);

        $this->assertSame($few, $many, "2 dishes ran $few queries, 25 dishes ran $many");
        $this->assertSame(25, DB::table('session_dishes')->whereNull('deleted_at')->count());
    }

    public function test_a_session_is_not_left_behind_when_one_of_its_dishes_cannot_be_saved(): void
    {
        $admin = $this->superAdmin();
        $dish = \App\Models\Dish::factory()->create();

        try {
            \App\Models\OrderSession::createSession(
                ['order_date' => now()->toDateString(), 'start_time' => '09:00', 'end_time' => '17:00', 'created_by' => $admin->id],
                [$dish->dish_id => 5, 999999 => 5] // the second dish does not exist: a foreign key refuses it
            );
            $this->fail('the second dish should have been refused');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        }

        $this->assertSame(0, DB::table('order_sessions')->count());
        $this->assertSame(0, DB::table('session_dishes')->count());
    }
}
