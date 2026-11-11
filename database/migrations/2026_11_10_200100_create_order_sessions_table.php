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
        Schema::create('order_sessions', function (Blueprint $table) {
            $table->id('session_id');
            // The user who opened the session; only they may end it.
            $table->foreignId('created_by')->constrained('users');
            // The session runs on one day, between start_time and end_time.
            $table->date('order_date');
            $table->time('start_time');
            $table->time('end_time');
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
        Schema::dropIfExists('order_sessions');
    }
};
