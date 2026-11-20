<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The Google Chat copy of notifications (System Configuration -> Notifications -> "Send
// notifications to Google Chat"): a direct message from the Chat app to everyone told, off by
// default, never able to cost an order or its bell notification. Google is faked; the test
// queue is `sync`, so the jobs run inside the request.
class GoogleChatNotificationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $keyFile = null;

    protected function tearDown(): void
    {
        if ($this->keyFile && is_file($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    // A real (throwaway) service account key on disk, so the app can sign its token request.
    private function configureKey(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $resource = @openssl_pkey_new($options);

        // PHP on Windows has no default openssl.cnf: use the one that ships next to php.exe.
        if (! $resource && is_file($config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) {
            $options['config'] = $config;
            $resource = openssl_pkey_new($options);
        }

        if (! $resource) {
            $this->markTestSkipped('PHP cannot generate an RSA key here (no OpenSSL configuration).');
        }

        openssl_pkey_export($resource, $private, null, array_intersect_key($options, ['config' => 1]));

        $this->keyFile = tempnam(sys_get_temp_dir(), 'chatkey');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'chat@example.iam.gserviceaccount.com', 'private_key' => $private]));

        config(['services.google_chat.credentials' => $this->keyFile]);
    }

    // Welcomed already by default: most tests are about the notification itself, and the first
    // message anyone gets is the Welcome (see the tests at the end of this section).
    private function googleCustomer(string $googleId = '111', bool $welcomed = true): User
    {
        $customer = $this->userWith('view menu', 'create orders', 'view my orders');
        DB::table('users')->where('id', $customer->id)->update(['google_id' => $googleId, 'google_chat_welcomed_at' => $welcomed ? now() : null]);

        return $customer;
    }

    private function fakeGoogle(int $findStatus = 200, int $postStatus = 200, int $tokenStatus = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], $tokenStatus),
            'chat.googleapis.com/v1/spaces:findDirectMessage*' => Http::response(['name' => 'spaces/AAA'], $findStatus),
            'chat.googleapis.com/v1/spaces/AAA/messages' => Http::response(['name' => 'spaces/AAA/messages/1'], $postStatus),
        ]);
    }

    private function place(User $customer): void
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
    }

    private function messagesPosted(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))
            ->map(fn (array $pair) => $pair[0])->values()->all();
    }

    public function test_it_is_off_by_default_and_nothing_is_sent(): void
    {
        $this->configureKey();
        $this->fakeGoogle();

        $this->assertFalse(Setting::googleChatNotifications());

        $this->place($this->googleCustomer());

        Http::assertNothingSent();
    }

    public function test_when_on_the_customer_gets_a_direct_message_with_the_bell_words(): void
    {
        $this->configureKey();
        $this->fakeGoogle();
        Setting::setGoogleChatNotifications(true);
        $customer = $this->googleCustomer('777');

        $this->place($customer);

        $posted = $this->messagesPosted();

        $this->assertCount(1, $posted);

        // A card: the bell's title in the header, the dishes, the total and the payment method.
        $card = $posted[0]['cardsV2'][0]['card'];
        $everything = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('placed', $card['header']['title']);
        $this->assertStringContainsString('🧾', $card['header']['title']);
        $this->assertStringContainsString('1×', $everything);
        $this->assertStringContainsString('Adobo', $everything);
        $this->assertStringContainsString('₱50.00', $everything);
        $this->assertStringContainsString('Cash on Pick up', $everything);

        // The person was found by their Google id, with the app's own token.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'findDirectMessage') && str_contains(urldecode($request->url()), 'users/777') && $request->hasHeader('Authorization', 'Bearer tok'));

        // The bell row is there as always.
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $customer->id)->count());
    }

    public function test_the_card_lists_the_dishes_escapes_text_and_only_links_https(): void
    {
        $entry = [
            'title' => 'Order X is ready for pick up',
            'body' => 'Show your number.',
            'url' => '/my-orders?order=5',
            'type' => 'order_ready',
            'app' => 'Cucina',
            'order' => ['number' => 'ORD-1', 'total' => 185.5, 'method' => 'GCash', 'items' => [
                ['dish_name' => 'Adobo <b>&</b>', 'quantity' => 2, 'sub_total' => 130],
                ['dish_name' => 'Rice', 'quantity' => 1, 'sub_total' => 55.5],
            ]],
        ];

        $card = GoogleChat::card($entry)['card'];
        $json = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame('🍽️ Order X is ready for pick up', $card['header']['title']);
        $this->assertSame('Cucina', $card['header']['subtitle']);
        $this->assertStringContainsString('<b>2×</b> Adobo &lt;b&gt;&amp;&lt;/b&gt; — ₱130.00<br><b>1×</b> Rice — ₱55.50', $json);
        $this->assertStringContainsString('<b>₱185.50</b>', $json);
        $this->assertStringContainsString('Order ORD-1', $json);
        $this->assertStringNotContainsString('buttonList', $json, 'a local http link has no button');

        // https gets the button.
        config(['app.url' => 'https://cucina.example']);
        url()->forceRootUrl('https://cucina.example');
        url()->forceScheme('https');
        $this->assertStringContainsString('"openLink":{"url":"https://cucina.example/my-orders?order=5"}', json_encode(GoogleChat::card($entry)['card'], JSON_UNESCAPED_SLASHES));

        // A long order is cut and counted; a message with nothing but a title still has a section.
        $many = ['title' => 'T', 'order' => ['number' => 'O', 'total' => 1, 'method' => null, 'items' => array_fill(0, 15, ['dish_name' => 'D', 'quantity' => 1, 'sub_total' => 1])]];
        $this->assertStringContainsString('+3 more', json_encode(GoogleChat::card($many)['card']));
        $this->assertNotEmpty(GoogleChat::card(['title' => 'Only a title'])['card']['sections']);
    }

    public function test_a_card_google_refuses_is_sent_again_as_plain_text(): void
    {
        $this->configureKey();
        Setting::setGoogleChatNotifications(true);
        $customer = $this->googleCustomer();

        $attempts = [];
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'chat.googleapis.com/v1/spaces:findDirectMessage*' => Http::response(['name' => 'spaces/AAA']),
            'chat.googleapis.com/v1/spaces/AAA/messages' => function (Request $request) use (&$attempts) {
                $attempts[] = $request->data();

                return isset($request->data()['cardsV2'])
                    ? Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'bad card']], 400)
                    : Http::response(['name' => 'spaces/AAA/messages/1']);
            },
        ]);

        $this->place($customer);

        $this->assertCount(2, $attempts);
        $this->assertArrayHasKey('cardsV2', $attempts[0]);
        $this->assertStringContainsString('placed', $attempts[1]['text']);
    }

    /* -------- the Welcome comes first -------- */

    public function test_a_person_never_welcomed_gets_the_welcome_before_their_first_notification(): void
    {
        $this->configureKey();
        $this->fakeGoogle();
        Setting::setGoogleChatNotifications(true);
        $customer = $this->googleCustomer('321', welcomed: false);

        $this->place($customer);

        $titles = array_map(fn (array $m) => $m['cardsV2'][0]['card']['header']['title'], $this->messagesPosted() ? array_map(fn ($r) => $r->data(), $this->messagesPosted()) : []);

        $this->assertCount(2, $titles);
        $this->assertStringContainsString('Welcome', $titles[0]);
        $this->assertStringContainsString('placed', $titles[1]);
        $this->assertNotNull(DB::table('users')->where('id', $customer->id)->value('google_chat_welcomed_at'));

        // The next event is only the notification.
        $this->actingAs($this->superAdmin())->postJson(route('orders.cancel', DB::table('orders')->value('order_id')))->assertOk();
        $this->assertCount(3, $this->messagesPosted());
    }

    public function test_the_welcome_goes_once_even_when_one_job_carries_several_messages(): void
    {
        $this->configureKey();
        $this->fakeGoogle();
        $customer = $this->googleCustomer('321', welcomed: false);

        (new \App\Jobs\SendGoogleChatMessages([
            ['user_id' => $customer->id, 'title' => 'Order X placed', 'body' => 'a', 'url' => null],
            ['user_id' => $customer->id, 'title' => 'Order Y placed', 'body' => 'b', 'url' => null],
        ]))->handle();

        $this->assertCount(3, $this->messagesPosted());
    }

    public function test_the_command_sends_the_welcome_first_and_a_plain_check_after_that(): void
    {
        $this->configureKey();
        $this->fakeGoogle();
        $user = $this->googleCustomer('555', welcomed: false);

        $this->artisan('google-chat:test', ['email' => $user->email])->expectsOutputToContain('the Welcome message')->assertSuccessful();
        $this->artisan('google-chat:test', ['email' => $user->email])->expectsOutputToContain('a connection check')->assertSuccessful();

        $titles = array_map(fn ($r) => $r->data()['cardsV2'][0]['card']['header']['title'], $this->messagesPosted());

        $this->assertCount(2, $titles);
        $this->assertStringContainsString('Welcome', $titles[0]);
        $this->assertStringContainsString('Google Chat is connected', $titles[1]);
        // Never a made-up order.
        $this->assertStringNotContainsString('ORD-TEST', json_encode($this->messagesPosted() ? array_map(fn ($r) => $r->data(), $this->messagesPosted()) : []));
    }

    public function test_the_card_can_list_points_and_name_its_own_button(): void
    {
        $this->assertSame('👋 Welcome', GoogleChat::card(['title' => 'Welcome', 'type' => 'welcome'])['card']['header']['title']);

        url()->forceRootUrl('https://cucina.example');
        url()->forceScheme('https');

        $card = GoogleChat::card(['title' => 'T', 'points' => ['🧾 One', '✅ Two <b>'], 'url' => '/my-orders', 'button' => 'Open my orders']);
        $json = json_encode($card['card'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('🧾 One<br>✅ Two &lt;b&gt;', $json);
        $this->assertStringContainsString('"text":"Open my orders"', $json);
    }
    public function test_the_token_request_is_a_signed_jwt_for_the_chat_scope(): void
    {
        $this->configureKey();
        $this->fakeGoogle();
        Setting::setGoogleChatNotifications(true);

        $this->place($this->googleCustomer());

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
                return false;
            }

            $data = $request->data();
            [, $claims, $signature] = explode('.', $data['assertion']);
            $decoded = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

            return $data['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $decoded['iss'] === 'chat@example.iam.gserviceaccount.com'
                && $decoded['scope'] === 'https://www.googleapis.com/auth/chat.bot'
                && $signature !== '';
        });
    }

    public function test_people_without_a_google_account_are_skipped_and_still_get_the_bell(): void
    {
        $this->configureKey();
        $this->fakeGoogle();
        Setting::setGoogleChatNotifications(true);
        $customer = $this->userWith('view menu', 'create orders', 'view my orders');

        $this->place($customer);

        $this->assertSame([], $this->messagesPosted());
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $customer->id)->count());
    }

    public function test_nothing_is_sent_while_the_key_is_not_set_up(): void
    {
        config(['services.google_chat.credentials' => null]);
        $this->fakeGoogle();
        Setting::setGoogleChatNotifications(true);

        $this->assertFalse(GoogleChat::configured());

        $this->place($this->googleCustomer());

        Http::assertNothingSent();

        config(['services.google_chat.credentials' => 'storage/app/does-not-exist.json']);
        $this->assertFalse(GoogleChat::configured());
    }

    public function test_someone_who_has_not_added_the_app_is_skipped_and_the_miss_is_remembered(): void
    {
        $this->configureKey();
        $this->fakeGoogle(findStatus: 404);
        Setting::setGoogleChatNotifications(true);
        $customer = $this->googleCustomer();

        $this->place($customer);

        $this->assertSame([], $this->messagesPosted());
        $this->assertSame(1, DB::table('user_notifications')->where('user_id', $customer->id)->count());
        $this->assertTrue(Cache::has('google-chat:space:111:missing'));

        // A second event does not ask Google about the same person again.
        $lookups = fn () => Http::recorded(fn (Request $request) => str_contains($request->url(), 'findDirectMessage'))->count();
        $before = $lookups();

        $this->actingAs($this->superAdmin())->postJson(route('orders.cancel', DB::table('orders')->value('order_id')))->assertOk();

        $this->assertSame($before, $lookups());
    }

    public function test_google_failing_never_costs_the_order_or_its_bell_notification(): void
    {
        $this->configureKey();
        Setting::setGoogleChatNotifications(true);
        $customer = $this->googleCustomer();

        // No token.
        $this->fakeGoogle(tokenStatus: 500);
        $this->place($customer);
        $this->assertSame([], $this->messagesPosted());

        // The message itself is refused: the remembered space is dropped so it is looked up again.
        Cache::forget('google-chat:token');
        $this->fakeGoogle(postStatus: 403);
        $this->actingAs($this->superAdmin())->postJson(route('orders.cancel', DB::table('orders')->value('order_id')))->assertOk();
        $this->assertFalse(Cache::has('google-chat:space:111'));

        // An outage (the connection fails).
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        Cache::flush();
        $this->place($customer);

        $this->assertSame(3, DB::table('user_notifications')->where('user_id', $customer->id)->count());
        $this->assertSame(2, DB::table('orders')->count());
    }

    public function test_the_message_text_is_the_title_the_words_and_a_link(): void
    {
        $link = url('/my-orders?order=5#order-5');

        // Markdown (the default, like a new Chat app).
        $this->assertSame("**Order X is ready**\nShow your order number.\n[" . __('Open') . "]($link)", GoogleChat::format('Order X is ready', 'Show your order number.', '/my-orders?order=5#order-5'));
        $this->assertSame('**Plain**', GoogleChat::format('Plain', null, null));

        // Google Chat's own syntax, for an app set to the legacy markup.
        config(['services.google_chat.markup' => 'legacy']);
        $this->assertSame("*Order X is ready*\nShow your order number.\n<$link|" . __('Open') . '>', GoogleChat::format('Order X is ready', 'Show your order number.', '/my-orders?order=5#order-5'));
    }

    /* -------- php artisan google-chat:test -------- */

    public function test_the_command_names_the_step_that_fails(): void
    {
        $this->artisan('google-chat:test', ['email' => 'nobody@example.com'])->expectsOutputToContain('No such user.')->assertFailed();

        $plain = $this->userWith();
        $this->artisan('google-chat:test', ['email' => $plain->email])->expectsOutputToContain('no google_id')->assertFailed();

        $user = $this->googleCustomer('555');

        // No key file.
        config(['services.google_chat.credentials' => null]);
        $this->artisan('google-chat:test', ['email' => $user->email])->expectsOutputToContain('[FAIL] Key file')->assertFailed();

        // The person has not added the app: Google answers 404, and the command says what that means.
        $this->configureKey();
        $tokenRefused = false;
        Http::fake([
            // By reference: an arrow function would copy the flag when it is created.
            'oauth2.googleapis.com/*' => function () use (&$tokenRefused) {
                return $tokenRefused
                    ? Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)
                    : Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            },
            'chat.googleapis.com/v1/spaces:findDirectMessage*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'Not found']], 404),
        ]);
        $this->artisan('google-chat:test', ['email' => $user->email])
            ->expectsOutputToContain('[FAIL] Direct message space: HTTP 404 NOT_FOUND Not found')
            ->assertFailed();

        // The token is refused: Google's reason is shown.
        $tokenRefused = true;
        $this->artisan('google-chat:test', ['email' => $user->email])->expectsOutputToContain('[FAIL] Access token')->assertFailed();
    }

    public function test_the_command_sends_one_test_message_when_everything_works_and_not_with_no_send(): void
    {
        $this->configureKey();
        $this->fakeGoogle();
        $user = $this->googleCustomer('555');

        $this->artisan('google-chat:test', ['email' => $user->email, '--no-send' => true])->expectsOutputToContain('[ok]   Direct message space: spaces/AAA')->assertSuccessful();
        $this->assertSame([], $this->messagesPosted());

        $this->artisan('google-chat:test', ['email' => $user->email])->expectsOutputToContain('[ok]   Test message: sent')->assertSuccessful();
        $this->assertCount(1, $this->messagesPosted());
    }

    /* -------- System Configuration -------- */

    private function payload(array $extra = []): array
    {
        return $extra + ['session_start_time' => '09:00', 'session_end_time' => '17:00'];
    }

    public function test_the_switch_needs_edit_settings_is_validated_and_left_alone_when_absent(): void
    {
        $this->actingAs($this->userWith('view settings'))
            ->putJson(route('settings.update'), $this->payload(['google_chat_notifications' => 1]))->assertForbidden();
        $this->assertFalse(Setting::googleChatNotifications());

        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['google_chat_notifications' => 'maybe']))
            ->assertStatus(422)->assertJsonValidationErrors('google_chat_notifications');

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['google_chat_notifications' => '1']))->assertOk();
        $this->assertTrue(Setting::googleChatNotifications());

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload())->assertOk();
        $this->assertTrue(Setting::googleChatNotifications());

        $this->actingAs($admin)->putJson(route('settings.update'), $this->payload(['google_chat_notifications' => '0']))->assertOk();
        $this->assertFalse(Setting::googleChatNotifications());
    }

    public function test_the_page_shows_the_switch_and_warns_when_the_key_is_missing(): void
    {
        $viewer = $this->userWith('view settings');

        config(['services.google_chat.credentials' => null]);
        $this->actingAs($viewer)->get(route('settings.index'))
            ->assertOk()
            ->assertSee('name="google_chat_notifications"', false)
            ->assertDontSee('GOOGLE_CHAT_CREDENTIALS');

        Setting::setGoogleChatNotifications(true);

        $this->actingAs($viewer)->get(route('settings.index'))->assertOk()->assertSee('GOOGLE_CHAT_CREDENTIALS');

        $this->configureKey();

        $this->actingAs($viewer)->get(route('settings.index'))->assertOk()->assertDontSee('GOOGLE_CHAT_CREDENTIALS');
    }
}
