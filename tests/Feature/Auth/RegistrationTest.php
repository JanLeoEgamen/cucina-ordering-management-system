<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'company_id' => Company::factory()->create()->company_id,
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    private function register(): void
    {
        $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'company_id' => Company::factory()->create()->company_id,
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_new_user_is_a_customer_and_can_open_the_menu(): void
    {
        $this->seedPermissions();

        $this->register();

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertSame(['Customer'], $user->getRoleNames()->all());
        $this->assertTrue($user->can('view menu'));
        $this->assertTrue($user->can('create orders'));
        // Customers order; they do not manage anything.
        $this->assertFalse($user->can('view users'));
        $this->assertFalse($user->can('create order-sessions'));

        $this->get('/menu')->assertOk();
    }

    public function test_registering_still_works_before_the_roles_are_seeded(): void
    {
        // No PermissionSeeder run: the Customer role does not exist yet.
        $this->register();

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertCount(0, $user->roles);
    }

    public function test_the_customer_role_is_added_once_and_keeps_other_roles(): void
    {
        $this->seedPermissions();
        $user = User::factory()->create();
        $adminRoleId = DB::table('roles')->where('name', 'Super Admin')->value('id');
        User::syncUserRoles($user->id, [$adminRoleId]);

        User::giveCustomerRole($user->id);
        User::giveCustomerRole($user->id);

        $this->assertEqualsCanonicalizing(['Customer', 'Super Admin'], $user->fresh()->getRoleNames()->all());
    }

    public function test_registering_is_limited_to_five_attempts_a_minute_per_address(): void
    {
        // Invalid attempts count too: the cap is against flooding, not only against successes.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/register', [])->assertSessionHasErrors('email');
        }

        $this->post('/register', [])->assertStatus(429);
        $this->assertGuest();

        // Another address is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])
            ->post('/register', [])->assertSessionHasErrors('email');

        // The window passes and the first address can try again.
        $this->travel(61)->seconds();
        $this->post('/register', [])->assertSessionHasErrors('email');
    }

    public function test_registering_is_limited_to_thirty_attempts_an_hour_per_address(): void
    {
        // Six rounds of five attempts, a minute apart: the minute cap never trips, the hourly one must.
        for ($round = 0; $round < 6; $round++) {
            for ($i = 0; $i < 5; $i++) {
                $this->post('/register', [])->assertSessionHasErrors('email');
            }
            $this->travel(61)->seconds();
        }

        $this->post('/register', [])->assertStatus(429);
    }
}
