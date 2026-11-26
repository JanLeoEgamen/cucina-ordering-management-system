<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The accounts the app starts with, both Super Admin:
//   - the owner, from SEED_OWNER_EMAIL / SEED_OWNER_PASSWORD (config/seed.php);
//   - test@example.com / password, ONLY when APP_ENV is local or testing.
//
// Super Admin is given only to an account this seeder has just created. An address that
// already has an account is left exactly as it is: registration does not prove who owns
// an email, so having a known one must never be enough to be promoted. To promote an
// existing account, a Super Admin does it on the Users page (database.md has the console
// way for a database that has no Super Admin).
class UserSeeder extends Seeder
{
    // A throwaway developer login. Never on a server: its password is public.
    public const DEVELOPMENT_EMAIL = 'test@example.com';

    public function run(): void
    {
        // The role must exist. Only when it is missing: running PermissionSeeder again would
        // put the Customer role back to its default permissions.
        if (! DB::table('roles')->where('name', Role::SUPER_ADMIN)->where('guard_name', 'web')->exists()) {
            $this->call(PermissionSeeder::class);
        }

        if (app()->environment(['local', 'testing'])) {
            $this->createSuperAdmin(self::DEVELOPMENT_EMAIL, 'Test', 'User', 'password');
        }

        $this->createOwner();
    }

    private function createOwner(): void
    {
        $email = Str::lower(trim((string) config('seed.owner.email')));

        if ($email === '') {
            $this->command?->warn('SEED_OWNER_EMAIL is not set: no owner account was created.');

            return;
        }

        $password = (string) config('seed.owner.password');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20);
        }

        $created = $this->createSuperAdmin($email, config('seed.owner.first_name'), config('seed.owner.last_name'), $password);

        if (! $created) {
            $this->command?->warn("An account for {$email} already exists: left as it is (no new password, no role).");
        } elseif ($generated) {
            $this->command?->warn("Owner {$email} created with password: {$password} (shown once; set SEED_OWNER_PASSWORD in .env to choose it)");
        }
    }

    // Create the account and make it Super Admin. False, and nothing changed, when the
    // address is taken (a deleted account too: users.email is unique including those).
    private function createSuperAdmin(string $email, string $firstName, string $lastName, string $password): bool
    {
        if (User::withTrashed()->where('email', $email)->exists()) {
            return false;
        }

        // The model casts the password to "hashed".
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
            'profile_completed_at' => now(),
        ]);

        $user->assignRole(Role::SUPER_ADMIN);

        return true;
    }
}
