<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// A user belongs to a company, picked from the Companies list: required at registration, on the
// profile and in the Users page.
class UserCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];
    }

    private function userPayload(array $overrides = []): array
    {
        $this->seedPermissions();

        return $overrides + [
            'first_name' => 'Jan',
            'last_name' => 'Egamen',
            'email' => 'jan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'roles' => [DB::table('roles')->value('id')],
        ];
    }

    public function test_the_registration_form_offers_the_active_companies_in_a_dropdown(): void
    {
        Company::factory()->create(['company_name' => 'Acme Foods']);
        Company::factory()->inactive()->create(['company_name' => 'Closed Co']);
        Company::factory()->create(['company_name' => 'Gone Inc', 'deleted_at' => now()]);

        $this->get('/register')
            ->assertOk()
            ->assertSee('<select id="company_id" name="company_id"', false)
            ->assertSee('Acme Foods')
            ->assertDontSee('Closed Co')
            ->assertDontSee('Gone Inc');
    }

    public function test_the_registration_form_says_so_when_there_are_no_companies(): void
    {
        $this->get('/register')->assertOk()->assertSee('No companies have been added yet');
    }

    public function test_registering_stores_the_chosen_company(): void
    {
        $company = Company::factory()->create();

        $this->post('/register', $this->registration(['company_id' => $company->company_id]))->assertRedirect();

        $this->assertSame($company->company_id, User::where('email', 'test@example.com')->value('company_id'));
    }

    public function test_the_company_is_required_at_registration(): void
    {
        $this->post('/register', $this->registration())->assertSessionHasErrors('company_id');

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_registration_refuses_a_company_that_is_not_on_offer(): void
    {
        foreach ([
            'unknown' => 99999,
            'inactive' => Company::factory()->inactive()->create()->company_id,
            'deleted' => Company::factory()->create(['deleted_at' => now()])->company_id,
            'text' => 'Acme Foods',
        ] as $case => $value) {
            $this->post('/register', $this->registration(['company_id' => $value]))
                ->assertSessionHasErrors('company_id');

            $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        }
    }

    public function test_an_admin_creates_and_edits_a_user_with_a_company(): void
    {
        $admin = $this->superAdmin();
        $acme = Company::factory()->create();
        $globex = Company::factory()->create();

        $this->actingAs($admin)
            ->postJson(route('users.store'), $this->userPayload(['company_id' => $acme->company_id]))
            ->assertOk();

        $user = User::where('email', 'jan@example.com')->firstOrFail();
        $this->assertSame($acme->company_id, $user->company_id);

        $this->actingAs($admin)
            ->putJson(route('users.update', $user->id), $this->userPayload(['company_id' => $globex->company_id, 'password' => null, 'password_confirmation' => null]))
            ->assertOk();

        $this->assertSame($globex->company_id, $user->fresh()->company_id);
    }

    public function test_the_users_page_requires_a_company_on_create_and_edit(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->postJson(route('users.store'), $this->userPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        $this->actingAs($admin)
            ->putJson(route('users.update', $user->id), $this->userPayload(['company_id' => '', 'password' => null, 'password_confirmation' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');
    }

    public function test_an_admin_cannot_put_a_new_user_in_an_inactive_company_but_can_keep_one_there(): void
    {
        $admin = $this->superAdmin();
        $off = Company::factory()->inactive()->create();

        $this->actingAs($admin)
            ->postJson(route('users.store'), $this->userPayload(['company_id' => $off->company_id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        // Someone who belongs to it already can be edited without being moved.
        $user = User::factory()->create(['company_id' => $off->company_id]);

        $this->actingAs($admin)
            ->putJson(route('users.update', $user->id), $this->userPayload([
                'first_name' => 'Renamed',
                'company_id' => $off->company_id,
                'password' => null,
                'password_confirmation' => null,
            ]))
            ->assertOk();

        $this->assertSame($off->company_id, $user->fresh()->company_id);
    }

    public function test_the_feed_and_the_edit_record_carry_the_company(): void
    {
        $admin = $this->superAdmin();
        $company = Company::factory()->create(['company_name' => 'Initech']);
        $user = User::factory()->create(['company_id' => $company->company_id]);
        $without = User::factory()->create(['company_id' => null]);

        $rows = collect($this->actingAs($admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('users.index'))->assertOk()->json('data'));

        $this->assertSame('Initech', $rows->firstWhere('id', $user->id)['company']);
        $this->assertNull($rows->firstWhere('id', $without->id)['company']);

        $this->actingAs($admin)->getJson(route('users.edit', $user->id))
            ->assertOk()
            ->assertJsonPath('company', 'Initech')
            ->assertJsonPath('company_id', $company->company_id);
    }

    public function test_the_users_page_lists_every_company_in_its_drawers(): void
    {
        Company::factory()->create(['company_name' => 'Acme Foods']);
        Company::factory()->inactive()->create(['company_name' => 'Closed Co']);

        $this->actingAs($this->superAdmin())->get(route('users.index'))
            ->assertOk()
            ->assertSee('name="company_id"', false)
            ->assertSee('Acme Foods')
            // Only the edit drawer lists a company that is switched off, marked as such.
            ->assertSee('Closed Co (inactive)', false);
    }

    public function test_the_profile_shows_and_updates_the_company(): void
    {
        $initech = Company::factory()->create(['company_name' => 'Initech']);
        $umbrella = Company::factory()->create(['company_name' => 'Umbrella']);
        $user = User::factory()->create(['company_id' => $initech->company_id]);

        $this->actingAs($user)->get('/profile')
            ->assertOk()
            ->assertSee('name="company_id"', false)
            ->assertSee('Initech')
            ->assertSee('Umbrella');

        $this->actingAs($user)->patch('/profile', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'company_id' => $umbrella->company_id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($umbrella->company_id, $user->fresh()->company_id);
    }

    public function test_the_profile_requires_a_company(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
        ])->assertSessionHasErrors('company_id');
    }

    public function test_the_profile_keeps_a_company_that_was_switched_off_since(): void
    {
        $company = Company::factory()->create(['company_name' => 'Old Co']);
        $user = User::factory()->create(['company_id' => $company->company_id]);
        $company->update(['is_active' => false]);

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('Old Co (inactive)', false);

        $this->actingAs($user)->patch('/profile', [
            'first_name' => 'Renamed',
            'last_name' => $user->last_name,
            'email' => $user->email,
            'company_id' => $company->company_id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($company->company_id, $user->fresh()->company_id);
    }
}
