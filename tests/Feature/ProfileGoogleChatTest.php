<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            DB::table('users')->where('id', $user->id)->update(['google_id' => '123']);
        }

        return $user->fresh();
    }

    private function switchOn(): void
    {
        $this->configureKey();
        Setting::setGoogleChatNotifications(true);
        config(['services.google_chat.app_name' => 'Cucina-Feliza']);
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
            ->assertSee('Send me a test message')
            ->assertSee('Search for Cucina-Feliza')
            ->assertSee('https://chat.google.com', false);
    }

    public function test_the_link_can_point_at_the_app_listing(): void
    {
        $this->switchOn();
        config(['services.google_chat.app_url' => 'https://workspace.google.com/marketplace/app/cucina/1']);

        $this->actingAs($this->person())->get(route('profile.edit'))->assertOk()
            ->assertSee('https://workspace.google.com/marketplace/app/cucina/1', false);
    }

    public function test_someone_who_never_signed_in_with_google_is_told_how_instead_of_a_button(): void
    {
        $this->switchOn();

        $this->actingAs($this->person(false))->get(route('profile.edit'))->assertOk()
            ->assertSee('Continue with Google')
            ->assertDontSee('Send me a test message');
    }

    public function test_the_test_message_is_sent_to_the_person_as_a_card(): void
    {
        $this->switchOn();
        $this->fakeGoogle();

        $this->actingAs($this->person())->postJson(route('profile.google-chat.test'))
            ->assertOk()->assertJsonPath('status', 'success');

        $posted = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'));

        $this->assertCount(1, $posted);
        $this->assertArrayHasKey('cardsV2', $posted->first()[0]->data());
        Http::assertSent(fn (Request $request) => str_contains(urldecode($request->url()), 'users/123'));
    }

    public function test_a_person_who_has_not_added_the_app_is_told_what_to_do(): void
    {
        $this->switchOn();
        $this->fakeGoogle(findStatus: 404);

        $response = $this->actingAs($this->person())->postJson(route('profile.google-chat.test'))
            ->assertStatus(422)->assertJsonPath('status', 'failed');

        $this->assertStringContainsString('search for Cucina-Feliza', $response->json('message'));
        $this->assertSame([], Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/messages'))->all());
    }

    public function test_a_server_problem_is_never_explained_to_the_customer(): void
    {
        $this->switchOn();
        $this->fakeGoogle(tokenStatus: 500);

        $response = $this->actingAs($this->person())->postJson(route('profile.google-chat.test'))->assertStatus(422);

        $this->assertStringNotContainsString('gserviceaccount', $response->getContent());
        $this->assertStringNotContainsString('secret-project', $response->getContent());
        $this->assertStringContainsString('tell the staff', $response->json('message'));
    }

    public function test_the_test_needs_the_feature_a_google_account_and_a_login(): void
    {
        $this->postJson(route('profile.google-chat.test'))->assertUnauthorized();

        // Feature off.
        $this->actingAs($this->person())->postJson(route('profile.google-chat.test'))->assertNotFound();

        // On, but this person never signed in with Google.
        $this->switchOn();
        $this->fakeGoogle();
        $this->actingAs($this->person(false))->postJson(route('profile.google-chat.test'))->assertStatus(422);
        Http::assertNothingSent();
    }
}
