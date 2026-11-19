<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    // Every status that has its own wording, and the title it must show.
    private const TITLES = [
        401 => 'Sign in to continue',
        403 => 'You do not have access',
        404 => 'Page not found',
        405 => 'That is not allowed here',
        408 => 'The request took too long',
        413 => 'That file is too large',
        419 => 'This page has expired',
        429 => 'Too many requests',
        500 => 'Something went wrong on our side',
        502 => 'The server could not be reached',
        503 => 'We will be right back',
        504 => 'The server took too long',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // A route that fails with whatever status it is asked for, as a real controller would.
        Route::middleware('web')->get('/_fail/{code}', function (int $code) {
            abort($code);
        })->whereNumber('code');
    }

    public function test_every_listed_status_has_its_own_themed_page(): void
    {
        foreach (self::TITLES as $code => $title) {
            $html = $this->get('/_fail/' . $code)
                ->assertStatus($code)
                ->assertSee($title)
                ->getContent();

            $this->assertStringContainsString('>' . $code . '</p>', $html, (string) $code);
            // The app's theme: the saved dark / light choice before paint, the switch, the Cucina brand.
            $this->assertStringContainsString("localStorage.getItem('cucina.theme')", $html, (string) $code);
            $this->assertStringContainsString('data-theme-toggle', $html, (string) $code);
            $this->assertStringContainsString('bg-gray-50', $html, (string) $code);
            $this->assertStringContainsString('dark:bg-gray-900', $html, (string) $code);
            $this->assertStringContainsString('Cucina', $html, (string) $code);
            // Not the framework's grey page.
            $this->assertStringNotContainsString('antialiased"><div class="relative flex items-top', $html, (string) $code);
        }
    }

    public function test_a_status_without_its_own_page_falls_back_to_the_generic_4xx_and_5xx(): void
    {
        $this->get('/_fail/418')
            ->assertStatus(418)
            ->assertSee('We could not open that')
            ->assertSee('>418</p>', false)
            ->assertDontSee('Try again');

        $this->get('/_fail/507')
            ->assertStatus(507)
            ->assertSee('Something went wrong on our side')
            ->assertSee('>507</p>', false)
            ->assertSee('Try again');
    }

    public function test_only_the_errors_that_can_pass_offer_try_again(): void
    {
        $this->get('/_fail/404')->assertDontSee('Try again');
        $this->get('/_fail/403')->assertDontSee('Try again');
        $this->get('/_fail/419')->assertSee('Try again');
        $this->get('/_fail/503')->assertSee('Try again');
    }

    public function test_a_guest_is_sent_home_and_a_signed_in_user_to_the_dashboard(): void
    {
        $this->get('/_fail/404')
            ->assertSee('Back to home')
            ->assertDontSee('Go to dashboard');

        $this->actingAs($this->userWith())
            ->get('/_fail/404')
            ->assertSee('Go to dashboard')
            ->assertSee(route('dashboard'), false);
    }

    public function test_a_missing_url_and_a_forbidden_page_use_the_themed_page(): void
    {
        $this->get('/there-is-no-such-page')
            ->assertNotFound()
            ->assertSee('Page not found');

        // A user without the permission is refused by the controller's middleware.
        $this->actingAs($this->userWith('view menu'))
            ->get(route('users.index'))
            ->assertForbidden()
            ->assertSee('You do not have access')
            // The permission package's technical wording is not shown to the user.
            ->assertDontSee('User does not have the right permissions');
    }

    public function test_a_403_shows_the_sentence_the_app_wrote_itself(): void
    {
        Route::middleware('web')->get('/_deny', function () {
            abort(403, 'Complete your profile first.');
        });

        $this->get('/_deny')
            ->assertForbidden()
            ->assertSee('Complete your profile first.');
    }

    public function test_an_uncaught_exception_shows_the_500_page_and_never_its_message_in_production(): void
    {
        config(['app.debug' => false]);

        Route::middleware('web')->get('/_boom', function () {
            throw new \RuntimeException('SQLSTATE[HY000] secret detail');
        });

        $this->get('/_boom')
            ->assertStatus(500)
            ->assertSee('Something went wrong on our side')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('secret detail');
    }

    public function test_an_ajax_request_still_gets_json_not_the_page(): void
    {
        $this->getJson('/there-is-no-such-page')
            ->assertNotFound()
            ->assertJsonMissingPath('html');

        $this->getJson('/_fail/403')->assertForbidden()->assertJsonStructure(['message']);
    }
}
