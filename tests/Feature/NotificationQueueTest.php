<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// The queue half of notifications: the request only pushes a job, the job writes the
// rows, and a job that fails can never break an order. (Who is told what lives in NotificationTest.)
class NotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    private function watcher(): User
    {
        $user = $this->userWith('view orders', 'view payments');
        Setting::setNotificationWatchers(array_merge(Setting::notificationWatchers(), [$user->id]));

        return $user;
    }

    private function placeCashOrder(User $customer): int
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Adobo', 'price' => 50])->dish_id,
            'available_stock' => 10,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        $this->actingAs($customer)->postJson(route('orders.store'), [
            'session_id' => $session->session_id,
            'payment_method_id' => $cash->method_id,
            'items' => [$line->session_dish_id => 1],
        ])->assertOk();

        return (int) DB::table('orders')->orderByDesc('order_id')->value('order_id');
    }

    /* ---- the request only pushes a job ---------------------------------- */

    public function test_placing_an_order_pushes_one_job_on_the_notifications_queue_and_writes_nothing_itself(): void
    {
        Queue::fake();
        $customer = $this->customer();
        $this->watcher();

        $orderId = $this->placeCashOrder($customer);

        Queue::assertPushedOn('notifications', DeliverNotification::class, function (DeliverNotification $job) use ($orderId, $customer) {
            return $job->kind === 'order'
                && $job->data['order_id'] === $orderId
                && $job->data['event'] === OrderNotifier::PLACED
                && $job->actorId === $customer->id;
        });
        Queue::assertPushed(DeliverNotification::class, 1);
        $this->assertSame(0, DB::table('user_notifications')->count(), 'the worker writes them, not the request');
    }

    public function test_the_queue_name_comes_from_config(): void
    {
        config(['notifications.queue' => 'rush']);

        $this->assertSame('rush', (new DeliverNotification('order', ['order_id' => 1, 'event' => 'x']))->queue);
    }

    public function test_the_worker_run_writes_the_rows(): void
    {
        Queue::fake();
        $customer = $this->customer();
        $watcher = $this->watcher();
        $this->placeCashOrder($customer);

        Queue::assertPushed(DeliverNotification::class, function (DeliverNotification $job) {
            $job->handle();

            return true;
        });

        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $customer->id)->count());
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $watcher->id)->count());
    }

    public function test_the_job_retries_three_times_with_backoff_and_logs_when_it_gives_up(): void
    {
        $job = new DeliverNotification('order', ['order_id' => 7, 'event' => OrderNotifier::PLACED]);

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 30], $job->backoff());

        Log::spy();
        $job->failed(new \RuntimeException('boom'));

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Notification could not be delivered' && $context['error'] === 'boom');
    }

    public function test_a_job_that_fails_never_fails_the_order(): void
    {
        Schema::drop('user_notifications'); // the sync queue runs the job in the request and it fails

        $this->placeCashOrder($this->customer());

        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_a_sold_out_job_is_queued_only_when_a_dish_reached_zero(): void
    {
        Queue::fake();
        $this->watcher();
        $customer = $this->customer();
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Adobo', 'price' => 50])->dish_id,
            'available_stock' => 1,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        $this->actingAs($customer)->postJson(route('orders.store'), [
            'session_id' => $session->session_id,
            'payment_method_id' => $cash->method_id,
            'items' => [$line->session_dish_id => 1],
        ])->assertOk();

        Queue::assertPushed(DeliverNotification::class, 2); // placed + sold out
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->kind === 'sold-out' && $job->data['session_dish_ids'] === [$line->session_dish_id]);
    }

    /* ---- retries -------------------------------------------------------- */

    public function test_a_failed_sold_out_run_can_be_retried(): void
    {
        $this->watcher();
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Adobo'])->dish_id,
            'available_stock' => 0,
        ]);

        Schema::drop('user_notifications');

        try {
            OrderNotifier::deliverSoldOut([$line->session_dish_id], $session->session_id, null);
            $this->fail('the insert should have failed');
        } catch (\Throwable) {
            // expected: the queue would now retry the job
        }

        $this->assertFalse(Cache::has('sold-out-notified:' . $line->session_dish_id), 'the retry must be able to announce it');
    }
}
