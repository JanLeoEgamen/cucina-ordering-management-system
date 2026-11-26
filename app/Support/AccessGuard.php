<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

// The one rule about handing out access (security.md, "Privilege rules"): nobody gives,
// changes or takes away more than they have themselves. A Super Admin is exempt.
//
// The ordering permissions (Role::CUSTOMER_PERMISSIONS) count as held by everyone: anyone
// gets them by registering, and without this a user manager who does not order could not
// manage a customer.
//
// UserController and RoleController ask it before a write; the table buttons and the pickers
// ask it too, but only so they do not offer what the server would refuse.
class AccessGuard
{
    private function __construct(private readonly bool $superAdmin, private readonly array $held) {}

    public static function for(User $actor): self
    {
        return new self(
            $actor->hasRole(Role::SUPER_ADMIN),
            array_flip([...$actor->getAllPermissions()->pluck('name')->all(), ...Role::CUSTOMER_PERMISSIONS]),
        );
    }

    // May the actor hand out every one of these permissions (by name)?
    public function coversPermissions(array $permissionNames): bool
    {
        if ($this->superAdmin) {
            return true;
        }

        foreach ($permissionNames as $name) {
            if (! isset($this->held[$name])) {
                return false;
            }
        }

        return true;
    }

    // May the actor give this role to someone, or edit / delete it? Super Admin is for a
    // Super Admin only, even for someone who happens to hold every permission. An unknown
    // role is never covered.
    public function coversRole(int $roleId): bool
    {
        $role = Role::accessById()[$roleId] ?? null;

        if ($role === null) {
            return false;
        }

        if ($role['name'] === Role::SUPER_ADMIN) {
            return $this->superAdmin;
        }

        return $this->coversPermissions($role['permissions']);
    }

    // May the actor change or delete this account? Only when it has no access the actor does
    // not: otherwise setting its password or email would be a way to sign in as it.
    public function coversUser(int $userId): bool
    {
        if ($this->superAdmin) {
            return true;
        }

        foreach (User::roleIdMap()[$userId] ?? [] as $roleId) {
            if (! $this->coversRole($roleId)) {
                return false;
            }
        }

        // Permissions given to the account itself, outside any role.
        return $this->coversPermissions(User::directPermissionMap()[$userId] ?? []);
    }

    // The role choices ([id, name]) the actor may hand out, for the user form's picker.
    public function grantableRoles(array $roleOptions): array
    {
        return array_values(array_filter($roleOptions, fn (array $role) => $this->coversRole($role['id'])));
    }

    // Ids of the permissions the actor may not hand out: the role picker disables them.
    public function lockedPermissionIds(): array
    {
        return array_keys(array_filter(
            Role::permissionNamesById(),
            fn (string $name) => ! $this->coversPermissions([$name]),
        ));
    }
}
