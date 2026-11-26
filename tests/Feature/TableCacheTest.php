<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\DishType;
use App\Models\Order;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\TableCache;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Master data and the staff feeds are cached WITH their tables (TableCache): the write that
// changes a table is what drops the read, whoever wrote it (Eloquent, the query builder, a soft
// delete). A cached read that survives a write would show staff a table that lies, so each way of
// writing is covered, plus the one thing that must NOT be cached: what depends on the clock.
class TableCacheTest extends TestCase
{
    use RefreshDatabase;

    private function queries(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $work();

        return $count;
    }

    public function test_a_second_read_costs_no_query(): void
    {
        Dish::factory()->create();
        Dish::getAll();
        Role::options();

        $this->assertSame(0, $this->queries(fn () => Dish::getAll()));
        $this->assertSame(0, $this->queries(fn () => Role::options()));
    }

    public function test_every_kind_of_write_is_seen_by_the_next_read(): void
    {
        $this->assertCount(0, Dish::getAll());

        $dish = Dish::factory()->create(['dish_name' => 'Adobo']); // Eloquent insert
        $this->assertSame(['Adobo'], Dish::getAll()->pluck('dish_name')->all());

        DB::table('dishes')->where('dish_id', $dish->dish_id)->update(['dish_name' => 'Sinigang']); // query builder
        $this->assertSame(['Sinigang'], Dish::getAll()->pluck('dish_name')->all());

        DB::table('dishes')->where('dish_id', $dish->dish_id)->update(['deleted_at' => now()]); // soft delete
        $this->assertCount(0, Dish::getAll());
    }

    public function test_a_read_that_joins_another_table_follows_that_table_too(): void
    {
        $dish = Dish::factory()->create();
        $type = DishType::factory()->create(['type_name' => 'Soup']);
        DB::table('dish_dish_types')->insert(['dish_id' => $dish->dish_id, 'dish_type_id' => $type->dish_type_id]);
        $this->assertSame('Soup', Dish::getAll()->first()->dish_types[0]['type_name']);

        DB::table('dish_types')->where('dish_type_id', $type->dish_type_id)->update(['type_name' => 'Stew']);

        $this->assertSame('Stew', Dish::getAll()->first()->dish_types[0]['type_name']);
    }

    public function test_the_checkout_methods_follow_the_table(): void
    {
        $this->assertCount(0, PaymentMethod::activeOptions());

        $method = PaymentMethod::factory()->create(['is_active' => true]);
        $this->assertCount(1, PaymentMethod::activeOptions());

        PaymentMethod::updatePaymentMethod((int) $method->method_id, ['is_active' => false] + $method->only(['method_name', 'account_name', 'account_number']));
        $this->assertCount(0, PaymentMethod::activeOptions());
    }

    public function test_the_users_feed_follows_a_role_change(): void
    {
        $this->seedPermissions();
        $user = User::factory()->create();
        $this->assertSame([], User::getAll()->firstWhere('id', $user->id)->role_names);

        $user->assignRole(Role::SUPER_ADMIN);

        $this->assertSame([Role::SUPER_ADMIN], User::getAll()->firstWhere('id', $user->id)->role_names);
    }

    public function test_the_orders_feed_follows_an_order_and_its_status(): void
    {
        $this->assertCount(0, Order::getAll());

        $order = Order::factory()->create();
        $this->assertCount(1, Order::getAll());

        DB::table('orders')->where('order_id', $order->order_id)->update(['order_status' => 'cancelled']);
        $this->assertSame('cancelled', Order::getAll()[0]->order_status);
    }

    public function test_a_session_status_follows_the_clock_even_when_its_rows_are_cached(): void
    {
        Carbon::setTestNow('2026-11-23 06:00:00');
        OrderSession::factory()->create(['order_date' => '2026-11-23', 'start_time' => '09:00:00', 'end_time' => '11:00:00']);

        $this->assertSame('scheduled', OrderSession::getAll()->first()->status);

        Carbon::setTestNow('2026-11-23 10:00:00'); // no write in between
        $this->assertSame('live', OrderSession::getAll()->first()->status);

        Carbon::setTestNow('2026-11-23 12:00:00');
        $this->assertSame('ended', OrderSession::getAll()->first()->status);

        Carbon::setTestNow();
    }
    public function test_my_orders_follow_a_new_order_a_status_change_and_stay_per_customer(): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $this->assertCount(0, Order::pageOfUser($mine->id)->items());

        $order = Order::factory()->create(['user_id' => $mine->id]);
        Order::factory()->create(['user_id' => $other->id]);

        $this->assertCount(1, Order::pageOfUser($mine->id)->items());
        $this->assertCount(1, Order::pageOfUser($other->id)->items());

        DB::table('orders')->where('order_id', $order->order_id)->update(['order_status' => 'cancelled']);

        $this->assertSame('cancelled', Order::pageOfUser($mine->id)->items()[0]->order_status);
        $this->assertCount(0, Order::pageOfUser($mine->id, ['status' => 'pending'])->items());
        $this->assertCount(1, Order::pageOfUser($mine->id, ['q' => $order->order_number])->items(), 'a search is read fresh');
    }

    public function test_the_dashboards_open_orders_follow_the_tables(): void
    {
        $this->assertCount(0, Order::openForDashboard());

        $payment = \App\Models\Payment::factory()->create(['payment_status' => 'pending']);

        $this->assertSame('pending', Order::openForDashboard()->first()->payment_state);

        DB::table('payments')->where('order_id', $payment->order_id)->update(['payment_status' => 'rejected']);

        $this->assertSame('rejected', Order::openForDashboard()->first()->payment_state);
    }
    public function test_the_database_cache_driver_cannot_disturb_the_id_of_an_insert(): void
    {
        // The listener used to write the cache INSIDE the statement event: with the database driver on
        // SQLite that insert replaced the id insertGetId was about to read (migrate:fresh --seed died
        // with a foreign key error because the role got the wrong id).
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');

        $id = DB::table('roles')->insertGetId(['name' => 'Cook', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame($id, (int) DB::table('roles')->where('name', 'Cook')->value('id'));
    }

    public function test_a_missing_cache_table_never_breaks_a_write(): void
    {
        // migrate:fresh writes rows while the cache table is not there yet.
        config(['cache.default' => 'database']);
        Cache::forgetDriver('database');
        Schema::drop('cache');

        Dish::factory()->create();
        TableCache::settle();

        $this->assertSame(1, DB::table('dishes')->count());
    }

    public function test_finishing_a_migration_starts_the_cache_from_nothing(): void
    {
        Dish::factory()->create();
        $this->assertCount(1, Dish::getAll());

        // Rows changed behind the listener's back (what migrate:fresh does to a file cache).
        DB::connection()->getPdo()->exec('delete from dishes');
        $this->assertCount(1, Dish::getAll(), 'still served from the cache');

        event(new MigrationsEnded('up', []));

        $this->assertCount(0, Dish::getAll());
    }
}
