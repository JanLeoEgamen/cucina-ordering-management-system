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
        Schema::create('bulk_session_dishes', function (Blueprint $table) {
            $table->id('bulk_session_dish_id');
            $table->foreignId('bulk_session_id')->constrained('bulk_sessions', 'bulk_session_id')->cascadeOnDelete();
            $table->foreignId('dish_id')->constrained('dishes', 'dish_id')->cascadeOnDelete();
            // No stock columns on purpose: a bulk session has no limit, the kitchen
            // decides its own capacity.
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['bulk_session_id', 'dish_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_session_dishes');
    }
};
