<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Permissions and the Super Admin / Customer roles, then the first accounts (the owner
        // from .env, and the developer login on a local setup).
        $this->call(PermissionSeeder::class);
        $this->call(UserSeeder::class);

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

        // The companies people pick at registration (the Company dropdown is required).
        $this->call(CompanySeeder::class);

        // The menu the app starts with: the real dishes and their types, from
        // database/seeders/data/menu.csv. (DishTypeSeeder / DishSeeder are the old
        // demo menu and still run on their own with --class.)
        $this->call(MenuSeeder::class);
    }
}
