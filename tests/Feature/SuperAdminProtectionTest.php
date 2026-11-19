<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Business rule: an account with the Super Admin role is never deleted (known-issues / security S10).
class SuperAdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_cannot_be_deleted_from_the_users_page(): void
    {
        $actor = $this->userWith('delete users');
        $target = $this->superAdmin();

        $this->actingAs($actor)
            ->deleteJson(route('users.destroy', $target->id))
            ->assertStatus(422)
            ->assertJson(['status' => 'failed', 'message' => 'A Super Admin account cannot be deleted.']);

        $this->assertNotSoftDeleted('users', ['id' => $target->id]);
        $this->assertTrue($target->fresh()->hasRole(Role::SUPER_ADMIN));
    }

    public function test_one_super_admin_cannot_delete_another(): void
    {
        $actor = $this->superAdmin();
        $target = $this->superAdmin();

        $this->actingAs($actor)
            ->deleteJson(route('users.destroy', $target->id))
            ->assertStatus(422);

        $this->assertNotSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_an_ordinary_user_can_still_be_deleted(): void
    {
        $actor = $this->userWith('delete users');
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->deleteJson(route('users.destroy', $target->id))
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_deleting_users_still_needs_the_permission(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->userWith('view users'))
            ->deleteJson(route('users.destroy', $target->id))
            ->assertForbidden();

        $this->assertNotSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_a_super_admin_cannot_delete_their_own_account_from_the_profile(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->from('/profile')
            ->delete('/profile', ['password' => 'password'])
            ->assertRedirect(route('profile.edit'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_the_profile_page_offers_no_delete_section_to_a_super_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/profile')
            ->assertOk()
            ->assertDontSee('confirm-user-deletion');

        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertOk()
            ->assertSee('confirm-user-deletion');
    }

    public function test_the_users_feed_has_no_delete_button_on_a_super_admin_row(): void
    {
        $actor = $this->superAdmin();
        $plain = User::factory()->create(['first_name' => 'Plainperson']);

        $rows = $this->actingAs($actor)
            ->getJson(route('users.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data');

        $this->assertContains($plain->id, array_column($rows, 'id'));

        foreach ($rows as $row) {
            $isAdmin = $row['id'] === $actor->id;
            $hasDelete = str_contains($row['action'], 'data-row-action="delete"');

            $this->assertSame(! $isAdmin, $hasDelete, 'user ' . $row['id']);
        }
    }

    public function test_the_model_refuses_even_when_called_directly(): void
    {
        $admin = $this->superAdmin();

        $this->assertFalse(User::deleteUser($admin->id));
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }
}
