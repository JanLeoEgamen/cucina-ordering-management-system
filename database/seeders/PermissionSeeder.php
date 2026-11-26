<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    // Seed the permissions the app checks (App\Models\Role::PERMISSIONS) and the
    // two roles that come with the app: Super Admin (everything) and Customer
    // (ordering only, see Role::CUSTOMER_PERMISSIONS). Run it again after adding a
    // permission: the seeder is repeatable, and it re-syncs both roles each time.
    // It gives NO account a role: UserSeeder does, for the accounts it creates.
    public function run(): void
    {
        $now = now();

        foreach (Role::PERMISSIONS as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $guard = app(PermissionRegistrar::class);

        // The role rows are written with the query builder, so the package's
        // in-memory cache has to be dropped before it is read back.
        $guard->forgetCachedPermissions();

        $roleId = DB::table('roles')
            ->where('name', Role::SUPER_ADMIN)
            ->where('guard_name', 'web')
            ->value('id');

        if (! $roleId) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => Role::SUPER_ADMIN,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Role::syncRolePermissions((int) $roleId, DB::table('permissions')->pluck('id')->all());

        // The Customer role: the ordering side of the app only. It is editable
        // from the roles page like any other role the app did not lock.
        $customerId = DB::table('roles')
            ->where('name', Role::CUSTOMER)
            ->where('guard_name', 'web')
            ->value('id');

        if (! $customerId) {
            $customerId = (int) DB::table('roles')->insertGetId([
                'name' => Role::CUSTOMER,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Role::syncRolePermissions((int) $customerId, DB::table('permissions')
            ->whereIn('name', Role::CUSTOMER_PERMISSIONS)
            ->pluck('id')
            ->all());

        // The pivots were written by hand, so the cache is dropped once more.
        $guard->forgetCachedPermissions();
    }
}
