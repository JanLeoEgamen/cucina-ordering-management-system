<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

// "Continue with Google". Google itself is mocked: no network, no real credentials.
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    }

    // Make Socialite hand back this Google profile (or throw the given exception).
    private function google(array $overrides = [], ?\Throwable $fails = null): void
    {
        $profile = $overrides + [
            'sub' => 'g-123',
            'email' => 'Ana@Example.com',
            'name' => 'Ana Reyes',
            'given_name' => 'Ana',
            'family_name' => 'Reyes',
            'email_verified' => true,
        ];

        $provider = Mockery::mock(Provider::class);

        if ($fails) {
            $provider->shouldReceive('user')->andThrow($fails);
        } else {
            $user = (new SocialiteUser)->setRaw($profile)->map([
                'id' => $profile['sub'],
                'name' => $profile['name'],
                'email' => $profile['email'],
            ]);
            $provider->shouldReceive('user')->andReturn($user);
        }

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_it_is_off_until_the_credentials_are_set(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
        $this->get(route('google.redirect'))->assertNotFound();
        $this->get(route('google.callback'))->assertNotFound();
    }

    public function test_login_and_register_show_the_button_when_enabled(): void
    {
        $this->enable();

        $this->get('/login')->assertOk()->assertSee('Continue with Google')->assertSee(route('google.redirect'), false);
        $this->get('/register')->assertOk()->assertSee('Continue with Google');
    }

    public function test_the_redirect_goes_to_google(): void
    {
        $this->enable();

        $this->get(route('google.redirect'))->assertRedirectContains('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_a_new_google_user_is_created_as_a_customer_and_signed_in(): void
    {
        $this->seedPermissions();
        $this->enable();
        $this->google();
        Event::fake([Registered::class]);

        $this->get(route('google.callback'))->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'ana@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ana', $user->first_name);
        $this->assertSame('Reyes', $user->last_name);
        $this->assertSame('g-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(['Customer'], $user->getRoleNames()->all());
        Event::assertDispatched(Registered::class);

        // Not complete yet: the company is still unknown, so the app holds them on the form.
        $this->assertNull($user->profile_completed_at);
        $this->assertNull($user->company);
        $this->get('/dashboard')->assertRedirect(route('profile.complete'));
    }

    public function test_a_google_user_who_already_finished_their_profile_goes_straight_in(): void
    {
        $this->enable();
        $this->google();
        User::factory()->create(['email' => 'ana@example.com']);

        $this->get(route('google.callback'))->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertOk();
    }

    public function test_an_existing_account_is_linked_by_email_and_gets_no_duplicate(): void
    {
        $this->enable();
        $this->google();
        $existing = User::factory()->create(['email' => 'ana@example.com', 'company' => 'Acme']);

        $this->get(route('google.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($existing);
        $this->assertSame('g-123', $existing->fresh()->google_id);
        $this->assertSame('Acme', $existing->fresh()->company);
        $this->assertSame(1, User::where('email', 'ana@example.com')->count());
    }

    public function test_a_returning_google_user_is_found_by_google_id_even_if_the_email_changed(): void
    {
        $this->enable();
        $this->google(['email' => 'new-address@example.com']);
        $existing = User::factory()->create(['email' => 'ana@example.com', 'google_id' => 'g-123']);

        $this->get(route('google.callback'))->assertRedirect();

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count());
    }

    public function test_an_email_tied_to_another_google_account_is_refused(): void
    {
        $this->enable();
        $this->google(['sub' => 'g-other']);
        User::factory()->create(['email' => 'ana@example.com', 'google_id' => 'g-123']);

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unverified_google_email_is_refused(): void
    {
        $this->enable();
        $this->google(['email_verified' => false]);

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    public function test_a_deleted_account_is_refused_not_recreated(): void
    {
        $this->enable();
        $this->google();
        User::factory()->create(['email' => 'ana@example.com'])->delete();

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::withTrashed()->where('email', 'ana@example.com')->count());
    }

    public function test_a_failed_exchange_goes_back_to_login_with_a_message(): void
    {
        $this->enable();
        $this->google(fails: new \RuntimeException('denied'));

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_someone_already_signed_in_is_not_sent_to_google(): void
    {
        $this->enable();

        $this->actingAs(User::factory()->create())->get(route('google.redirect'))->assertRedirect();
        $this->assertAuthenticated();
    }
}
