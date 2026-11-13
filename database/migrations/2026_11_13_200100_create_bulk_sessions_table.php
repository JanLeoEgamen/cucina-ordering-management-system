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
        Schema::create('bulk_sessions', function (Blueprint $table) {
            $table->id('bulk_session_id');
            // The user who opened the session; only they may end it.
            $table->foreignId('created_by')->constrained('users');
            // A short label the customers see ("Christmas party trays"); optional.
            $table->string('title')->nullable();
            // Same window as an order session: one day, between start_time and end_time.
            $table->date('order_date');
            $table->time('start_time');
            $table->time('end_time');
            // The fewest plates (all dishes together) one order must hold.
            $table->unsignedInteger('min_quantity')->default(10);
            // Stamped when the creator ends the session by hand.
            $table->timestamp('ended_at')->nullable();
            // 1 while the session is open; the end action clears it.
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
        Schema::dropIfExists('bulk_sessions');
    }
};
