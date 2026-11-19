<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Several tabs of one browser share ONE session cookie: logging out in a tab forgets the user for
// all of them on the server. What the other tabs need is a way to find out (ui/session-guard.js),
// and that needs three things from the server, which these tests pin down.
class CrossTabLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_page_tells_the_tab_where_to_go_when_the_session_is_gone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('<meta name="login-url" content="' . route('login') . '">', false)
            // The Log Out form is flagged so the guard can announce the logout to the other tabs.
            ->assertSee('data-logout-form', false);
    }

    public function test_a_signed_out_page_has_no_login_url_so_the_guard_never_redirects_it(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('name="login-url"', false);
    }

    public function test_the_notification_poll_of_a_stale_tab_gets_a_401_after_the_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');
        $this->assertGuest();

        // What the other tab's poll sends: it must be a 401 (the guard's cue), never a redirect to HTML.
        $this->getJson(route('notifications.feed'))->assertUnauthorized();
    }
}
