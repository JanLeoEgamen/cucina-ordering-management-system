<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The show / hide (eye) button on the password fields of the sign in and sign up pages.
class PasswordToggleTest extends TestCase
{
    // The guest layout reads the dishes for its collage, so the tables must exist.
    use RefreshDatabase;

    public function test_the_login_page_has_one_password_field_with_a_show_button(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'x-bind:aria-pressed'));
        $this->assertStringContainsString('Show password', $html);
        // Still a password field until the button is pressed (and without JavaScript).
        $this->assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="password"/', $html);
    }

    public function test_the_register_page_has_a_show_button_on_both_password_fields(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'x-bind:aria-pressed'));
        $this->assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="password"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="password_confirmation"/', $html);
    }
}
