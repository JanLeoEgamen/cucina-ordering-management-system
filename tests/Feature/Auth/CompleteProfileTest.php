<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// A first "Continue with Google" lands on "Complete your profile" and cannot go further
// until the company (and a correct name) is given.
class CompleteProfileTest extends TestCase
{
    use RefreshDatabase;

    private function incomplete(): User
    {
        return User::factory()->profileIncomplete()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
    }

    public function test_an_incomplete_user_is_sent_to_the_form_from_every_gated_page(): void
    {
        $this->actingAs($this->incomplete());

        foreach (['/dashboard', '/profile', '/menu', '/notifications', '/users'] as $url) {
            $this->get($url)->assertRedirect(route('profile.complete'));
        }
    }

    public function test_a_script_request_gets_a_403_not_a_redirect(): void
    {
        $this->actingAs($this->incomplete())
            ->getJson('/notifications/feed')
            ->assertForbidden();
    }

    public function test_the_form_is_prefilled_and_asks_for_the_company(): void
    {
        $this->actingAs($this->incomplete())
            ->get(route('profile.complete'))
            ->assertOk()
            ->assertSee('name="company_id"', false)
            ->assertSee('value="Ana"', false)
            ->assertSee('value="Reyes"', false);
    }

    public function test_saving_completes_the_profile_and_opens_the_app(): void
    {
        $user = $this->incomplete();
        $company = Company::factory()->create(['company_name' => 'Acme Foods']);

        $this->actingAs($user)
            ->post(route('profile.complete.store'), ['first_name' => 'Ana Marie', 'last_name' => 'Reyes', 'company_id' => $company->company_id, 'employee_id' => 'E-2002'])
            ->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();
        $this->assertSame($company->company_id, $user->company_id);
        $this->assertSame('Ana Marie', $user->first_name);
        $this->assertNotNull($user->profile_completed_at);

        $this->get('/dashboard')->assertOk();
    }

    public function test_the_company_and_both_names_are_required(): void
    {
        $user = $this->incomplete();

        $this->actingAs($user)
            ->post(route('profile.complete.store'), ['first_name' => '', 'last_name' => '', 'company_id' => ''])
            ->assertSessionHasErrors(['first_name', 'last_name', 'company_id']);

        $this->assertNull($user->fresh()->profile_completed_at);
        $this->get('/dashboard')->assertRedirect(route('profile.complete'));
    }

    public function test_a_complete_user_is_not_held_and_the_form_sends_them_on(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertOk();
        $this->get(route('profile.complete'))->assertRedirect(route('dashboard'));
    }

    public function test_a_complete_user_cannot_overwrite_their_details_through_the_form(): void
    {
        $initech = Company::factory()->create(['company_name' => 'Initech']);
        $other = Company::factory()->create(['company_name' => 'Other']);
        $user = User::factory()->create(['company_id' => $initech->company_id]);

        $this->actingAs($user)
            ->post(route('profile.complete.store'), ['first_name' => 'X', 'last_name' => 'Y', 'company_id' => $other->company_id])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($initech->company_id, $user->fresh()->company_id);
    }

    public function test_the_form_needs_a_login(): void
    {
        $this->get(route('profile.complete'))->assertRedirect(route('login'));
        $this->post(route('profile.complete.store'))->assertRedirect(route('login'));
    }

    public function test_an_incomplete_user_can_still_log_out(): void
    {
        $this->actingAs($this->incomplete())->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_the_other_ways_to_get_an_account_are_complete_from_the_start(): void
    {
        $company = Company::factory()->create();

        $this->post('/register', [
            'first_name' => 'Test', 'last_name' => 'User', 'company_id' => $company->company_id, 'employee_id' => 'E-1001', 'email' => 'reg@example.com',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
        ]);
        $this->assertNotNull(User::where('email', 'reg@example.com')->value('profile_completed_at'));

        $this->seedPermissions();
        $id = User::createUser([
            'first_name' => 'A', 'last_name' => 'B', 'company_id' => $company->company_id, 'email' => 'admin-made@example.com', 'password' => 'password123',
        ], [DB::table('roles')->value('id')]);
        $this->assertNotNull(User::find($id)->profile_completed_at);
    }

    public function test_the_migration_marks_every_existing_account_complete(): void
    {
        $migration = require database_path('migrations/2026_11_13_400000_add_profile_completed_at_to_users_table.php');

        $migration->down();
        DB::table('users')->insert(['first_name' => 'Old', 'last_name' => 'Timer', 'email' => 'old@example.com', 'password' => 'x']);
        $migration->up();

        $this->assertNotNull(DB::table('users')->where('email', 'old@example.com')->value('profile_completed_at'));
    }

    public function test_the_employee_id_is_required_unless_the_company_is_guest(): void
    {
        $user = $this->incomplete();
        $acme = Company::factory()->create(['company_name' => 'Acme Foods']);
        $guest = Company::factory()->create(['company_name' => Company::GUEST]);

        $this->actingAs($user)
            ->post(route('profile.complete.store'), ['first_name' => 'Ana', 'last_name' => 'Reyes', 'company_id' => $acme->company_id])
            ->assertSessionHasErrors('employee_id');
        $this->assertNull($user->fresh()->profile_completed_at);

        $this->actingAs($user)
            ->post(route('profile.complete.store'), ['first_name' => 'Ana', 'last_name' => 'Reyes', 'company_id' => $guest->company_id, 'employee_id' => 'E-5'])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNotNull($user->profile_completed_at);
        $this->assertNull($user->employee_id);
    }
}
