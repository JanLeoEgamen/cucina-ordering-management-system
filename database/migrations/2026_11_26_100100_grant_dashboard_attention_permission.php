<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

// The dashboard's pending payments card used to show to everyone with `view payments`. It is now
// the `attention` widget with its own permission, so every role that saw it is given that
// permission here and nobody loses the card on an upgrade (and nobody has to rerun
// PermissionSeeder, which would also put the Customer role back to its defaults).
// A fresh database has no roles yet: nothing happens and the seeder does the rest.
return new class extends Migration
{
    private const NEW = 'view dashboard-attention';

    private const HAD_IT = 'view payments';

    public function up(): void
    {
        $source = DB::table('permissions')->where('name', self::HAD_IT)->where('guard_name', 'web')->value('id');
        $roleIds = $source
            ? DB::table('role_has_permissions')->where('permission_id', $source)->pluck('role_id')
            : collect();

        if ($roleIds->isEmpty()) {
            return;
        }

        $now = now();

        DB::table('permissions')->insertOrIgnore([
            'name' => self::NEW,
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('permissions')->where('name', self::NEW)->where('guard_name', 'web')->value('id');

        DB::table('role_has_permissions')->insertOrIgnore(
            $roleIds->map(fn ($roleId) => ['permission_id' => $permissionId, 'role_id' => $roleId])->all()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', self::NEW)->where('guard_name', 'web')->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
