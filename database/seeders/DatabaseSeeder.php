<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['first_name' => 'Test', 'last_name' => 'User', 'password' => bcrypt('password')]
        );

        // Permissions, and the Super Admin role on the account above: without
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
