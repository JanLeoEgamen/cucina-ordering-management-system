<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Nobody gives, changes or takes away more access than they have (App\Support\AccessGuard,
// security.md "Privilege rules": S1, S2, S18).
class PrivilegeRulesTest extends TestCase
{
    use RefreshDatabase;

    private const MANAGER = ['view users', 'create users', 'edit users', 'delete users'];

    private const ROLE_MANAGER = ['view roles', 'create roles', 'edit roles', 'delete roles'];

    private function roleId(string $name): int
    {
        return (int) DB::table('roles')->where('name', $name)->value('id');
    }

    private function permissionIds(string ...$names): array
    {
        return DB::table('permissions')->whereIn('name', $names)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function role(string $name, string ...$permissions): int
    {
        $this->seedPermissions();

        return Role::createRole(['name' => $name], $this->permissionIds(...$permissions));
    }

    private function userInRole(int $roleId): User
    {
        $user = User::factory()->create();
        User::syncUserRoles($user->id, [$roleId]);

        return $user->fresh();
    }

    private function payload(array $roleIds, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Pat',
            'last_name' => 'Lee',
            'company_id' => Company::factory()->create()->company_id,
            'employee_id' => 'E-1001',
            'email' => 'pat@example.com',
            'password' => 'Sup3r-secret-pw!',
            'password_confirmation' => 'Sup3r-secret-pw!',
            'roles' => $roleIds,
        ], $overrides);
    }

    private function updatePayload(User $user, array $roleIds, array $overrides = []): array
    {
        return $this->payload($roleIds, array_merge([
            'first_name' => $user->first_name,
            'email' => $user->email,
            'password' => null,
            'password_confirmation' => null,
        ], $overrides));
    }

    private function rows(User $actor, string $route): array
    {
        return collect($this->actingAs($actor)
            ->getJson(route($route), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data'))->keyBy('id')->all();
    }

    /* -------- giving roles (S1) -------- */

    public function test_a_user_manager_cannot_create_a_super_admin_or_any_stronger_account(): void
    {
        $actor = $this->userWith(...self::MANAGER);
        $stronger = $this->role('Cashier', 'verify payments');

        foreach ([$this->roleId(Role::SUPER_ADMIN), $stronger] as $roleId) {
            $this->actingAs($actor)
                ->postJson(route('users.store'), $this->payload([$roleId]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('roles');
        }

        $this->assertDatabaseMissing('users', ['email' => 'pat@example.com']);
    }

    public function test_holding_every_permission_is_not_enough_to_give_super_admin(): void
    {
        $actor = $this->userWith(...Role::PERMISSIONS);

        $this->actingAs($actor)
            ->postJson(route('users.store'), $this->payload([$this->roleId(Role::SUPER_ADMIN)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    public function test_a_user_manager_gives_roles_within_their_own_access_and_the_customer_role(): void
    {
        $helper = $this->role('Helper', 'view users');
        // None of the ordering permissions: those never count against anyone.
        $actor = $this->userWith(...self::MANAGER);

        $this->actingAs($actor)
            ->postJson(route('users.store'), $this->payload([$helper, $this->roleId(Role::CUSTOMER)]))
            ->assertOk();

        $this->assertEqualsCanonicalizing(['Customer', 'Helper'], User::where('email', 'pat@example.com')->firstOrFail()->getRoleNames()->all());
    }

    public function test_a_user_manager_cannot_add_a_stronger_role_to_an_account(): void
    {
        $actor = $this->userWith(...self::MANAGER);
        $cashier = $this->role('Cashier', 'verify payments');
        $target = $this->userInRole($this->roleId(Role::CUSTOMER));

        $this->actingAs($actor)
            ->putJson(route('users.update', $target->id), $this->updatePayload($target, [$cashier]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');

        $this->assertSame([Role::CUSTOMER], $target->fresh()->getRoleNames()->all());
    }

    /* -------- stronger accounts (S1) -------- */

    public function test_a_user_manager_cannot_take_over_a_super_admin(): void
    {
        $actor = $this->userWith(...self::MANAGER);
        $admin = $this->superAdmin();
        $hash = $admin->password;

        $this->actingAs($actor)
            ->putJson(route('users.update', $admin->id), $this->updatePayload($admin, [$this->roleId(Role::CUSTOMER)], [
                'email' => 'mine@example.com',
                'password' => 'Taken-over-1!',
                'password_confirmation' => 'Taken-over-1!',
            ]))
            ->assertForbidden();

        $fresh = $admin->fresh();
        $this->assertSame($admin->email, $fresh->email);
        $this->assertSame($hash, $fresh->password);
        $this->assertTrue($fresh->hasRole(Role::SUPER_ADMIN));
    }

    public function test_a_stronger_account_cannot_be_edited_or_deleted(): void
    {
        $actor = $this->userWith(...self::MANAGER);
        $byRole = $this->userInRole($this->role('Cashier', 'verify payments'));
        // A permission held directly counts too.
        $byPermission = $this->userWith('verify payments');

        foreach ([$byRole, $byPermission] as $target) {
            $this->actingAs($actor)
                ->putJson(route('users.update', $target->id), $this->updatePayload($target, [$this->roleId(Role::CUSTOMER)]))
                ->assertForbidden();

            $this->actingAs($actor)->deleteJson(route('users.destroy', $target->id))->assertForbidden();

            $this->assertNotSoftDeleted('users', ['id' => $target->id]);
        }
    }

    public function test_a_weaker_account_can_still_be_edited_and_deleted(): void
    {
        $actor = $this->userWith(...self::MANAGER);
        $target = $this->userInRole($this->roleId(Role::CUSTOMER));

        $this->actingAs($actor)
            ->putJson(route('users.update', $target->id), $this->updatePayload($target, [$this->roleId(Role::CUSTOMER)], ['first_name' => 'Renamed']))
            ->assertOk();

        $this->assertSame('Renamed', $target->fresh()->first_name);

        $this->actingAs($actor)->deleteJson(route('users.destroy', $target->id))->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_a_super_admin_can_edit_and_demote_another_super_admin(): void
    {
        $actor = $this->superAdmin();
        $other = $this->superAdmin();

        $this->actingAs($actor)
            ->putJson(route('users.update', $other->id), $this->updatePayload($other, [$this->roleId(Role::CUSTOMER)]))
            ->assertOk();

        $this->assertSame([Role::CUSTOMER], $other->fresh()->getRoleNames()->all());
    }

    /* -------- your own roles (S1) -------- */

    public function test_nobody_changes_their_own_roles_not_even_a_super_admin(): void
    {
        $admin = $this->superAdmin();
        $superAdmin = $this->roleId(Role::SUPER_ADMIN);
        $customer = $this->roleId(Role::CUSTOMER);

        foreach ([[$customer], [$superAdmin, $customer]] as $roles) {
            $this->actingAs($admin)
                ->putJson(route('users.update', $admin->id), $this->updatePayload($admin, $roles))
                ->assertStatus(422)
                ->assertJsonValidationErrors('roles');
        }

        $this->assertSame([Role::SUPER_ADMIN], $admin->fresh()->getRoleNames()->all());
    }

    public function test_your_other_details_can_still_be_edited_with_the_same_roles(): void
    {
        $manager = $this->role('Manager', ...self::MANAGER);
        $actor = $this->userInRole($manager);

        $this->actingAs($actor)
            ->putJson(route('users.update', $actor->id), $this->updatePayload($actor, [$manager], ['first_name' => 'Myself']))
            ->assertOk();

        $this->assertSame('Myself', $actor->fresh()->first_name);
    }

    /* -------- what the Users page offers -------- */

    public function test_the_users_feed_has_no_edit_or_delete_button_on_a_stronger_account(): void
    {
        $actor = $this->userWith(...self::MANAGER);
        $stronger = $this->userInRole($this->role('Cashier', 'verify payments'));
        $direct = $this->userWith('verify payments');
        $weaker = $this->userInRole($this->roleId(Role::CUSTOMER));

        $rows = $this->rows($actor, 'users.index');

        foreach ([$stronger, $direct] as $user) {
            $this->assertStringNotContainsString('data-row-action="edit"', $rows[$user->id]['action']);
            $this->assertStringNotContainsString('data-row-action="delete"', $rows[$user->id]['action']);
            $this->assertStringContainsString('data-row-action="view"', $rows[$user->id]['action']);
        }

        $this->assertStringContainsString('data-row-action="edit"', $rows[$weaker->id]['action']);
        $this->assertStringContainsString('data-row-action="delete"', $rows[$weaker->id]['action']);
    }

    public function test_the_user_drawers_offer_only_roles_the_actor_may_give(): void
    {
        $this->role('Cashier', 'verify payments');
        $this->role('Helper', 'view users');

        $response = $this->actingAs($this->userWith(...self::MANAGER))->get(route('users.index'))->assertOk();

        $this->assertEqualsCanonicalizing(['Customer', 'Helper'], array_column($response->viewData('grantableRoles'), 'name'));
        // The table filter still lists them all.
        $this->assertCount(4, $response->viewData('roleOptions'));
    }

    /* -------- roles (S2) -------- */

    public function test_a_role_cannot_be_created_with_a_permission_you_lack(): void
    {
        $actor = $this->userWith(...self::ROLE_MANAGER);

        $this->actingAs($actor)
            ->postJson(route('roles.store'), ['name' => 'Sneaky', 'permissions' => $this->permissionIds('view roles', 'verify payments')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');

        $this->assertDatabaseMissing('roles', ['name' => 'Sneaky']);
    }

    public function test_a_role_can_be_created_with_your_own_and_the_ordering_permissions(): void
    {
        $actor = $this->userWith(...self::ROLE_MANAGER);
        $ids = $this->permissionIds('view roles', 'view menu', 'create orders');

        $this->actingAs($actor)->postJson(route('roles.store'), ['name' => 'Staff', 'permissions' => $ids])->assertOk();

        $this->assertEqualsCanonicalizing($ids, Role::permissionIds($this->roleId('Staff')));
    }

    public function test_you_cannot_add_a_permission_to_a_role_you_hold(): void
    {
        $own = $this->role('Role keeper', ...self::ROLE_MANAGER);
        $actor = $this->userInRole($own);

        $this->actingAs($actor)
            ->putJson(route('roles.update', $own), ['name' => 'Role keeper', 'permissions' => $this->permissionIds(...self::ROLE_MANAGER, ...['edit users'])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');

        $this->assertFalse($actor->fresh()->can('edit users'));
    }

    public function test_a_stronger_role_cannot_be_edited_or_deleted(): void
    {
        $actor = $this->userWith(...self::ROLE_MANAGER);
        $cashier = $this->role('Cashier', 'verify payments');

        // Not even to strip it.
        $this->actingAs($actor)
            ->putJson(route('roles.update', $cashier), ['name' => 'Cashier', 'permissions' => []])
            ->assertForbidden();

        $this->actingAs($actor)->deleteJson(route('roles.destroy', $cashier))->assertForbidden();

        $this->assertSame($this->permissionIds('verify payments'), Role::permissionIds($cashier));
    }

    public function test_the_customer_role_cannot_be_renamed_or_deleted_by_anyone(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->roleId(Role::CUSTOMER);

        foreach (['Shopper', 'customer'] as $name) {
            $this->actingAs($admin)
                ->putJson(route('roles.update', $customer), ['name' => $name, 'permissions' => []])
                ->assertStatus(422)
                ->assertJsonValidationErrors('name');
        }

        $this->actingAs($admin)->deleteJson(route('roles.destroy', $customer))->assertStatus(422);

        $this->assertSame($customer, $this->roleId(Role::CUSTOMER));
        $this->assertTrue($this->actingAs($admin)->getJson(route('roles.edit', $customer))->json('name_locked'));
    }

    public function test_the_customer_roles_permissions_can_still_be_edited(): void
    {
        $this->seedPermissions();
        $customer = $this->roleId(Role::CUSTOMER);
        $ids = $this->permissionIds('view menu');

        $this->actingAs($this->superAdmin())
            ->putJson(route('roles.update', $customer), ['name' => Role::CUSTOMER, 'permissions' => $ids])
            ->assertOk();

        $this->assertSame($ids, Role::permissionIds($customer));
    }

    public function test_a_super_admin_can_give_any_permission(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('roles.store'), ['name' => 'Everything', 'permissions' => $this->permissionIds(...Role::PERMISSIONS)])
            ->assertOk();
    }

    public function test_the_roles_page_offers_only_what_the_actor_may_do(): void
    {
        $actor = $this->userWith(...self::ROLE_MANAGER);
        $cashier = $this->role('Cashier', 'verify payments');
        $helper = $this->role('Helper', 'view roles');
        $customer = $this->roleId(Role::CUSTOMER);

        $rows = $this->rows($actor, 'roles.index');

        $this->assertStringNotContainsString('data-row-action="edit"', $rows[$cashier]['action']);
        $this->assertStringNotContainsString('data-row-action="delete"', $rows[$cashier]['action']);
        $this->assertStringContainsString('data-row-action="edit"', $rows[$helper]['action']);
        $this->assertStringContainsString('data-row-action="delete"', $rows[$helper]['action']);
        // Customer: editable (every ordering permission counts as held), never deletable.
        $this->assertStringContainsString('data-row-action="edit"', $rows[$customer]['action']);
        $this->assertStringNotContainsString('data-row-action="delete"', $rows[$customer]['action']);

        $page = $this->actingAs($actor)->get(route('roles.index'))->assertOk();
        $locked = $page->viewData('lockedPermissionIds');

        // The chip itself is rendered disabled.
        $page->assertSee('value="' . $this->permissionIds('verify payments')[0] . '" class="peer sr-only" disabled', false);

        $this->assertContains($this->permissionIds('verify payments')[0], $locked);
        $this->assertNotContains($this->permissionIds('view roles')[0], $locked);
        $this->assertNotContains($this->permissionIds('create orders')[0], $locked);

        $this->assertSame([], $this->actingAs($this->superAdmin())->get(route('roles.index'))->viewData('lockedPermissionIds'));
    }

    /* -------- the edit record (S18) -------- */

    public function test_the_edit_record_carries_no_secrets(): void
    {
        $target = User::factory()->create(['password' => Hash::make('secret-pass')]);
        DB::table('users')->where('id', $target->id)->update(['remember_token' => 'tok-123', 'google_id' => '999']);

        $record = $this->actingAs($this->superAdmin())->getJson(route('users.edit', $target->id))->assertOk()->json();

        foreach (['password', 'remember_token', 'google_id'] as $key) {
            $this->assertArrayNotHasKey($key, $record);
        }

        $this->assertSame($target->email, $record['email']);
    }
}
