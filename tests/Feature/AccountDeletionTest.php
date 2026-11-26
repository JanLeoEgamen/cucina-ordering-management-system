<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Deleting your own account from the Profile page (known-issues F19): it is a soft delete, the
// page says so, it drops the roles like the Users page does, and a pending order blocks it.
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $this->seedPermissions();

        $user = User::factory()->create();
        $user->assignRole(Role::CUSTOMER);

        return $user;
    }

    private function orderFor(User $user, string $status, array $overrides = []): void
    {
        Order::factory()->create(['user_id' => $user->id, 'order_status' => $status] + $overrides);
    }

    private function deleteOwnAccount(User $user)
    {
        return $this->actingAs($user)->from('/profile')->delete('/profile', ['password' => 'password']);
    }

    public function test_the_warnings_say_what_really_happens(): void
    {
        $this->actingAs($this->customer())->get('/profile')
            ->assertOk()
            ->assertSee('Your account details and order history are kept')
            ->assertDontSee('permanently delete');
    }

    public function test_deleting_your_account_drops_its_roles(): void
    {
        $user = $this->customer();

        $this->deleteOwnAccount($user)->assertRedirect('/');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $user->id, 'model_type' => User::class]);
        $this->assertGuest();
    }

    public function test_a_pending_order_blocks_the_deletion(): void
    {
        $user = $this->customer();
        $this->orderFor($user, 'pending');

        $this->deleteOwnAccount($user)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSoftDeleted('users', ['id' => $user->id]);
        $this->assertTrue($user->fresh()->hasRole(Role::CUSTOMER));
    }

    public function test_closed_removed_and_other_peoples_orders_do_not_block_it(): void
    {
        $user = $this->customer();
        $this->orderFor($user, 'completed');
        $this->orderFor($user, 'cancelled');
        $this->orderFor($user, 'pending', ['deleted_at' => now()]);
        $this->orderFor($this->customer(), 'pending');

        $this->deleteOwnAccount($user)->assertRedirect('/');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }
}
