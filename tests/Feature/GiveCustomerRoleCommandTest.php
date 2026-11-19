<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// users:give-customer-role (known-issues F2): the accounts that registered before sign-ups got
// the Customer role have none, and the menu answers 403 for them.
class GiveCustomerRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    private function hasCustomerRole(User $user): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Role::CUSTOMER)
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', User::class)
            ->exists();
    }

    public function test_a_role_less_account_gets_the_customer_role_and_can_open_the_menu(): void
    {
        $this->seed(PermissionSeeder::class);
        $old = User::factory()->create();

        $this->actingAs($old)->get('/menu')->assertForbidden();

        $this->artisan('users:give-customer-role')->assertSuccessful();

        $this->assertTrue($this->hasCustomerRole($old));
        $this->actingAs($old->fresh())->get('/menu')->assertOk();
    }

    public function test_accounts_that_already_have_a_role_are_left_alone(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Role::SUPER_ADMIN);

        $this->artisan('users:give-customer-role')->assertSuccessful();

        $this->assertFalse($this->hasCustomerRole($admin));
        $this->assertSame([Role::SUPER_ADMIN], $admin->fresh()->getRoleNames()->all());
    }

    public function test_a_deleted_account_is_skipped(): void
    {
        $this->seed(PermissionSeeder::class);
        $gone = User::factory()->create();
        User::deleteUser($gone->id);

        $this->artisan('users:give-customer-role')->assertSuccessful();

        $this->assertFalse($this->hasCustomerRole($gone));
    }

    public function test_a_dry_run_lists_but_changes_nothing(): void
    {
        $this->seed(PermissionSeeder::class);
        $old = User::factory()->create(['email' => 'old.customer@example.com']);

        $this->artisan('users:give-customer-role --dry-run')
            ->expectsOutputToContain('old.customer@example.com')
            ->assertSuccessful();

        $this->assertFalse($this->hasCustomerRole($old));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $this->seed(PermissionSeeder::class);
        $old = User::factory()->create();

        $this->artisan('users:give-customer-role')->assertSuccessful();
        $this->artisan('users:give-customer-role')
            ->expectsOutputToContain('Nothing to do')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $old->id)->count());
    }

    public function test_it_refuses_when_the_customer_role_is_not_seeded(): void
    {
        User::factory()->create();

        $this->artisan('users:give-customer-role')
            ->expectsOutputToContain('PermissionSeeder')
            ->assertFailed();
    }
}
