<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// The accounts the app starts with. Their Super Admin role is attached by
// PermissionSeeder (see PermissionSeeder::SUPER_ADMIN_EMAILS), so this seeder must
// run before it. Re-running keeps an existing account as it is: its password may
// have been changed in the app.
//
// SEED_USER_PASSWORD in .env replaces the default password below, which is meant
// for a development database only.
class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'jleoegamen6@gmail.com'],
            [
                'first_name' => 'Jan Leo',
                'last_name' => 'Egamen',
                // The model casts the password to "hashed".
                'password' => env('SEED_USER_PASSWORD') ?: 'Password123!',
                'profile_completed_at' => now(),
            ]
        );
    }
}
