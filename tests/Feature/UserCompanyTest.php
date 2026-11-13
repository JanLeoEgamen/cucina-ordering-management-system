<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// A user may have a company: asked at registration, kept by the Users page and the profile.
class UserCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return $overrides + [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }

    private function userPayload(array $overrides = []): array
    {
        $this->seedPermissions();

        return $overrides + [
            'first_name' => 'Jan',
            'last_name' => 'Egamen',
            'email' => 'jan@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [DB::table('roles')->value('id')],
        ];
    }

    public function test_the_registration_form_asks_for_a_company(): void
    {
        $this->get('/register')->assertOk()->assertSee('name="company"', false);
    }

    public function test_registering_stores_the_company(): void
    {
        $this->post('/register', $this->registration(['company' => 'Acme Foods']))->assertRedirect();

        $this->assertSame('Acme Foods', User::where('email', 'test@example.com')->value('company'));
    }

    public function test_the_company_is_optional_at_registration(): void
    {
        $this->post('/register', $this->registration())->assertSessionHasNoErrors();

        $this->assertNull(User::where('email', 'test@example.com')->value('company'));
    }

    public function test_a_company_longer_than_255_characters_is_refused(): void
    {
        $this->post('/register', $this->registration(['company' => str_repeat('a', 256)]))
            ->assertSessionHasErrors('company');

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_an_admin_creates_and_edits_a_user_with_a_company(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson(route('users.store'), $this->userPayload(['company' => 'Acme Foods']))
            ->assertOk();

        $user = User::where('email', 'jan@example.com')->firstOrFail();
        $this->assertSame('Acme Foods', $user->company);

        $this->actingAs($admin)
            ->putJson(route('users.update', $user->id), $this->userPayload(['company' => 'Globex', 'password' => null, 'password_confirmation' => null]))
            ->assertOk();

        $this->assertSame('Globex', $user->fresh()->company);

        // Blank clears it.
        $this->actingAs($admin)
            ->putJson(route('users.update', $user->id), $this->userPayload(['company' => '', 'password' => null, 'password_confirmation' => null]))
            ->assertOk();

        $this->assertNull($user->fresh()->company);
    }

    public function test_the_feed_and_the_edit_record_carry_the_company(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->create(['company' => 'Initech']);

        $rows = $this->actingAs($admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('users.index'))->assertOk()->json('data');

        $this->assertSame('Initech', collect($rows)->firstWhere('id', $user->id)['company']);

        $this->actingAs($admin)->getJson(route('users.edit', $user->id))
            ->assertOk()
            ->assertJsonPath('company', 'Initech');
    }

    public function test_the_profile_shows_and_updates_the_company(): void
    {
        $user = User::factory()->create(['company' => 'Initech']);

        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('name="company"', false)->assertSee('Initech');

        $this->actingAs($user)->patch('/profile', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'company' => 'Umbrella',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Umbrella', $user->fresh()->company);
    }
}
