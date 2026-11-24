<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The one password rule (AppServiceProvider: Password::defaults()): at least 8 characters with a
// letter, a number and a special character. Register, reset and change password all use it.
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $password): \Illuminate\Testing\TestResponse
    {
        return $this->post('/register', [
            'first_name' => 'Pat', 'last_name' => 'Cook', 'company_id' => \App\Models\Company::factory()->create()->company_id, 'employee_id' => 'E-1001',
            'email' => 'pat@example.com',
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    /** @dataProvider refusedPasswords */
    public function test_register_refuses_a_password_that_breaks_a_rule(string $password): void
    {
        $this->register($password)->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'pat@example.com']);
    }

    public static function refusedPasswords(): array
    {
        return [
            'no special character' => ['Password123'],
            'no number' => ['Password!!!'],
            'no letter' => ['12345678!'],
            'too short' => ['Ab1!'],
        ];
    }

    public function test_register_accepts_a_password_that_meets_every_rule(): void
    {
        $this->register('Password123!')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'pat@example.com']);
    }

    public function test_changing_the_password_uses_the_same_rule(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', ['current_password' => 'password', 'password' => 'NoSymbol123', 'password_confirmation' => 'NoSymbol123'])
            ->assertSessionHasErrorsIn('updatePassword', 'password');
    }

    public function test_only_the_register_page_draws_the_strength_indicator(): void
    {
        $register = $this->get(route('register'))->assertOk()->getContent();
        $login = $this->get(route('login'))->assertOk()->getContent();

        // Weak / Good / Strong words and the rule list are on the sign up page, hidden until typing.
        $this->assertStringContainsString('A special character', $register);
        $this->assertStringContainsString('x-show="value.length > 0"', $register);
        $this->assertSame(1, substr_count($register, 'x-show="value.length > 0"'));

        // Logging in is not choosing a password: no indicator there.
        $this->assertStringNotContainsString('A special character', $login);
    }
}
