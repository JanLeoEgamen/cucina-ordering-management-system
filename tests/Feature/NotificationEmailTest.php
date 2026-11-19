<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// The email copy of notifications (System Configuration -> Notifications -> "Send
// notifications by email"): off by default, the same people and words as the bell when
// on, and a mail problem can never cost an order or its in-app notification. The test
// queue is `sync`, so the jobs run inside the request.
class NotificationEmailTest extends TestCase
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

    /** @return array{0: OrderSession, 1: SessionDish, 2: PaymentMethod} */
    private function menu(int $stock = 10): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Adobo', 'price' => 50])->dish_id,
            'available_stock' => $stock,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);

        return [$session, $line, $cash];
    }

    private function place(User $customer, array $menu): void
    {
        [$session, $line, $cash] = $menu;

        $this->actingAs($customer)->postJson(route('orders.store'), [
            'session_id' => $session->session_id,
            'payment_method_id' => $cash->method_id,
            'items' => [$line->session_dish_id => 1],
        ])->assertOk();
    }

    /* -------- the switch -------- */

    public function test_email_is_off_by_default_and_nothing_is_mailed(): void
    {
        Mail::fake();
        $this->assertFalse(Setting::emailNotifications());

        $customer = $this->customer();
        $watcher = $this->watcher();
        $this->place($customer, $this->menu());

        // The bell still works.
        $this->assertDatabaseHas('user_notifications', ['user_id' => $customer->id, 'type' => OrderNotifier::PLACED]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $watcher->id, 'type' => OrderNotifier::PLACED]);
        Mail::assertNothingSent();
    }

    public function test_switched_on_the_customer_and_the_watcher_are_emailed_in_their_own_words(): void
    {
        Mail::fake();
        Setting::setEmailNotifications(true);

        $customer = $this->customer();
        $watcher = $this->watcher();
        $this->place($customer, $this->menu());

        Mail::assertSent(NotificationMail::class, 2);

        // Email is an addition, never a replacement: the same two people also have the
        // notification in the system (the bell and the Notifications page).
        foreach ([$customer, $watcher] as $person) {
            $this->assertSame(1, DB::table('user_notifications')->where('user_id', $person->id)->count());
            $this->actingAs($person)->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread', 1);
        }

        // The customer: "Order X placed", with a link to the whole address.
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($customer->email)
            && str_contains($mail->heading, 'placed')
            && str_contains((string) $mail->text, 'Cash on Pick up')
            && str_starts_with((string) $mail->link, 'http'));

        // The watcher: staff words, naming the customer.
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($watcher->email)
            && str_contains($mail->heading, $customer->name . ' placed order'));
    }

    public function test_the_email_says_exactly_what_the_bell_says(): void
    {
        Mail::fake();
        Setting::setEmailNotifications(true);

        $customer = $this->customer();
        $this->place($customer, $this->menu());

        $bell = DB::table('user_notifications')->where('user_id', $customer->id)->first();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->heading === $bell->title
            && $mail->text === $bell->body
            && $mail->link === url($bell->url));
    }

    public function test_a_watcher_is_not_emailed_about_their_own_action(): void
    {
        Mail::fake();
        Setting::setEmailNotifications(true);

        // Someone who is both customer and watcher places the order: one email, theirs.
        $both = $this->userWith('view menu', 'create orders', 'view my orders', 'view orders');
        Setting::setNotificationWatchers([$both->id]);
        $this->place($both, $this->menu());

        Mail::assertSent(NotificationMail::class, 1);
    }

    public function test_sold_out_is_emailed_to_the_watchers_only(): void
    {
        Mail::fake();
        Setting::setEmailNotifications(true);

        [$session, $line] = $this->menu(1);
        DB::table('session_dishes')->where('session_dish_id', $line->session_dish_id)->update(['available_stock' => 0]);
        $watcher = $this->watcher();
        $bystander = $this->customer();

        OrderNotifier::deliverSoldOut([$line->session_dish_id], $session->session_id, null);

        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($watcher->email)
            && $mail->heading === 'Adobo is sold out');
        Mail::assertNotSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo($bystander->email));
    }

    /* -------- when mail goes wrong -------- */

    public function test_a_mail_failure_never_fails_the_order_or_loses_the_bell_notification(): void
    {
        Setting::setEmailNotifications(true);
        // A mailer that does not exist: every send throws.
        config(['mail.default' => 'no-such-mailer']);

        $customer = $this->customer();
        $this->place($customer, $this->menu());

        $this->assertSame(1, DB::table('orders')->count());
        // Written exactly once: a failing email must not make the job retry the rows.
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $customer->id)->count());
    }

    public function test_one_bad_address_does_not_stop_the_others(): void
    {
        Mail::fake();
        Setting::setEmailNotifications(true);

        $job = new \App\Jobs\SendNotificationEmails([
            ['user_id' => 1, 'email' => '', 'title' => 'Broken', 'body' => null, 'url' => null],
            ['user_id' => 2, 'email' => 'ok@example.com', 'title' => 'Fine', 'body' => 'Text', 'url' => '/my-orders'],
        ]);
        $job->handle();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $mail) => $mail->hasTo('ok@example.com')
            && $mail->link === url('/my-orders'));
    }

    public function test_the_email_job_is_not_retried(): void
    {
        // A retry would send again to everyone who already got the first attempt.
        $this->assertSame(1, (new \App\Jobs\SendNotificationEmails([]))->tries);
    }

    public function test_switching_it_on_adds_one_job_and_it_stays_on_the_notifications_queue(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendNotificationEmails::class]);
        Setting::setEmailNotifications(true);

        $this->place($this->customer(), $this->menu());

        \Illuminate\Support\Facades\Bus::assertDispatchedTimes(\App\Jobs\SendNotificationEmails::class, 1);
        \Illuminate\Support\Facades\Bus::assertDispatched(
            \App\Jobs\SendNotificationEmails::class,
            fn (\App\Jobs\SendNotificationEmails $job) => $job->queue === config('notifications.queue')
        );
    }

    /* -------- System Configuration -------- */

    private function payload(array $extra = []): array
    {
        return $extra + ['session_start_time' => '09:00', 'session_end_time' => '17:00'];
    }

    public function test_saving_the_switch_needs_edit_settings_and_stores_it(): void
    {
        $this->actingAs($this->userWith('view settings'))
            ->putJson(route('settings.update'), $this->payload(['email_notifications' => 1]))->assertForbidden();
        $this->assertFalse(Setting::emailNotifications());

        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['email_notifications' => '1']))->assertOk();
        $this->assertTrue(Setting::emailNotifications());

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['email_notifications' => '0']))->assertOk();
        $this->assertFalse(Setting::emailNotifications());
    }

    public function test_a_save_that_leaves_the_switch_out_changes_nothing(): void
    {
        Setting::setEmailNotifications(true);

        $this->actingAs($this->superAdmin())->putJson(route('settings.update'), $this->payload())->assertOk();

        $this->assertTrue(Setting::emailNotifications());
    }

    public function test_the_switch_is_validated(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson(route('settings.update'), $this->payload(['email_notifications' => 'maybe']))
            ->assertStatus(422)->assertJsonValidationErrors('email_notifications');

        $this->assertFalse(Setting::emailNotifications());
    }

    public function test_the_page_shows_the_switch_and_warns_when_the_mailer_only_logs(): void
    {
        $viewer = $this->userWith('view settings');

        config(['mail.default' => 'smtp']);
        $this->actingAs($viewer)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="email_notifications"', false)
            ->assertDontSee('only written to the log');

        Setting::setEmailNotifications(true);

        $this->actingAs($viewer)->get(route('settings.index'))
            ->assertOk()->assertDontSee('only written to the log');

        config(['mail.default' => 'log']);
        $this->actingAs($viewer)->get(route('settings.index'))
            ->assertOk()->assertSee('only written to the log');
    }
}
