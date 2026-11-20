<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// The Google Chat card of the Profile page: shown only while the feature is on and set up,
// tells a connected person how to add the Chat app, and "Send me a test message" says in plain
// words what is missing (never the server's setup). Google is faked.
class ProfileGoogleChatTest extends TestCase
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

    private function configureKey(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $resource = @openssl_pkey_new($options);

        if (! $resource && is_file($config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) {
            $options['config'] = $config;
            $resource = openssl_pkey_new($options);
        }

        if (! $resource) {
            $this->markTestSkipped('PHP cannot generate an RSA key here (no OpenSSL configuration).');
        }

        openssl_pkey_export($resource, $private, null, array_intersect_key($options, ['config' => 1]));

        $this->keyFile = tempnam(sys_get_temp_dir(), 'chatkey');
        file_put_contents($this->keyFile, json_encode(['client_email' => 'chat@secret-project.iam.gserviceaccount.com', 'private_key' => $private]));

        config(['services.google_chat.credentials' => $this->keyFile]);
    }

    private function person(bool $google = true): User
    {
        $user = $this->userWith('view my orders');

        if ($google) {
            // Unique per person (users.google_id is unique); not a fixed value.
            DB::table('users')->where('id', $user->id)->update(['google_id' => '90' . $user->id]);
        }

        return $user->fresh();
    }

    private function switchOn(): void
    {
        $this->configureKey();
        Setting::setGoogleChatNotifications(true);
        config(['services.google_chat.app_name' => 'Cucina-Feliza', 'services.google_chat.app_url' => null]);
    }

    private function fakeGoogle(int $findStatus = 200, int $tokenStatus = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], $tokenStatus),
            'chat.googleapis.com/v1/spaces:findDirectMessage*' => Http::response(['name' => 'spaces/AAA', 'error' => ['status' => 'NOT_FOUND', 'message' => 'no dm']], $findStatus),
            'chat.googleapis.com/v1/spaces/AAA/messages' => Http::response(['name' => 'spaces/AAA/messages/1']),
        ]);
    }

    public function test_the_card_is_hidden_until_the_feature_is_on_and_set_up(): void
    {
        $user = $this->person();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertDontSee('Send me a test message');

        // On, but no key file.
        Setting::setGoogleChatNotifications(true);
        config(['services.google_chat.credentials' => null]);
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertDontSee('Send me a test message');

        $this->switchOn();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertDontSee('Send me a test message')
            ->assertSee('Search for Cucina-Feliza')
            ->assertSee('https://chat.google.com', false)
            // No listing address: the button just opens Google Chat.
            ->assertSee('Open Google Chat')
            ->assertDontSee('Install Cucina-Feliza')
            // No listing, no guide.
            ->assertDontSee('google-chat-guide', false)
            ->assertDontSee('Skip tutorial');
    }

    public function test_the_link_can_point_at_the_app_listing(): void
    {
        $this->switchOn();
        config(['services.google_chat.app_url' => 'https://workspace.google.com/marketplace/app/cucina/1']);

        $this->actingAs($this->person())->get(route('profile.edit'))->assertOk()
            ->assertSee('https://workspace.google.com/marketplace/app/cucina/1', false)
            // The link is the app's own page: the button and the first step say Install.
            ->assertSee('Install Cucina-Feliza')
            ->assertSee('Click Install Cucina-Feliza')
            ->assertDontSee('say hi')
            // No plain link to Google Chat any more (the guide's own step is titled "Open Google Chat and say hi").
            ->assertDontSee('target="_blank" rel="noopener noreferrer"', false)
            // ...and clicking it opens the how-to guide first; the guide's last step carries the address.
            ->assertSee('google-chat-guide', false)
            ->assertSee('data-url="https://workspace.google.com/marketplace/app/cucina/1"', false)
            ->assertSee('Skip tutorial')
            ->assertSee('Go to the installation page');
    }

    public function test_someone_who_never_signed_in_with_google_is_told_how_instead_of_a_button(): void
    {
        $this->switchOn();

        $this->actingAs($this->person(false))->get(route('profile.edit'))->assertOk()
            ->assertSee('Continue with Google')
            ->assertDontSee('Send me a test message');
    }

    /* -------- the "synced" indicator -------- */

    public function test_the_page_carries_the_indicator_and_asks_for_the_status_after_it_loads(): void
    {
        $this->switchOn();

        // Nothing is asked of Google while the page renders.
        Http::fake();
        $this->actingAs($this->person())->get(route('profile.edit'))->assertOk()
            ->assertSee(route('profile.google-chat.status'), false)
            ->assertSee('Synced with Google Chat')
            ->assertSee('Not synced yet')
            ->assertSee('Your notifications are sent to your chat with Cucina-Feliza.');
        Http::assertNothingSent();

        // Never signed in with Google: "not synced" straight away, no script, no status call.
        $this->actingAs($this->person(false))->get(route('profile.edit'))->assertOk()
            ->assertSee('Not synced yet')
            ->assertDontSee(route('profile.google-chat.status'), false);
    }

    public function test_the_status_says_connected_when_the_chat_with_the_app_exists(): void
    {
        $this->switchOn();
        $this->fakeGoogle();

        $this->actingAs($this->person())->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('state', 'connected');

        // The lookup, and the Welcome (the first time they are found synced): nothing else is sent.
        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'));

        $this->assertCount(1, $sent);
        $this->assertStringContainsString('Welcome', $sent->first()[0]->data()['cardsV2'][0]['card']['header']['title']);
    }

    public function test_the_status_says_not_added_when_google_has_no_chat_for_them(): void
    {
        $this->switchOn();
        $this->fakeGoogle(findStatus: 404);

        $this->actingAs($this->person())->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertJsonPath('state', 'not_added');
    }

    public function test_the_status_is_a_live_lookup_not_the_remembered_chat(): void
    {
        $this->switchOn();
        $this->fakeGoogle(findStatus: 404);

        // A chat remembered from before; the person has since removed it.
        $user = $this->person();
        \Illuminate\Support\Facades\Cache::put('google-chat:space:' . $user->google_id, 'spaces/OLD', 3600);

        $this->actingAs($user)->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertJsonPath('state', 'not_added');
    }

    public function test_the_status_is_error_when_google_cannot_be_asked_and_never_explains_why(): void
    {
        $this->switchOn();
        $this->fakeGoogle(tokenStatus: 500);

        $response = $this->actingAs($this->person())->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertJsonPath('state', 'error');

        $this->assertStringNotContainsString('gserviceaccount', $response->getContent());
    }

    public function test_the_status_for_a_person_without_google_and_the_guards(): void
    {
        $this->getJson(route('profile.google-chat.status'))->assertUnauthorized();

        // Feature off.
        $this->actingAs($this->person())->getJson(route('profile.google-chat.status'))->assertNotFound();

        $this->switchOn();
        $this->fakeGoogle();
        $this->actingAs($this->person(false))->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertJsonPath('state', 'no_google');
        Http::assertNothingSent();
    }
    /* -------- the Welcome message -------- */

    private function messages(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))
            ->map(fn (array $pair) => $pair[0]->data())->values()->all();
    }

    private function welcomedAt(User $user)
    {
        return DB::table('users')->where('id', $user->id)->value('google_chat_welcomed_at');
    }

    public function test_the_first_time_a_person_is_found_synced_the_app_welcomes_them_once(): void
    {
        $this->switchOn();
        $this->fakeGoogle();
        $user = $this->person();

        $this->assertNull($this->welcomedAt($user));

        $this->actingAs($user)->getJson(route('profile.google-chat.status'))->assertOk()->assertJsonPath('state', 'connected');

        $messages = $this->messages();
        $this->assertCount(1, $messages);

        $card = $messages[0]['cardsV2'][0]['card'];
        $everything = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // A welcome, not an order: no dishes, no total, nothing made up.
        $this->assertSame('👋 Welcome to Cucina-Feliza', $card['header']['title']);
        $this->assertStringContainsString('When your order is ready for pick up', $everything);
        $this->assertStringNotContainsString('ORD-', $everything);
        $this->assertStringNotContainsString('Total', $everything);
        $this->assertNotNull($this->welcomedAt($user));

        // Checking again (or reloading the page) never sends it a second time.
        $this->actingAs($user)->getJson(route('profile.google-chat.status'))->assertOk();
        $this->assertCount(1, $this->messages());
    }

    public function test_nobody_is_welcomed_twice(): void
    {
        $this->switchOn();
        $this->fakeGoogle();

        $done = $this->person();
        DB::table('users')->where('id', $done->id)->update(['google_chat_welcomed_at' => now()]);

        $this->actingAs($done)->getJson(route('profile.google-chat.status'))->assertOk()->assertJsonPath('state', 'connected');
        $this->assertSame([], $this->messages());
    }

    public function test_nobody_is_welcomed_before_the_chat_exists(): void
    {
        $this->switchOn();
        $this->fakeGoogle(findStatus: 404);

        // No chat yet (404): not welcomed, nothing marked.
        $waiting = $this->person();

        $this->actingAs($waiting)->getJson(route('profile.google-chat.status'))->assertOk()->assertJsonPath('state', 'not_added');
        $this->assertSame([], $this->messages());
        $this->assertNull($this->welcomedAt($waiting));
    }

    public function test_a_welcome_google_refuses_is_tried_again_later(): void
    {
        $this->switchOn();
        $user = $this->person();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'chat.googleapis.com/v1/spaces:findDirectMessage*' => Http::response(['name' => 'spaces/AAA']),
            'chat.googleapis.com/v1/spaces/AAA/messages' => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        ]);

        $this->actingAs($user)->getJson(route('profile.google-chat.status'))->assertOk()->assertJsonPath('state', 'connected');

        // The mark was given back: the welcome is not lost.
        $this->assertNull($this->welcomedAt($user));
    }

    public function test_there_is_no_test_message_button_and_no_route_for_it(): void
    {
        $this->switchOn();
        $this->fakeGoogle();

        $this->actingAs($this->person())->get(route('profile.edit'))->assertOk()
            ->assertDontSee('Send me a test message')
            ->assertSee('Synced with Google Chat');

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('profile.google-chat.test'));
    }

    /* -------- the prompt after sign-in -------- */

    public function test_the_welcome_status_says_whether_the_welcome_just_went_out(): void
    {
        $this->switchOn();
        $this->fakeGoogle();
        $user = $this->person();

        $this->actingAs($user)->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertJsonPath('state', 'connected')->assertJsonPath('welcomed', true);

        $this->actingAs($user)->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertJsonPath('welcomed', false);
    }

    public function test_the_prompt_is_drawn_for_someone_who_signed_in_with_google_and_is_not_synced(): void
    {
        $this->switchOn();

        $this->actingAs($this->person())->get(route('dashboard'))->assertOk()
            ->assertSee('google-chat-prompt', false)
            ->assertSee('Get your order updates in Google Chat')
            ->assertSee('Maybe later')
            ->assertSee(route('profile.google-chat.status'), false)
            // No listing address: the button opens Google Chat, there is no guide to open first.
            // (The guide modal answers to `=== 'google-chat-guide'`; the prompt's own listener for the
            // guide's "proceeded" event is not the guide.)
            ->assertDontSee("=== 'google-chat-guide'", false);

        config(['services.google_chat.app_url' => 'https://workspace.google.com/marketplace/app/cucina/1']);

        $this->actingAs($this->person())->get(route('dashboard'))->assertOk()
            ->assertSee('Install Cucina-Feliza')
            ->assertSee("=== 'google-chat-guide'", false);
    }

    public function test_the_prompt_is_not_drawn_when_it_does_not_apply(): void
    {
        $this->switchOn();
        $marker = 'Get your order updates in Google Chat';

        // Never signed in with Google: they cannot be found, so nothing is offered.
        $this->actingAs($this->person(false))->get(route('dashboard'))->assertOk()->assertDontSee($marker);

        // Already welcomed.
        $welcomed = $this->person();
        DB::table('users')->where('id', $welcomed->id)->update(['google_chat_welcomed_at' => now()]);
        $this->actingAs($welcomed->fresh())->get(route('dashboard'))->assertOk()->assertDontSee($marker);

        // Already asked since this login.
        $this->withSession(['google_chat_checked' => true])->actingAs($this->person())->get(route('dashboard'))->assertOk()->assertDontSee($marker);

        // The Profile page has the full card (and its own guide): no second prompt on top of it.
        $this->actingAs($this->person())->get(route('profile.edit'))->assertOk()->assertDontSee($marker);
    }

    public function test_the_prompt_is_not_drawn_while_google_chat_is_off_or_not_set_up(): void
    {
        $marker = 'Get your order updates in Google Chat';

        // Switch off.
        $this->actingAs($this->person())->get(route('dashboard'))->assertOk()->assertDontSee($marker);

        // On, but no key file.
        Setting::setGoogleChatNotifications(true);
        config(['services.google_chat.credentials' => null]);
        $this->actingAs($this->person())->get(route('dashboard'))->assertOk()->assertDontSee($marker);
    }

    public function test_the_status_marks_the_login_session_so_the_next_page_does_not_ask_again(): void
    {
        $this->switchOn();
        $this->fakeGoogle(findStatus: 404);

        $this->actingAs($this->person())->getJson(route('profile.google-chat.status'))
            ->assertOk()->assertSessionHas('google_chat_checked', true);
    }

    public function test_maybe_later_stores_nothing_so_the_prompt_returns_at_every_login_until_synced(): void
    {
        $this->switchOn();
        $user = $this->person();
        $marker = 'Get your order updates in Google Chat';

        // "Maybe later" is only a button that closes the dialog: there is no endpoint, no column.
        $this->assertFalse(Schema::hasColumn('users', 'google_chat_prompt_dismissed_at'));
        $this->assertFalse(Route::has('profile.google-chat.dismiss'));

        // A visit where they were asked and closed it: not asked again on the next page...
        $this->actingAs($user)->getJson(route('profile.google-chat.status'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertDontSee($marker);

        // ...but the next login asks again, even when the session was kept (no sign out between).
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->get(route('dashboard'))->assertOk()->assertSee($marker);

        // Until they are synced (welcomed): then never again.
        DB::table('users')->where('id', $user->id)->update(['google_chat_welcomed_at' => now()]);
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->assertDontSee($marker);
    }

    public function test_a_sign_in_clears_the_asked_flag_even_without_signing_out(): void
    {
        $this->switchOn();
        $user = $this->person();
        $marker = 'Get your order updates in Google Chat';

        $this->withSession(['google_chat_checked' => true])->get(route('login'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        $this->assertNull(session('google_chat_checked'));
        $this->get(route('dashboard'))->assertOk()->assertSee($marker);
    }

    public function test_should_prompt_follows_the_rules(): void
    {
        $this->switchOn();

        $this->assertFalse(\App\Support\GoogleChat::shouldPrompt(null));
        $this->assertTrue(\App\Support\GoogleChat::shouldPrompt($this->person()));
        $this->assertFalse(\App\Support\GoogleChat::shouldPrompt($this->person(false)));
    }
}
