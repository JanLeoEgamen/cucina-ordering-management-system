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
        Schema::create('session_dishes', function (Blueprint $table) {
            $table->id('session_dish_id');
            $table->foreignId('session_id')->constrained('order_sessions', 'session_id')->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained('dishes', 'dish_id')->cascadeOnDelete();
            // available_stock is what the menu still offers; reserved_stock is
            // what the placed orders hold. The two move together.
            $table->integer('available_stock')->default(0);
            $table->integer('reserved_stock')->default(0);
            // Offered in this session (the drawer's checkbox).
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            // A dish appears at most once per session.
            $table->unique(['session_id', 'dish_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('session_dishes');
    }
};
