<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['first_name' => 'Test', 'last_name' => 'User', 'password' => bcrypt('password'), 'profile_completed_at' => now()]
        );

        // The admin account of the owner. The password is never kept in the repo:
        // it is read from SEED_ADMIN_PASSWORD in .env, or generated and shown once
        // when that is empty. An existing account is left as it is (its password
        // may have been changed in the app).
        $admin = User::firstOrCreate(
            ['email' => 'jbegamen@bounty.com.ph'],
            [
                'first_name' => 'Admin',
                'last_name' => 'Account',
                'password' => bcrypt($password = env('SEED_ADMIN_PASSWORD') ?: Str::random(16)),
                'profile_completed_at' => now(),
            ]
        );

        if ($admin->wasRecentlyCreated && ! env('SEED_ADMIN_PASSWORD')) {
            $this->command?->warn('Admin jbegamen@bounty.com.ph created with password: ' . $password . ' (shown once; set SEED_ADMIN_PASSWORD in .env to choose it)');
        }

        // Jan Leo Egamen's account (UserSeeder), before the permissions so that
        // PermissionSeeder can give it the Super Admin role.
        $this->call(UserSeeder::class);

        // Permissions, and the Super Admin role on the accounts above: without
        // it every gated page answers 403.
        $this->call(PermissionSeeder::class);

        // The methods the checkout offers. Without one there is nothing to pick
        // at checkout, so an order cannot be placed. Cash on Pick up is the only
        // cash method; it is locked by PaymentMethod::PROTECTED: it is only ever
        // used, never edited or deleted.
        $methods = [
            ['method_name' => PaymentMethod::CASH_ON_PICKUP, 'account_name' => null, 'account_number' => null],
            ['method_name' => 'GCash', 'account_name' => 'Cucina Kitchen', 'account_number' => '0917-000-0000'],
            ['method_name' => 'Bank Transfer', 'account_name' => 'Cucina Kitchen', 'account_number' => '1234-5678-9012'],
        ];

        foreach ($methods as $method) {
            PaymentMethod::firstOrCreate(['method_name' => $method['method_name']], $method);
        }

        // The menu the app starts with: the real dishes and their types, from
        // database/seeders/data/menu.csv. (DishTypeSeeder / DishSeeder are the old
        // demo menu and still run on their own with --class.)
        $this->call(MenuSeeder::class);
    }
}
