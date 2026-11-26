<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// The insight, planning and customer widgets and the analytics export are new permissions. On a
// database that already has roles: the three personal dashboard widgets go to every role that can
// open the pages they are about; everything else goes to Super Admin only (customer names, money
// and exports are the administrator's to hand out). Nobody has to rerun PermissionSeeder. A fresh
// database has no roles yet: nothing happens and the seeder does the rest.
return new class extends Migration
{
    // widget permission => the permissions whose roles get it.
    private const BY_PAGE = [
        'view dashboard-my-sellout-alert' => ['view my orders'],
        'view dashboard-my-new-dishes' => ['view my orders'],
        'view dashboard-my-discounts' => ['view menu', 'view pre-order-menu'],
    ];

    private const STAFF = [
        'view dashboard-insights',
        'view dashboard-security',
        'view analytics-order-times',
        'view analytics-discounts',
        'view analytics-review-time',
        'view analytics-prep-suggestions',
        'view analytics-weekday-demand',
        'view analytics-bring-back',
        'view analytics-menu-fatigue',
        'view analytics-new-dish-performance',
        'view analytics-pairings',
        'view analytics-customers',
        'view analytics-repeat-rate',
        'view analytics-never-ordered',
        'view analytics-companies',
        'view analytics-company-participation',
        'view analytics-lapsed-customers',
        'view analytics-top-customers',
        'export analytics',
    ];

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
        $superAdmins = DB::table('roles')->where('name', 'Super Admin')->where('guard_name', 'web')->pluck('id');
        $rows = [];

        foreach ($names as $name) {
            $roleIds = $superAdmins;

            foreach (self::BY_PAGE[$name] ?? [] as $page) {
                if (isset($ids[$page])) {
                    $roleIds = $roleIds->merge(DB::table('role_has_permissions')->where('permission_id', $ids[$page])->pluck('role_id'));
                }
            }

            foreach ($roleIds->unique() as $roleId) {
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
            ->where('guard_name', 'web')
            ->whereIn('name', [...array_keys(self::BY_PAGE), ...self::STAFF])
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
