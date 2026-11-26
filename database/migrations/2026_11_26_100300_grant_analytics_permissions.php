<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// The Analytics page's permissions. On a database that already has roles they go to Super Admin
// only (sales and money are the administrator's to hand out, on the Roles page), so nobody has to
// rerun PermissionSeeder. A fresh database has no roles yet: nothing happens, the seeder does it.
return new class extends Migration
{
    private const PERMISSIONS = [
        'view analytics-sales-summary',
        'view analytics-sales-trend',
        'view analytics-order-status',
        'view analytics-payment-methods',
        'view analytics-basket-size',
        'view analytics-cancelled-value',
        'view analytics-collections',
        'view analytics-duplicate-references',
        'view analytics-money-held',
        'view analytics-dish-sales',
        'view analytics-sell-through',
        'view analytics-sellout-speed',
        'view analytics-rice',
        'view analytics-session-scorecard',
    ];

    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }

        $now = now();

        DB::table('permissions')->insertOrIgnore(array_map(fn (string $name) => [
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ], self::PERMISSIONS));

        $superAdmin = DB::table('roles')->where('name', 'Super Admin')->where('guard_name', 'web')->value('id');

        if ($superAdmin) {
            DB::table('role_has_permissions')->insertOrIgnore(
                DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id')
                    ->map(fn ($id) => ['permission_id' => $id, 'role_id' => $superAdmin])->all()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
