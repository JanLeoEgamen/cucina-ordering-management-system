<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two indexes the dashboard and Analytics need as the order history grows. Measured on MariaDB with
// 31,000 orders (same rows before and after):
//   payments(verified_at)       money collected today 73 ms -> 0.6 ms, 30 days of collections 42 -> 4 ms
//   orders(user_id, order_date) "who ordered in this period" 31 -> 6 ms, one month's repeat rate 26 -> 9 ms
// Only what is missing is added. On MySQL / MariaDB the second one covers `user_id`, so the server
// drops the foreign key's own index in favour of it: down() puts a plain one back BEFORE dropping it,
// because the foreign key cannot be left without an index.
return new class extends Migration
{
    private const PAYMENTS = 'payments_verified_at_index';

    private const ORDERS = 'orders_user_id_order_date_index';

    private const ORDERS_USER = 'orders_user_id_index';

    public function up(): void
    {
        if (! Schema::hasIndex('payments', ['verified_at'])) {
            Schema::table('payments', fn (Blueprint $table) => $table->index('verified_at', self::PAYMENTS));
        }

        if (! Schema::hasIndex('orders', ['user_id', 'order_date'])) {
            Schema::table('orders', fn (Blueprint $table) => $table->index(['user_id', 'order_date'], self::ORDERS));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('payments', self::PAYMENTS)) {
            Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(self::PAYMENTS));
        }

        if (! Schema::hasIndex('orders', self::ORDERS)) {
            return;
        }

        if (! Schema::hasIndex('orders', ['user_id']) && ! Schema::hasIndex('orders', 'orders_user_id_foreign')) {
            Schema::table('orders', fn (Blueprint $table) => $table->index('user_id', self::ORDERS_USER));
        }

        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(self::ORDERS));
    }
};
