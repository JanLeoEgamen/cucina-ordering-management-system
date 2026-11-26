<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "When was it placed" must never change. orders.order_date and pre_orders.order_date were made
// with $table->timestamp('order_date'), a NOT NULL column with no default. With
// explicit_defaults_for_timestamp OFF (MariaDB before 10.10, e.g. XAMPP; old MySQL) the server
// silently gives the first such column "ON UPDATE CURRENT_TIMESTAMP", so every later update of
// the row (verifying, completing, cancelling, announcing) moved the order to that moment.
//
// Only a column that really has that behaviour is touched (SQLite and a server with the setting
// on have nothing to fix). It becomes a plain DATETIME, and the moved dates are put back from
// created_at: the app writes both from the same moment and nothing rewrites created_at. As a
// second witness the day must also be the one printed in the order number (ORD-YYYYMMDD-NNNN);
// a row where it is not is left as it is.
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['orders', 'pre_orders'] as $table) {
            if (! $this->rewritesItself($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dateTime('order_date')->change();
            });

            DB::table($table)
                ->whereNotNull('created_at')
                ->whereColumn('order_date', '!=', 'created_at')
                ->whereRaw("SUBSTRING(order_number, 5, 8) = DATE_FORMAT(created_at, '%Y%m%d')")
                ->update(['order_date' => DB::raw('created_at')]);
        }
    }

    // Nothing to undo: putting the old column back would bring the bug back.
    public function down(): void
    {
    }

    private function rewritesItself(string $table): bool
    {
        $column = DB::selectOne(
            "SELECT EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'order_date'",
            [DB::getTablePrefix() . $table]
        );

        return $column !== null && str_contains(strtolower((string) $column->extra), 'on update');
    }
};
