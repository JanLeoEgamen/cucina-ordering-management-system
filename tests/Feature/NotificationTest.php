<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\OrderNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Notifications: who is told what when an order moves, the watchers picked in System
// Configuration, the bell's feed and the page. The sending is a queued job (see
// NotificationQueueTest); the test queue is `sync`, so it runs inside the request.
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        return $this->userWith('view menu', 'create orders', 'view my orders');
    }

    // A watcher is any user picked in System Configuration.
    private function watcher(string ...$permissions): User
    {
        $user = $this->userWith(...($permissions ?: ['view orders', 'view payments']));
        Setting::setNotificationWatchers(array_merge(Setting::notificationWatchers(), [$user->id]));

        return $user;
    }

    /**
     * @return array{0: OrderSession, 1: SessionDish, 2: PaymentMethod, 3: PaymentMethod}
     */
    private function menu(int $stock = 10): array
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Adobo', 'price' => 50])->dish_id,
            'available_stock' => $stock,
        ]);
        $cash = PaymentMethod::factory()->create(['method_name' => PaymentMethod::CASH_ON_PICKUP]);
        $gcash = PaymentMethod::factory()->create(['method_name' => 'GCash']);

        return [$session, $line, $cash, $gcash];
    }

    // The customer places an order through the real endpoint. Returns the order id.
    private function place(User $customer, OrderSession $session, SessionDish $line, PaymentMethod $method, int $quantity = 1): int
    {
        Storage::fake('public');

        $payload = [
            'session_id' => $session->session_id,
            'payment_method_id' => $method->method_id,
            'items' => [$line->session_dish_id => $quantity],
        ];

        if ($method->method_name !== PaymentMethod::CASH_ON_PICKUP) {
            $payload['proof_of_payment'] = UploadedFile::fake()->image('proof.jpg');
        }

        $this->actingAs($customer)->postJson(route('orders.store'), $payload)->assertOk();

        return (int) DB::table('orders')->orderByDesc('order_id')->value('order_id');
    }

    private function rowsFor(User $user, ?string $type = null)
    {
        return DB::table('user_notifications')
            ->where('user_id', $user->id)
            ->when($type, fn ($query) => $query->where('type', $type))
            ->orderBy('id')
            ->get();
    }

    /* ---- placing an order ---------------------------------------------- */

    public function test_the_customer_is_told_their_order_was_placed(): void
    {
        [$session, $line, , $gcash] = $this->menu();
        $customer = $this->customer();

        $orderId = $this->place($customer, $session, $line, $gcash, 2);

        $row = $this->rowsFor($customer, OrderNotifier::PLACED)->first();
        $number = DB::table('orders')->where('order_id', $orderId)->value('order_number');

        $this->assertNotNull($row);
        $this->assertSame("Order {$number} placed", $row->title);
        $this->assertStringContainsString('₱100.00', $row->body);
        $this->assertStringContainsString('GCash', $row->body);
        $this->assertSame('/my-orders#order-' . $orderId, $row->url);
        $this->assertSame($orderId, (int) $row->order_id);
        $this->assertNull($row->read_at);
    }

    public function test_a_cash_order_says_to_pay_at_the_counter(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();

        $this->place($customer, $session, $line, $cash);

        $this->assertStringContainsString('Pay at the counter', $this->rowsFor($customer, OrderNotifier::PLACED)->first()->body);
    }

    public function test_without_watchers_only_the_customer_is_told(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $staff = $this->superAdmin();

        $this->place($customer, $session, $line, $cash);

        $this->assertSame(1, DB::table('user_notifications')->count());
        $this->assertCount(0, $this->rowsFor($staff));
    }

    public function test_a_watcher_gets_a_copy_in_staff_words(): void
    {
        [$session, $line, , $gcash] = $this->menu();
        $customer = $this->customer();
        $watcher = $this->watcher();
        $bystander = $this->superAdmin(); // staff, but not picked

        $orderId = $this->place($customer, $session, $line, $gcash);

        $row = $this->rowsFor($watcher, OrderNotifier::PLACED)->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString($customer->name . ' placed order', $row->title);
        $this->assertSame($orderId, (int) $row->order_id);
        // A new order is looked at on the Orders page.
        $this->assertSame('/orders', $row->url);
        $this->assertCount(0, $this->rowsFor($bystander));
    }

    public function test_every_watcher_is_told_with_one_insert(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $a = $this->watcher();
        $b = $this->watcher();
        $c = $this->watcher();

        $this->actingAs($customer);
        DB::enableQueryLog();
        $this->place($customer, $session, $line, $cash);
        $inserts = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'insert into "user_notifications"'));

        // The customer and three watchers, written by ONE statement.
        $this->assertCount(1, $inserts);
        $this->assertSame(4, DB::table('user_notifications')->count());
        foreach ([$a, $b, $c] as $watcher) {
            $this->assertCount(1, $this->rowsFor($watcher, OrderNotifier::PLACED));
        }
    }

    public function test_a_customer_who_is_also_a_watcher_is_told_once_in_their_own_words(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        Setting::setNotificationWatchers([$customer->id]);

        $this->place($customer, $session, $line, $cash);

        $rows = $this->rowsFor($customer, OrderNotifier::PLACED);

        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('Order ORD-', $rows->first()->title);
    }

    public function test_a_deleted_watcher_is_not_told_and_does_not_break_anything(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $gone = $this->watcher();
        $gone->delete(); // soft delete

        $this->place($customer, $session, $line, $cash);

        $this->assertCount(0, $this->rowsFor($gone));
        $this->assertCount(1, $this->rowsFor($customer, OrderNotifier::PLACED));
    }

    public function test_a_replayed_order_does_not_notify_twice(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $payload = [
            'session_id' => $session->session_id,
            'payment_method_id' => $cash->method_id,
            'items' => [$line->session_dish_id => 1],
            'idempotency_key' => 'same-cart',
        ];

        $this->actingAs($customer)->postJson(route('orders.store'), $payload)->assertOk();
        $this->actingAs($customer)->postJson(route('orders.store'), $payload)->assertOk();

        $this->assertCount(1, $this->rowsFor($customer, OrderNotifier::PLACED));
    }

    public function test_a_notification_that_fails_never_fails_the_order(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();

        // The table is gone (migration not run yet): the order must still go through.
        Schema::drop('user_notifications');

        $this->actingAs($customer)
            ->postJson(route('orders.store'), [
                'session_id' => $session->session_id,
                'payment_method_id' => $cash->method_id,
                'items' => [$line->session_dish_id => 1],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('orders', 1);
    }

    /* ---- what staff do ------------------------------------------------- */

    public function test_verifying_and_rejecting_a_payment_tell_the_customer_and_the_watchers(): void
    {
        [$session, $line, , $gcash] = $this->menu();
        $customer = $this->customer();
        $watcher = $this->watcher();
        $first = $this->place($customer, $session, $line, $gcash);
        $second = $this->place($customer, $session, $line, $gcash);
        $staff = $this->superAdmin();

        $this->actingAs($staff)->postJson(route('payments.verify', $first))->assertOk();
        $this->actingAs($staff)->postJson(route('payments.reject', $second), ['reason' => 'Amount does not match.'])->assertOk();

        $verified = $this->rowsFor($customer, OrderNotifier::PAYMENT_VERIFIED)->first();
        $rejected = $this->rowsFor($customer, OrderNotifier::PAYMENT_REJECTED)->first();

        $this->assertSame('Payment verified', $verified->title);
        $this->assertSame($first, (int) $verified->order_id);
        $this->assertSame('Payment rejected', $rejected->title);
        // The reason, and the way out, are in the customer's notification.
        $this->assertStringContainsString('Amount does not match.', $rejected->body);
        $this->assertStringContainsString('send a new proof', $rejected->body);
        $this->assertSame('/my-orders#order-' . $second, $rejected->url);

        $this->assertStringContainsString("{$customer->name}'s payment", $this->rowsFor($watcher, OrderNotifier::PAYMENT_VERIFIED)->first()->body);
        $this->assertStringContainsString('Amount does not match.', $this->rowsFor($watcher, OrderNotifier::PAYMENT_REJECTED)->first()->body);
        // Payment events open the Payments page for a watcher who may see it.
        $this->assertSame('/payments', $this->rowsFor($watcher, OrderNotifier::PAYMENT_VERIFIED)->first()->url);
    }

    public function test_a_watcher_is_not_told_about_their_own_action(): void
    {
        [$session, $line, , $gcash] = $this->menu();
        $customer = $this->customer();
        $orderId = $this->place($customer, $session, $line, $gcash);

        // The staff member who verifies is a watcher too.
        $staff = $this->superAdmin();
        Setting::setNotificationWatchers([$staff->id]);

        $this->actingAs($staff)->postJson(route('payments.verify', $orderId))->assertOk();

        $this->assertCount(1, $this->rowsFor($customer, OrderNotifier::PAYMENT_VERIFIED));
        $this->assertCount(0, $this->rowsFor($staff, OrderNotifier::PAYMENT_VERIFIED));
    }

    public function test_completing_an_order_tells_the_customer_and_the_watchers(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $watcher = $this->watcher();
        $orderId = $this->place($customer, $session, $line, $cash);

        $this->actingAs($this->superAdmin())->postJson(route('orders.complete', $orderId))->assertOk();

        $mine = $this->rowsFor($customer, OrderNotifier::COMPLETED)->first();

        $this->assertStringEndsWith('completed', $mine->title);
        $this->assertStringContainsString('handed over', $mine->body);
        $this->assertCount(1, $this->rowsFor($watcher, OrderNotifier::COMPLETED));
    }

    public function test_cancelling_an_order_tells_the_customer_and_the_watchers(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $watcher = $this->watcher();
        $orderId = $this->place($customer, $session, $line, $cash);

        $this->actingAs($this->superAdmin())->postJson(route('orders.cancel', $orderId))->assertOk();

        $this->assertStringEndsWith('cancelled', $this->rowsFor($customer, OrderNotifier::CANCELLED)->first()->title);
        $this->assertCount(1, $this->rowsFor($watcher, OrderNotifier::CANCELLED));
    }

    public function test_a_refused_action_tells_nobody(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $orderId = $this->place($customer, $session, $line, $cash);
        $staff = $this->superAdmin();

        $this->actingAs($staff)->postJson(route('orders.cancel', $orderId))->assertOk();
        $before = DB::table('user_notifications')->count();

        // Already cancelled: both are refused with 422.
        $this->actingAs($staff)->postJson(route('orders.cancel', $orderId))->assertStatus(422);
        $this->actingAs($staff)->postJson(route('orders.complete', $orderId))->assertStatus(422);

        $this->assertSame($before, DB::table('user_notifications')->count());
    }

    /* ---- the customer fixes a rejected payment ------------------------- */

    private function rejectedOrder(User $customer, PaymentMethod $method): int
    {
        $session = OrderSession::factory()->create();
        $line = SessionDish::factory()->create(['session_id' => $session->session_id, 'available_stock' => 7, 'reserved_stock' => 3]);
        $order = Order::factory()->create(['user_id' => $customer->id, 'session_id' => $session->session_id, 'payment_status' => 'rejected']);
        OrderItem::factory()->create(['order_id' => $order->order_id, 'session_dish_id' => $line->session_dish_id, 'quantity' => 3]);
        Payment::factory()->create(['order_id' => $order->order_id, 'payment_method_id' => $method->method_id, 'payment_status' => 'rejected']);

        return (int) $order->order_id;
    }

    public function test_a_new_proof_tells_the_watchers_but_not_the_customer(): void
    {
        Storage::fake('public');
        [, , , $gcash] = $this->menu();
        $customer = $this->customer();
        $watcher = $this->watcher();
        $orderId = $this->rejectedOrder($customer, $gcash);

        $this->actingAs($customer)
            ->postJson(route('my-orders.payment.proof', $orderId), ['proof_of_payment' => UploadedFile::fake()->image('p.jpg')])
            ->assertOk();

        $row = $this->rowsFor($watcher, OrderNotifier::PAYMENT_RESENT)->first();

        $this->assertStringContainsString('sent a new proof', $row->title);
        $this->assertSame('/payments', $row->url);
        $this->assertCount(0, $this->rowsFor($customer));
    }

    public function test_switching_to_cash_tells_the_watchers(): void
    {
        [, , , $gcash] = $this->menu();
        $customer = $this->customer();
        $watcher = $this->watcher();
        $orderId = $this->rejectedOrder($customer, $gcash);

        $this->actingAs($customer)->postJson(route('my-orders.payment.cash', $orderId))->assertOk();

        $this->assertStringContainsString('will pay', $this->rowsFor($watcher, OrderNotifier::PAYMENT_RESENT)->first()->title);
        $this->assertCount(0, $this->rowsFor($customer));
    }

    /* ---- sold out ------------------------------------------------------ */

    public function test_the_last_plates_tell_the_watchers_and_only_them(): void
    {
        [$session, $line, $cash] = $this->menu(2);
        $customer = $this->customer();
        $watcher = $this->watcher('view order-sessions');

        $this->place($customer, $session, $line, $cash, 2);

        $row = $this->rowsFor($watcher, OrderNotifier::SOLD_OUT)->first();

        $this->assertSame('Adobo is sold out', $row->title);
        $this->assertSame('/order-sessions/' . $session->session_id, $row->url);
        $this->assertCount(0, $this->rowsFor($customer, OrderNotifier::SOLD_OUT));
    }

    public function test_an_order_that_leaves_plates_is_not_a_sold_out(): void
    {
        [$session, $line, $cash] = $this->menu(5);
        $watcher = $this->watcher();

        $this->place($this->customer(), $session, $line, $cash, 2);

        $this->assertCount(0, $this->rowsFor($watcher, OrderNotifier::SOLD_OUT));
    }

    public function test_a_sold_out_dish_is_announced_once_in_a_row(): void
    {
        [$session, $line] = $this->menu(0);
        $watcher = $this->watcher();
        $this->withoutDefer();

        OrderNotifier::soldOut([$line->session_dish_id], (int) $session->session_id);
        OrderNotifier::soldOut([$line->session_dish_id], (int) $session->session_id);

        $this->assertCount(1, $this->rowsFor($watcher, OrderNotifier::SOLD_OUT));
    }

    /* ---- the feed and the page ----------------------------------------- */

    private function note(User $user, array $overrides = []): int
    {
        return (int) DB::table('user_notifications')->insertGetId($overrides + [
            'user_id' => $user->id,
            'type' => 'order_placed',
            'title' => 'A title',
            'body' => 'A body',
            'url' => '/my-orders',
            'created_at' => now(),
        ]);
    }

    public function test_the_first_poll_answers_the_count_and_the_newest_id_without_a_list(): void
    {
        $user = $this->customer();
        $this->note($user);
        $newest = $this->note($user);
        $this->note($user, ['read_at' => now()]);

        $this->actingAs($user)
            ->getJson(route('notifications.feed'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('unread', 2)
            ->assertJsonPath('latest_id', $newest + 1)
            ->assertJsonPath('items', []);
    }

    public function test_the_next_poll_returns_only_what_is_new(): void
    {
        $user = $this->customer();
        $old = $this->note($user, ['title' => 'Old']);
        $new = $this->note($user, ['title' => 'New']);

        $this->actingAs($user);

        $this->getJson(route('notifications.feed', ['after' => $old]))
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $new)
            ->assertJsonPath('items.0.title', 'New')
            ->assertJsonPath('items.0.open_url', route('notifications.open', $new));

        // Nothing newer than the newest: an empty answer.
        $this->getJson(route('notifications.feed', ['after' => $new]))->assertJsonPath('items', []);
    }

    public function test_the_feed_never_shows_someone_elses_notifications(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $this->note($other);

        $this->actingAs($mine)
            ->getJson(route('notifications.feed', ['after' => 0]))
            ->assertJsonPath('unread', 0)
            ->assertJsonPath('latest_id', 0)
            ->assertJsonPath('items', []);
    }

    public function test_the_page_lists_my_notifications_newest_first_and_counts_the_unread(): void
    {
        $user = $this->customer();
        $this->note($user, ['title' => 'First one']);
        $this->note($user, ['title' => 'Second one', 'read_at' => now()]);
        $this->note($this->customer(), ['title' => 'Not mine']);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSeeInOrder(['Second one', 'First one'])
            ->assertDontSee('Not mine')
            ->assertSee('1 unread')
            ->assertSee('Mark all as read');
    }

    public function test_an_empty_page_says_so(): void
    {
        $this->actingAs($this->customer())
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('No notifications yet')
            // The page's own "Mark all as read" form is not drawn (the bell's panel has a
            // hidden button of its own, which is not a form).
            ->assertDontSee('action="' . route('notifications.read-all') . '"', false);
    }

    public function test_the_page_is_numbered_with_a_total_and_previous_next(): void
    {
        $user = $this->customer();

        for ($i = 1; $i <= 45; $i++) {
            $this->note($user, ['title' => "Note {$i}"]);
        }

        // 45 rows, 20 a page: 3 pages, newest first.
        $this->actingAs($user)->get(route('notifications.index'))
            ->assertSee('Note 45')->assertDontSee('Note 25<', false)
            ->assertSee('Showing 1–20 of 45')
            ->assertSee('aria-current="page"', false)
            ->assertSee('?page=2', false)->assertSee('?page=3', false)
            ->assertSee('aria-disabled="true"', false)   // Previous is off on page 1
            ->assertSee('rel="next"', false)->assertDontSee('rel="prev"', false);

        $this->get(route('notifications.index', ['page' => 2]))
            ->assertSee('Note 25')->assertDontSee('Note 45<', false)->assertDontSee('Note 5<', false)
            ->assertSee('Showing 21–40 of 45')
            ->assertSee('rel="prev"', false)->assertSee('rel="next"', false);

        $this->get(route('notifications.index', ['page' => 3]))
            ->assertSee('Note 5<', false)->assertDontSee('Note 25<', false)
            ->assertSee('Showing 41–45 of 45')
            ->assertSee('rel="prev"', false)->assertDontSee('rel="next"', false);
    }

    public function test_the_total_counts_only_my_notifications(): void
    {
        $me = $this->customer();
        $other = $this->customer();

        for ($i = 1; $i <= 21; $i++) {
            $this->note($me);
        }

        for ($i = 1; $i <= 30; $i++) {
            $this->note($other);
        }

        $this->actingAs($me)->get(route('notifications.index'))->assertSee('of 21');
    }

    public function test_one_page_of_notifications_shows_no_page_controls(): void
    {
        $user = $this->customer();
        $this->note($user, ['title' => 'Only one']);

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertSee('Only one')
            ->assertDontSee('Showing ')
            ->assertDontSee('rel="next"', false);
    }

    public function test_a_page_past_the_end_goes_to_the_last_page(): void
    {
        $user = $this->customer();

        for ($i = 1; $i <= 25; $i++) {
            $this->note($user);
        }

        $this->actingAs($user)->get(route('notifications.index', ['page' => 99]))
            ->assertRedirect(route('notifications.index', ['page' => 2]));

        // An empty inbox asked for page 5 lands on the (empty) first page.
        $this->actingAs($this->customer())->followingRedirects()->get(route('notifications.index', ['page' => 5]))
            ->assertOk()->assertSee('No notifications yet');
    }

    public function test_opening_one_marks_it_read_and_goes_to_its_page(): void
    {
        $user = $this->customer();
        $id = $this->note($user, ['url' => '/my-orders#order-9']);

        $this->actingAs($user)
            ->get(route('notifications.open', $id))
            ->assertRedirect('/my-orders#order-9');

        $this->assertNotNull(DB::table('user_notifications')->where('id', $id)->value('read_at'));
    }

    public function test_opening_one_again_keeps_the_first_read_time(): void
    {
        $user = $this->customer();
        $id = $this->note($user, ['read_at' => '2026-01-01 10:00:00']);

        $this->actingAs($user)->get(route('notifications.open', $id));

        $this->assertSame('2026-01-01 10:00:00', DB::table('user_notifications')->where('id', $id)->value('read_at'));
    }

    public function test_a_notification_without_a_link_returns_to_the_list(): void
    {
        $user = $this->customer();
        $id = $this->note($user, ['url' => null]);

        $this->actingAs($user)->get(route('notifications.open', $id))->assertRedirect(route('notifications.index'));
    }

    public function test_only_a_local_link_is_ever_followed(): void
    {
        $user = $this->customer();
        $this->actingAs($user);

        foreach (['https://evil.test/phish', '//evil.test', 'javascript:alert(1)'] as $url) {
            $id = $this->note($user, ['url' => $url]);

            $this->get(route('notifications.open', $id))->assertRedirect(route('notifications.index'));
        }
    }

    public function test_nobody_can_open_someone_elses_notification(): void
    {
        $owner = $this->customer();
        $id = $this->note($owner);

        $this->actingAs($this->customer())->get(route('notifications.open', $id))->assertNotFound();

        $this->assertNull(DB::table('user_notifications')->where('id', $id)->value('read_at'));
    }

    public function test_mark_all_as_read_touches_only_my_own(): void
    {
        $mine = $this->customer();
        $other = $this->customer();
        $this->note($mine);
        $this->note($mine);
        $theirs = $this->note($other);

        $this->actingAs($mine)->post(route('notifications.read-all'))->assertRedirect(route('notifications.index'));

        $this->assertSame(0, UserNotification::unreadCount($mine->id));
        $this->assertNull(DB::table('user_notifications')->where('id', $theirs)->value('read_at'));
    }

    public function test_mark_all_as_read_answers_json_for_ajax(): void
    {
        $user = $this->customer();
        $this->note($user);

        $this->actingAs($user)->postJson(route('notifications.read-all'))->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_guests_cannot_reach_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->getJson(route('notifications.feed'))->assertUnauthorized();
        $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
    }

    public function test_every_page_carries_the_bell_for_the_script(): void
    {
        $this->actingAs($this->customer())
            ->get(route('menu.index'))
            ->assertOk()
            ->assertSee('data-notif-badge', false)
            ->assertSee(route('notifications.feed'), false);
    }

    public function test_the_bell_panel_asks_for_the_latest_few_newest_first_with_their_read_state(): void
    {
        $user = $this->customer();
        $read = $this->note($user, ['title' => 'Already seen', 'read_at' => now()]);

        // Seven more: the panel lists six.
        for ($i = 1; $i <= 7; $i++) {
            $this->note($user, ['title' => 'Note ' . $i]);
        }

        $this->note($this->customer(), ['title' => 'Someone else']);

        $response = $this->actingAs($user)
            ->getJson(route('notifications.feed', ['recent' => 1]))
            ->assertOk()
            ->assertJsonCount(6, 'recent')
            ->assertJsonPath('items', [])
            ->assertJsonPath('recent.0.title', 'Note 7')
            ->assertJsonPath('recent.0.unread', true)
            ->assertJsonStructure(['recent' => [['id', 'title', 'body', 'open_url', 'unread', 'time']]]);

        $this->assertNotContains('Someone else', array_column($response->json('recent'), 'title'));

        // The seen one is the oldest, so it is outside the six.
        $this->assertNotContains($read, array_column($response->json('recent'), 'id'));

        // A person with only a read one sees it listed as read.
        $other = $this->customer();
        $this->note($other, ['title' => 'Old news', 'read_at' => now()]);

        $this->actingAs($other)
            ->getJson(route('notifications.feed', ['recent' => 1]))
            ->assertJsonCount(1, 'recent')
            ->assertJsonPath('recent.0.unread', false);
    }

    public function test_the_poll_does_not_read_the_list_unless_the_panel_asks(): void
    {
        $user = $this->customer();
        $this->note($user);

        $this->actingAs($user)
            ->getJson(route('notifications.feed', ['after' => 0]))
            ->assertJsonMissingPath('recent');
    }

    public function test_the_top_right_corner_has_the_bell_panel_and_the_account_menu_and_the_sidebar_does_not(): void
    {
        $user = $this->customer();

        $html = $this->actingAs($user)->get(route('menu.index'))->assertOk()->getContent();

        // One corner per bar (phone + desktop): a bell with its panel, Profile and Log Out.
        $this->assertSame(2, substr_count($html, 'data-notif-panel'));
        $this->assertSame(2, substr_count($html, 'data-notif-bell'));
        $this->assertSame(1, substr_count($html, 'id="desktop-account"'));
        $this->assertSame(1, substr_count($html, 'id="phone-account"'));
        $this->assertSame(2, substr_count($html, 'action="' . route('logout') . '"'));
        $this->assertSame(2, substr_count($html, 'href="' . route('profile.edit') . '"'));
        $this->assertStringContainsString(route('notifications.read-all'), $html);

        // The sidebar's own bottom links are gone.
        $this->assertStringNotContainsString('title="Profile"', $html);
        $this->assertStringNotContainsString('title="Log Out"', $html);
    }

    public function test_a_card_on_my_orders_can_be_scrolled_to(): void
    {
        $customer = $this->customer();
        $order = Order::factory()->create(['user_id' => $customer->id]);

        $this->actingAs($customer)
            ->get(route('my-orders.index'))
            ->assertSee('id="order-' . $order->order_id . '"', false);
    }

    /* ---- the watchers in System Configuration ------------------------- */

    private function settingsPayload(array $extra = []): array
    {
        return ['session_start_time' => '09:00', 'session_end_time' => '17:00'] + $extra;
    }

    public function test_the_configuration_page_offers_the_users_and_marks_the_watchers(): void
    {
        $watcher = $this->userWith();
        $other = $this->userWith();
        Setting::setNotificationWatchers([$watcher->id]);

        $this->actingAs($this->superAdmin())
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('id="notification-watchers"', false)
            ->assertSee('value="' . $watcher->id . '" selected', false)
            ->assertDontSee('value="' . $other->id . '" selected', false);
    }

    public function test_the_watchers_are_saved_and_can_be_changed_or_emptied(): void
    {
        $a = $this->userWith();
        $b = $this->userWith();
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        $this->putJson(route('settings.update'), $this->settingsPayload(['notification_watchers' => ['', (string) $a->id, (string) $b->id]]))->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Setting::notificationWatchers());

        $this->putJson(route('settings.update'), $this->settingsPayload(['notification_watchers' => [(string) $b->id]]))->assertOk();
        $this->assertSame([$b->id], Setting::notificationWatchers());

        // Nobody picked: the form still sends its empty entry.
        $this->putJson(route('settings.update'), $this->settingsPayload(['notification_watchers' => ['']]))->assertOk();
        $this->assertSame([], Setting::notificationWatchers());
    }

    public function test_a_caller_that_sends_no_watchers_changes_nothing(): void
    {
        $a = $this->userWith();
        Setting::setNotificationWatchers([$a->id]);

        $this->actingAs($this->superAdmin())->putJson(route('settings.update'), $this->settingsPayload())->assertOk();

        $this->assertSame([$a->id], Setting::notificationWatchers());
    }

    public function test_an_unknown_or_deleted_user_cannot_be_picked(): void
    {
        $gone = $this->userWith();
        $gone->delete();
        $this->actingAs($this->superAdmin());

        $this->putJson(route('settings.update'), $this->settingsPayload(['notification_watchers' => [999999]]))
            ->assertStatus(422)->assertJsonValidationErrors('notification_watchers.0');
        $this->putJson(route('settings.update'), $this->settingsPayload(['notification_watchers' => [$gone->id]]))
            ->assertStatus(422);

        $this->assertSame([], Setting::notificationWatchers());
    }

    public function test_only_editors_can_change_the_watchers(): void
    {
        $viewer = $this->userWith('view settings');
        $target = $this->userWith();

        $this->actingAs($viewer)
            ->putJson(route('settings.update'), $this->settingsPayload(['notification_watchers' => [$target->id]]))
            ->assertForbidden();

        $this->assertSame([], Setting::notificationWatchers());
    }

    public function test_a_damaged_watcher_value_reads_as_nobody(): void
    {
        Setting::set(Setting::NOTIFICATION_WATCHERS, '{not json');
        $this->assertSame([], Setting::notificationWatchers());

        Setting::set(Setting::NOTIFICATION_WATCHERS, '"a string"');
        $this->assertSame([], Setting::notificationWatchers());
    }

    public function test_a_watcher_added_in_the_configuration_is_told_from_then_on(): void
    {
        [$session, $line, $cash] = $this->menu();
        $customer = $this->customer();
        $admin = $this->superAdmin();
        $newWatcher = $this->userWith('view orders');

        $this->place($customer, $session, $line, $cash);
        $this->assertCount(0, $this->rowsFor($newWatcher));

        $this->actingAs($admin)->putJson(route('settings.update'), $this->settingsPayload(['notification_watchers' => [$newWatcher->id]]))->assertOk();

        $this->place($customer, $session, $line, $cash);
        $this->assertCount(1, $this->rowsFor($newWatcher, OrderNotifier::PLACED));
    }

    /* ---- housekeeping -------------------------------------------------- */

    public function test_the_prune_command_deletes_old_read_ones_and_very_old_ones(): void
    {
        $user = $this->customer();
        $freshUnread = $this->note($user);
        $freshRead = $this->note($user, ['read_at' => now()]);
        $oldRead = $this->note($user, ['read_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);
        $oldUnread = $this->note($user, ['created_at' => now()->subDays(40)]);
        $ancientUnread = $this->note($user, ['created_at' => now()->subDays(100)]);

        $this->artisan('notifications:prune')->assertSuccessful();

        $left = DB::table('user_notifications')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$freshUnread, $freshRead, $oldUnread], $left);
        $this->assertNotContains($oldRead, $left);
        $this->assertNotContains($ancientUnread, $left);
    }

    public function test_the_prune_command_is_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command, 'notifications:prune'));

        $this->assertCount(1, $events);
    }

    public function test_the_polling_indexes_exist(): void
    {
        // The unread count, the list / poll and the prune each have their own index.
        $indexes = collect(Schema::getIndexes('user_notifications'))->pluck('columns', 'name');

        $this->assertSame(['user_id', 'read_at', 'id'], $indexes['user_notifications_unread_index']);
        $this->assertSame(['user_id', 'id'], $indexes['user_notifications_feed_index']);
        $this->assertSame(['created_at'], $indexes['user_notifications_created_index']);
    }
}
