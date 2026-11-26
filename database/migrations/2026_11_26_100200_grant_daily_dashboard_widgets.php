<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// The daily-use dashboard widgets are new permissions. On a database that already has roles they
// are handed out here, so nobody has to rerun PermissionSeeder (which would put the Customer role
// back to its defaults):
//   - the personal widgets go to every role that can open the pages they are about,
//   - the staff widgets go to Super Admin only: who else sees money or system checks is the
//     administrator's choice, made on the Roles page.
// A fresh database has no roles yet: nothing happens and the seeder does the rest.
return new class extends Migration
{
    // widget permission => the permission whose roles get it.
    private const BY_PAGE = [
        'view dashboard-my-orders' => 'view my orders',
        'view dashboard-my-pickup' => 'view my orders',
        'view dashboard-my-usual' => 'view my orders',
        'view dashboard-my-favorites' => 'view my orders',
        'view dashboard-my-spending' => 'view my orders',
        'view dashboard-popular-dishes' => 'view menu',
    ];

    private const STAFF = [
        'view dashboard-today',
        'view dashboard-live-session',
        'view dashboard-cash-expected',
        'view dashboard-unclaimed',
        'view dashboard-system-health',
        'view dashboard-setup-checklist',
    ];

    private const SUPER_ADMIN = 'Super Admin';

    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }

        $now = now();
        $names = [...array_keys(self::BY_PAGE), ...self::STAFF];

        DB::table('permissions')->insertOrIgnore(array_map(fn (string $name) => [
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ], $names));

        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');
        $superAdmins = DB::table('roles')->where('name', self::SUPER_ADMIN)->where('guard_name', 'web')->pluck('id');
        $rows = [];

        foreach ($names as $name) {
            $roleIds = $superAdmins;

            if (isset(self::BY_PAGE[$name], $ids[self::BY_PAGE[$name]])) {
                $roleIds = $roleIds->merge(
                    DB::table('role_has_permissions')->where('permission_id', $ids[self::BY_PAGE[$name]])->pluck('role_id')
                )->unique();
            }

            foreach ($roleIds as $roleId) {
                $rows[] = ['permission_id' => $ids[$name], 'role_id' => $roleId];
            }
        }

        if ($rows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', [...array_keys(self::BY_PAGE), ...self::STAFF])
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
