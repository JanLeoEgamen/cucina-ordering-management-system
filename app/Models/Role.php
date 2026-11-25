<?php

namespace App\Models;

use App\Support\TableCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

// Roles and the permissions they hold. The rows are read and written with the
// query builder like the other CRUD models; the class still extends Spatie's
// Role so the users' `roles` relation and the permission checks keep working.
class Role extends SpatieRole
{
    // The permissions the app checks, in the order the picker lists them.
    public const PERMISSIONS = [
        'view dishes', 'create dishes', 'edit dishes', 'delete dishes',
        'view dish-types', 'create dish-types', 'edit dish-types', 'delete dish-types',
        'view payment-methods', 'create payment-methods', 'edit payment-methods', 'delete payment-methods',
        'view payments', 'verify payments', 'reject payments', 'undo payments', 'delete payments',
        'view order-sessions', 'create order-sessions', 'edit order-sessions',
        'delete order-sessions', 'end order-sessions',
        'view menu', 'create orders', 'view my orders', 'view orders', 'complete orders', 'cancel orders',
        'view pre-order-sessions', 'create pre-order-sessions', 'edit pre-order-sessions',
        'delete pre-order-sessions', 'end pre-order-sessions',
        'view pre-order-menu', 'create pre-orders', 'view my pre-orders',
        'view pre-orders', 'complete pre-orders', 'cancel pre-orders',
        'verify pre-order-payments', 'reject pre-order-payments', 'undo pre-order-payments',
        'view roles', 'create roles', 'edit roles', 'delete roles',
        'view users', 'create users', 'edit users', 'delete users',
        'view companies', 'create companies', 'edit companies', 'delete companies',
        'view discounts', 'create discounts', 'edit discounts', 'delete discounts', 'review discounts',
        'view settings', 'edit settings',
        'view audit-logs',
    ];

    // How the roles page groups the permissions: section => the resources in it
    // (the words after the action in a permission name). Only the picker's layout;
    // nothing is checked against it.
    public const PERMISSION_SECTIONS = [
        'Dish masterlist' => ['dishes', 'dish-types'],
        'Order sessions and orders' => ['order-sessions', 'menu', 'orders', 'my orders'],
        'Payments and discounts' => ['payments', 'payment-methods', 'discounts'],
        'Pre-orders' => [
            'pre-order-sessions', 'pre-order-menu', 'pre-orders', 'my pre-orders', 'pre-order-payments',
        ],
        'Administration' => ['roles', 'users', 'companies', 'settings', 'audit-logs'],
    ];

    // The seeded role that holds every permission.
    public const SUPER_ADMIN = 'Super Admin';

    // The seeded role for the people ordering: the menu, their own orders, and
    // placing one. Nothing behind the counter.
    public const CUSTOMER = 'Customer';

    // What the Customer role is seeded with. The roles page can change this
    // afterwards; running the seeder again puts it back to this list.
    public const CUSTOMER_PERMISSIONS = [
        'view menu',
        'create orders',
        'view my orders',
        'view pre-order-menu',
        'create pre-orders',
        'view my pre-orders',
    ];

    // Protected roles: they cannot be edited or deleted from the app. Add a
    // name here to lock another role, and remove it to unlock the role again.
    // Nothing in the UI can change this list.
    public const PROTECTED = [
        self::SUPER_ADMIN,
    ];

    // Table names, matching config/permission.php.
    protected const TABLE = 'roles';

    protected const PIVOT_ROLE = 'role_has_permissions';

    protected const PIVOT_USER = 'model_has_roles';

    protected const TABLE_PERMISSION = 'permissions';

    protected $table = self::TABLE;

    /* -------- Reads -------- */

    // Get every role with the number of permissions and users it has, for the feed.
    public static function getAll(): Collection
    {
        return TableCache::remember(['roles', 'role_has_permissions', 'model_has_roles'], 'role.all', fn () => self::loadAll());
    }

    // The query behind the cached method above.
    protected static function loadAll(): Collection
    {
        $permissions = DB::table(self::PIVOT_ROLE)
            ->selectRaw('count(*)')
            ->whereColumn('role_id', self::TABLE . '.id');

        $users = DB::table(self::PIVOT_USER)
            ->selectRaw('count(*)')
            ->whereColumn('role_id', self::TABLE . '.id');

        return DB::table(self::TABLE)
            ->select([self::TABLE . '.id', self::TABLE . '.name'])
            ->selectSub($permissions, 'permissions_count')
            ->selectSub($users, 'users_count')
            ->orderBy(self::TABLE . '.name')
            ->get()
            ->map(function (object $role) {
                // The feed and the drawers mark a protected role, so the table
                // can show it and the buttons can stay away.
                $role->is_protected = self::isProtectedName($role->name);

                return $role;
            });
    }

    // Get one role with its permissions and user count, for the drawers.
    // Named findOneById because the package's Role::findById() is taken.
    public static function findOneById(int $roleId): ?object
    {
        $role = DB::table(self::TABLE)->where('id', $roleId)->first();

        if (! $role) {
            return null;
        }

        // Ids tick the checkboxes in the edit drawer, names are printed by the
        // view drawer, so both travel with the record.
        $role->permissions = self::permissionIds($roleId);
        $role->permission_names = self::permissionNames($roleId);
        $role->permission_groups = self::groupsOf($role->permissions);
        $role->users_count = self::countUsers($roleId);
        $role->is_protected = self::isProtectedName($role->name);

        return $role;
    }

    // Check whether the role exists (before updating or deleting it).
    public static function existsById(int $roleId): bool
    {
        return DB::table(self::TABLE)->where('id', $roleId)->exists();
    }

    // Is this role one of the protected ones? Read from the table, so a renamed
    // row cannot keep a lock the list no longer grants.
    public static function isProtected(int $roleId): bool
    {
        $name = DB::table(self::TABLE)->where('id', $roleId)->value('name');

        return $name !== null && self::isProtectedName($name);
    }

    // Is this role name protected?
    public static function isProtectedName(string $name): bool
    {
        return in_array($name, self::PROTECTED, true);
    }

    // Check whether the role is still held by a live user.
    public static function isAssignedToUsers(int $roleId): bool
    {
        return DB::table(self::PIVOT_USER)
            ->join('users', 'users.id', '=', self::PIVOT_USER . '.model_id')
            ->where(self::PIVOT_USER . '.role_id', $roleId)
            ->where(self::PIVOT_USER . '.model_type', User::class)
            ->whereNull('users.deleted_at')
            ->exists();
    }

    // Ids of the role's permissions, for the checkboxes in the edit drawer.
    public static function permissionIds(int $roleId): array
    {
        return DB::table(self::PIVOT_ROLE)
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->map(fn ($permissionId) => (int) $permissionId)
            ->all();
    }

    // Full names of the role's permissions, for the view drawer.
    public static function permissionNames(int $roleId): array
    {
        return DB::table(self::PIVOT_ROLE)
            ->join(self::TABLE_PERMISSION, self::TABLE_PERMISSION . '.id', '=', self::PIVOT_ROLE . '.permission_id')
            ->where(self::PIVOT_ROLE . '.role_id', $roleId)
            ->orderBy(self::TABLE_PERMISSION . '.name')
            ->pluck(self::TABLE_PERMISSION . '.name')
            ->map(fn (string $name) => Str::headline($name))
            ->all();
    }

    // How many live users hold the role.
    public static function countUsers(int $roleId): int
    {
        return DB::table(self::PIVOT_USER)
            ->join('users', 'users.id', '=', self::PIVOT_USER . '.model_id')
            ->where(self::PIVOT_USER . '.role_id', $roleId)
            ->where(self::PIVOT_USER . '.model_type', User::class)
            ->whereNull('users.deleted_at')
            ->count();
    }

    // The permission picker's layout: sections (the areas of the app) holding one
    // row per resource ("Dishes", "Payments") with one checkbox per action
    // (View, Create, Edit ...). Built from the permission NAMES, "<action>
    // <resource>", so a new permission lands in its resource's row with no change
    // here; only a brand new resource has to be given a section in
    // PERMISSION_SECTIONS (until then it is listed under "Other").
    public static function permissionGroups(): array
    {
        return TableCache::remember(['permissions'], 'role.permission_groups', fn () => self::loadPermissionGroups());
    }

    // The query behind the cached method above.
    protected static function loadPermissionGroups(): array
    {
        $sectionOf = [];

        foreach (self::PERMISSION_SECTIONS as $section => $resources) {
            foreach ($resources as $resource) {
                $sectionOf[$resource] = $section;
            }
        }

        // section => resource => [permission, ...], in PERMISSIONS order.
        $tree = [];

        foreach (self::permissionOptionsRaw() as $permission) {
            [$action, $resource] = array_pad(explode(' ', $permission['name'], 2), 2, '');

            if ($resource === '') {
                [$action, $resource] = ['', $action];
            }

            $section = $sectionOf[$resource] ?? 'Other';

            $tree[$section][$resource][] = [
                'id' => $permission['id'],
                'action' => $action === '' ? Str::headline($resource) : Str::headline($action),
                'name' => Str::headline($permission['name']),
            ];
        }

        $groups = [];

        // Sections in the order of PERMISSION_SECTIONS, "Other" last.
        foreach ([...array_keys(self::PERMISSION_SECTIONS), 'Other'] as $section) {
            if (empty($tree[$section])) {
                continue;
            }

            $resources = [];

            // Rows in the order the section lists them, strangers after them.
            $order = array_flip(self::PERMISSION_SECTIONS[$section] ?? []);
            $names = array_keys($tree[$section]);
            usort($names, fn ($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

            foreach ($names as $resource) {
                $resources[] = [
                    'label' => Str::headline($resource),
                    'permissions' => $tree[$section][$resource],
                ];
            }

            $groups[] = ['label' => $section, 'resources' => $resources];
        }

        return $groups;
    }

    // permissionGroups() narrowed to the given permission ids: only the sections
    // and rows that hold one, each with only its granted actions. The view drawer
    // prints it as it is.
    public static function groupsOf(array $permissionIds): array
    {
        $granted = array_flip($permissionIds);
        $groups = [];

        foreach (self::permissionGroups() as $group) {
            $rows = [];

            foreach ($group['resources'] as $row) {
                $actions = array_values(array_filter($row['permissions'], fn (array $permission) => isset($granted[$permission['id']])));

                if ($actions) {
                    $rows[] = ['label' => $row['label'], 'permissions' => $actions];
                }
            }

            if ($rows) {
                $groups[] = ['label' => __($group['label']), 'resources' => $rows];
            }
        }

        return $groups;
    }

    // Every permission as [id, name] (the raw name), in PERMISSIONS order.
    protected static function permissionOptionsRaw(): array
    {
        return DB::table(self::TABLE_PERMISSION)
            ->get(['id', 'name'])
            ->sortBy(function (object $permission) {
                $position = array_search($permission->name, self::PERMISSIONS, true);

                return $position === false ? count(self::PERMISSIONS) : $position;
            })
            ->map(fn (object $permission) => ['id' => (int) $permission->id, 'name' => $permission->name])
            ->values()
            ->all();
    }

    // Every role as a choice for the user form's picker.
    public static function options(): array
    {
        return TableCache::remember(['roles'], 'role.options', fn () => self::loadOptions());
    }

    // The query behind the cached method above.
    protected static function loadOptions(): array
    {
        return DB::table(self::TABLE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (object $role) => ['id' => (int) $role->id, 'name' => $role->name])
            ->all();
    }

    /* -------- Writes -------- */

    // Create a role with its permissions and return its id.
    public static function createRole(array $attributes, array $permissionIds): int
    {
        $now = now();

        $roleId = (int) DB::table(self::TABLE)->insertGetId([
            'name' => $attributes['name'],
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::syncRolePermissions($roleId, $permissionIds);

        return $roleId;
    }

    // Rename a role and replace its permissions; returns the number of changed rows.
    public static function updateRole(int $roleId, array $attributes, array $permissionIds): int
    {
        $changed = DB::table(self::TABLE)
            ->where('id', $roleId)
            ->update(['name' => $attributes['name'], 'updated_at' => now()]);

        self::syncRolePermissions($roleId, $permissionIds);

        return $changed;
    }

    // Delete a role. The roles table has no soft delete, so the row goes; its
    // permission rows go with it.
    public static function deleteRole(int $roleId): bool
    {
        self::syncRolePermissions($roleId, []);

        return DB::table(self::TABLE)->where('id', $roleId)->delete() === 1;
    }

    // Replace a role's permissions: delete that role's rows, insert the posted ids.
    public static function syncRolePermissions(int $roleId, array $permissionIds): void
    {
        DB::table(self::PIVOT_ROLE)->where('role_id', $roleId)->delete();

        $rows = collect($permissionIds)
            ->map(fn ($permissionId) => (int) $permissionId)
            ->unique()
            ->map(fn (int $permissionId) => ['role_id' => $roleId, 'permission_id' => $permissionId])
            ->values()
            ->all();

        if ($rows) {
            DB::table(self::PIVOT_ROLE)->insert($rows);
        }

        // The permission map is cached, so every write has to drop it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}