<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

// "Bulk orders" became "Pre-orders". The four original create migrations stay as they were
// applied; this one moves the data: tables, their columns, the permission names (so every
// role keeps what it had), the settings keys and the order-number prefix.
return new class extends Migration
{
    private const TABLES = [
        'bulk_sessions' => 'pre_order_sessions',
        'bulk_session_dishes' => 'pre_order_session_dishes',
        'bulk_orders' => 'pre_orders',
        'bulk_order_items' => 'pre_order_items',
    ];

    // table (new name) => [old column => new column]
    private const COLUMNS = [
        'pre_order_sessions' => ['bulk_session_id' => 'pre_order_session_id'],
        'pre_order_session_dishes' => [
            'bulk_session_dish_id' => 'pre_order_session_dish_id',
            'bulk_session_id' => 'pre_order_session_id',
        ],
        'pre_orders' => [
            'bulk_order_id' => 'pre_order_id',
            'bulk_session_id' => 'pre_order_session_id',
        ],
        'pre_order_items' => [
            'bulk_order_item_id' => 'pre_order_item_id',
            'bulk_order_id' => 'pre_order_id',
            'bulk_session_dish_id' => 'pre_order_session_dish_id',
        ],
    ];

    // Indexes whose auto-generated name carried the old words: table (new) => [old => new].
    private const INDEXES = [
        'pre_order_session_dishes' => [
            'bulk_session_dishes_bulk_session_id_dish_id_unique' => 'pre_order_session_dishes_pre_order_session_id_dish_id_unique',
        ],
        'pre_orders' => [
            'bulk_orders_order_number_unique' => 'pre_orders_order_number_unique',
        ],
    ];

    private const PERMISSIONS = [
        'view bulk-sessions' => 'view pre-order-sessions',
        'create bulk-sessions' => 'create pre-order-sessions',
        'edit bulk-sessions' => 'edit pre-order-sessions',
        'delete bulk-sessions' => 'delete pre-order-sessions',
        'end bulk-sessions' => 'end pre-order-sessions',
        'view bulk-menu' => 'view pre-order-menu',
        'create bulk-orders' => 'create pre-orders',
        'view my bulk-orders' => 'view my pre-orders',
        'view bulk-orders' => 'view pre-orders',
        'complete bulk-orders' => 'complete pre-orders',
        'cancel bulk-orders' => 'cancel pre-orders',
        'verify bulk-payments' => 'verify pre-order-payments',
        'reject bulk-payments' => 'reject pre-order-payments',
        'undo bulk-payments' => 'undo pre-order-payments',
    ];

    private const SETTINGS = [
        'bulk_orders.min_quantity' => 'pre_orders.min_quantity',
        'bulk_orders.max_quantity' => 'pre_orders.max_quantity',
    ];

    public function up(): void
    {
        $this->move(self::TABLES, self::COLUMNS, self::INDEXES, self::PERMISSIONS, self::SETTINGS, 'BLK-', 'PRE-');
    }

    public function down(): void
    {
        $this->move(
            array_flip(self::TABLES),
            $this->flipColumns(),
            $this->flipIndexes(),
            array_flip(self::PERMISSIONS),
            array_flip(self::SETTINGS),
            'PRE-',
            'BLK-',
            true
        );
    }

    private function move(array $tables, array $columns, array $indexes, array $permissions, array $settings, string $fromPrefix, string $toPrefix, bool $reverse = false): void
    {
        // A table rename keeps the foreign keys pointing at the renamed table, so the order does not matter.
        foreach ($tables as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }

        foreach ($columns as $table => $pairs) {
            // Going up the table is already under its new name; going down it is under the old one.
            $name = $reverse ? ($tables[$table] ?? $table) : $table;

            foreach ($pairs as $from => $to) {
                if (Schema::hasTable($name) && Schema::hasColumn($name, $from) && ! Schema::hasColumn($name, $to)) {
                    Schema::table($name, function (Blueprint $t) use ($from, $to) {
                        $t->renameColumn($from, $to);
                    });
                }
            }
        }

        foreach ($indexes as $table => $pairs) {
            $name = $reverse ? ($tables[$table] ?? $table) : $table;

            foreach ($pairs as $from => $to) {
                if (! Schema::hasTable($name)) {
                    continue;
                }

                // `RENAME INDEX` does not exist on older MariaDB, so drop the (unique) index
                // and make it again under the new name, on the same columns.
                $index = collect(Schema::getIndexes($name))->firstWhere('name', $from);
                if ($index === null || ! Schema::hasIndex($name, $from)) {
                    continue;
                }

                Schema::table($name, function (Blueprint $t) use ($from, $to, $index) {
                    $t->dropUnique($from);
                    $t->unique($index['columns'], $to);
                });
            }
        }

        if (Schema::hasTable('permissions')) {
            foreach ($permissions as $from => $to) {
                DB::table('permissions')->where('name', $from)->update(['name' => $to]);
            }
        }

        if (Schema::hasTable('settings')) {
            foreach ($settings as $from => $to) {
                DB::table('settings')->where('key', $from)->update(['key' => $to]);
            }
        }

        // Done row by row: string concatenation differs between SQLite (||) and MySQL (CONCAT).
        $orders = $reverse ? 'bulk_orders' : 'pre_orders';
        $key = $reverse ? 'bulk_order_id' : 'pre_order_id';
        if (Schema::hasTable($orders)) {
            foreach (DB::table($orders)->where('order_number', 'like', $fromPrefix . '%')->get([$key, 'order_number']) as $row) {
                DB::table($orders)->where($key, $row->$key)
                    ->update(['order_number' => $toPrefix . substr($row->order_number, strlen($fromPrefix))]);
            }
        }

        // The roles' permissions are cached by name: forget them.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function flipColumns(): array
    {
        $flipped = [];
        foreach (self::COLUMNS as $table => $pairs) {
            $flipped[$table] = array_flip($pairs);
        }

        return $flipped;
    }

    private function flipIndexes(): array
    {
        $flipped = [];
        foreach (self::INDEXES as $table => $pairs) {
            $flipped[$table] = array_flip($pairs);
        }

        return $flipped;
    }
};
