<?php

// A fresh temporary database with what the browser tests need, written once per run. The ids
// and names go to fixtures.json, which the specs read.

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PreOrderSession;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$app = require __DIR__ . '/app.php';
$app->make(Kernel::class)->bootstrap();

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

$adminId = (int) User::where('email', 'test@example.com')->value('id');

$person = function (string $email, string $firstName, string $role) {
    $user = User::create([
        'first_name' => $firstName,
        'last_name' => 'Tester',
        'email' => $email,
        'password' => 'password',
        'profile_completed_at' => now(),
    ]);
    $user->assignRole($role);
};

$permissionIds = fn (array $names) => DB::table('permissions')->whereIn('name', $names)->pluck('id')->all();

Role::createRole(['name' => 'User manager'], $permissionIds([
    'view users', 'create users', 'edit users', 'delete users',
    'view roles', 'create roles', 'edit roles', 'delete roles',
]));
Role::createRole(['name' => 'Cashier'], $permissionIds(['verify payments']));

$person('customer@example.com', 'Casey', Role::CUSTOMER);
$person('manager@example.com', 'Morgan', 'User manager');

// Two dishes with known prices: one comes with rice, one does not.
[$riceId, $plainId] = Dish::query()->where('is_active', true)->orderBy('dish_id')->limit(2)->pluck('dish_id')->map(fn ($id) => (int) $id)->all();

DB::table('dishes')->where('dish_id', $riceId)->update(['dish_name' => 'Browser Adobo', 'price' => 100, 'includes_rice' => true]);
DB::table('dishes')->where('dish_id', $plainId)->update(['dish_name' => 'Browser Soup', 'price' => 80, 'includes_rice' => false]);

Setting::setAlaCarte('both', 20);
Setting::setPreOrderLimits(1, null);

$window = ['created_by' => $adminId, 'order_date' => now()->toDateString(), 'start_time' => '00:00:00', 'end_time' => '23:59:59'];

$sessionId = OrderSession::createSession($window, [$riceId => 20, $plainId => 20]);
$preSessionId = PreOrderSession::createSession($window + ['title' => 'Browser pre-order', 'min_quantity' => 1], [$riceId, $plainId]);

$line = fn (string $table, string $key, string $sessionKey, int $session, int $dishId) => (int) DB::table($table)
    ->where($sessionKey, $session)->where('dish_id', $dishId)->value($key);

file_put_contents(getenv('BROWSER_TEST_DIR') . '/fixtures.json', json_encode([
    'menu' => [
        'rice' => $line('session_dishes', 'session_dish_id', 'session_id', $sessionId, $riceId),
        'plain' => $line('session_dishes', 'session_dish_id', 'session_id', $sessionId, $plainId),
    ],
    'preOrder' => [
        'rice' => $line('pre_order_session_dishes', 'pre_order_session_dish_id', 'pre_order_session_id', $preSessionId, $riceId),
        'plain' => $line('pre_order_session_dishes', 'pre_order_session_dish_id', 'pre_order_session_id', $preSessionId, $plainId),
    ],
    'roles' => DB::table('roles')->pluck('id', 'name'),
    'users' => DB::table('users')->pluck('id', 'email'),
    'permissions' => DB::table('permissions')->pluck('id', 'name'),
], JSON_PRETTY_PRINT));

// This script ends without the app's usual shutdown, which is what finishes the cache versions.
App\Support\TableCache::settle();

fwrite(fopen('php://stdout', 'w'), "Browser test data is ready.\n");
