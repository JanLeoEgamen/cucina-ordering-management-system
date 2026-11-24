<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The staff Orders and Payments tables are filtered, sorted and searched by the database now (F22), by
// the order's / payment's status and date and by the dishes of an order. Without these a filter or a sort
// reads the whole table.
//
// Only what is missing is added: MySQL already indexes `order_items.order_id` (its foreign key), SQLite
// does not, which made a dish search on 5,000 orders take 3 s there.
return new class extends Migration
{
    private const INDEXES = [
        'orders' => ['order_date', 'order_status', 'payment_status'],
        'payments' => ['created_at', 'payment_status'],
        'order_items' => ['order_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    // Skipped when an index on exactly that column is already there.
                    if (! Schema::hasIndex($table, [$column])) {
                        $blueprint->index($column, $table . '_' . $column . '_index');
                    }
                }
            });
        }
    }

    // Drops only the indexes THIS migration made (by name): never a foreign key's own index, which
    // MySQL needs and which was there before.
    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
                foreach ($columns as $column) {
                    $name = $table . '_' . $column . '_index';

                    if (Schema::hasIndex($table, $name)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
    }
};
