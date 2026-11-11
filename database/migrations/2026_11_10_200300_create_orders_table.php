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
        Schema::create('orders', function (Blueprint $table) {
            $table->id('order_id');
            $table->foreignId('session_id')->constrained('order_sessions', 'session_id')->cascadeOnDelete();
            // The customer who placed the order.
            $table->foreignId('user_id')->constrained('users');
            $table->string('order_number')->unique();
            $table->timestamp('order_date');
            $table->decimal('total_amount', 10, 2)->default(0);
            // pending -> completed / cancelled; a cancelled order frees its stock.
            $table->enum('order_status', ['pending', 'completed', 'cancelled'])->default('pending');
            // Mirrors the payments row: pending -> verified / rejected.
            $table->enum('payment_status', ['pending', 'verified', 'rejected'])->default('pending');
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
        Schema::dropIfExists('orders');
    }
};
