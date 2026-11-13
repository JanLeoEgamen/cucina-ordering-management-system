<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // The permission list is seeded once per test, not once per call.
    private bool $permissionsSeeded = false;

    // Create the 16 permissions the app checks (and the Super Admin role).
    protected function seedPermissions(): void
    {
        if ($this->permissionsSeeded) {
            return;
        }

        $this->seed(PermissionSeeder::class);

        $this->permissionsSeeded = true;
    }

    // A user holding every permission. The CRUD routes are gated with
    // `permission:<name>`, so a plain factory user only ever gets 403.
    protected function superAdmin(): User
    {
        $this->seedPermissions();

        $user = User::factory()->create();
        $user->assignRole(Role::SUPER_ADMIN);

        return $user;
    }

    // A user holding exactly the given permissions, for the 403 cases.
    protected function userWith(string ...$permissions): User
    {
        $this->seedPermissions();

        $user = User::factory()->create();

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        return $user;
    }
}
