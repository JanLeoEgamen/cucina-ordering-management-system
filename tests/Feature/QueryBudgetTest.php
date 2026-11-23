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
}
