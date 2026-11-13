<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bulk_order_items', function (Blueprint $table) {
            $table->id('bulk_order_item_id');
            $table->foreignId('bulk_order_id')->constrained('bulk_orders', 'bulk_order_id')->cascadeOnDelete();
            // The dish of the bulk session this line was sold from.
            $table->foreignId('bulk_session_dish_id')->constrained('bulk_session_dishes', 'bulk_session_dish_id');
            $table->unsignedInteger('quantity');
            // The dish price at the time of the order.
            $table->decimal('unit_price', 10, 2);
            $table->decimal('sub_total', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_order_items');
    }
};
