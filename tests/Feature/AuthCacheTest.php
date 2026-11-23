<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\TableCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

// The signed-in user (with roles and permissions) is served from the cache (AuthCache), so the
// danger is a STALE one: someone who lost a permission, a role or their account still getting in.
// These requests go through the real session guard (not actingAs, which skips the provider), each
// as a fresh request: the guard is forgotten in between.
class AuthCacheTest extends TestCase
{
    use RefreshDatabase;

    private function asSession(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withSession([Auth::guard('web')->getName() => $user->id]);
    }

    private function listRoles(User $user)
    {
        return $this->asSession($user)->getJson(route('roles.index'));
    }

    // Statements that read who the person is (the tables AuthCache covers).
    private function identityQueries(callable $request): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (preg_match('/from\s+[`"](users|model_has_roles|model_has_permissions)[`"]/i', $query->sql)) {
                $count++;
            }
        });

        $request();

        return $count;
    }

    public function test_a_second_request_identifies_the_user_without_a_query(): void
    {
        $user = $this->superAdmin();
        TableCache::settle(); // the setup writes are final, so the entry cached next is not versioned again
        $this->listRoles($user)->assertOk(); // fills the cache

        $this->assertSame(0, $this->identityQueries(fn () => $this->listRoles($user)->assertOk()));
    }

    public function test_a_role_taken_away_is_felt_on_the_very_next_request(): void
    {
        $user = $this->superAdmin();
        $this->listRoles($user)->assertOk();

        DB::table('model_has_roles')->where('model_id', $user->id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->listRoles($user)->assertForbidden();
    }

    public function test_a_direct_permission_taken_away_is_felt_at_once(): void
    {
        $user = $this->userWith('view roles');
        $this->listRoles($user)->assertOk();

        $user->revokePermissionTo('view roles');

        $this->listRoles($user)->assertForbidden();
    }

    public function test_changing_what_a_role_may_do_reaches_everyone_holding_it(): void
    {
        $this->seedPermissions();
        $role = \Spatie\Permission\Models\Role::findByName(Role::SUPER_ADMIN, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->listRoles($user)->assertOk();

        Role::syncRolePermissions((int) $role->id, []);

        $this->listRoles($user)->assertForbidden();
    }

    public function test_a_deleted_account_is_signed_out_at_once(): void
    {
        $user = $this->userWith('view roles');
        $this->listRoles($user)->assertOk();

        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);

        $this->listRoles($user)->assertUnauthorized();
    }

    public function test_a_ttl_of_zero_reads_the_database_every_time(): void
    {
        config(['auth.cache_ttl' => 0]);

        $user = $this->superAdmin();
        $this->listRoles($user)->assertOk();

        $this->assertGreaterThan(0, $this->identityQueries(fn () => $this->listRoles($user)->assertOk()));
    }
}
