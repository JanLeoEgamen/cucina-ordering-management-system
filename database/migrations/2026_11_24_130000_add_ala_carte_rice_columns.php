<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ala carte (a plate WITHOUT rice, for less):
//  - dishes.includes_rice: staff mark the dishes that normally come with rice. Off for every existing
//    dish, so nothing changes until staff tick them.
//  - order_items.no_rice / pre_order_items.no_rice: the line was sold without rice. Its unit_price is
//    already the reduced price (a copy, like every price on a line), so changing the rice price in
//    System Configuration never rewrites an old order.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->boolean('includes_rice')->default(false)->after('main_ingredients');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('no_rice')->default(false)->after('sub_total');
        });

        Schema::table('pre_order_items', function (Blueprint $table) {
            $table->boolean('no_rice')->default(false)->after('sub_total');
        });
    }

    public function down(): void
    {
        Schema::table('pre_order_items', function (Blueprint $table) {
            $table->dropColumn('no_rice');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('no_rice');
        });

        Schema::table('dishes', function (Blueprint $table) {
            $table->dropColumn('includes_rice');
        });
    }
};
