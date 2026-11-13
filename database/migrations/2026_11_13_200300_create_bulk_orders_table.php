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
        Schema::create('bulk_orders', function (Blueprint $table) {
            $table->id('bulk_order_id');
            $table->foreignId('bulk_session_id')->constrained('bulk_sessions', 'bulk_session_id')->cascadeOnDelete();
            // The customer who placed the order.
            $table->foreignId('user_id')->constrained('users');
            // BLK-YYYYMMDD-NNNN, its own counter apart from ORD-.
            $table->string('order_number')->unique();
            $table->timestamp('order_date');
            $table->decimal('total_amount', 10, 2)->default(0);
            // pending -> completed / cancelled. No stock to give back on cancel.
            $table->enum('order_status', ['pending', 'completed', 'cancelled'])->default('pending');

            // The payment lives on the order: a bulk order has exactly one.
            $table->foreignId('payment_method_id')->constrained('payment_methods', 'method_id');
            $table->enum('payment_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->string('reference_number')->nullable();
            $table->string('proof_of_payment')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            // The user who verified or rejected it, and when.
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();

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
        Schema::dropIfExists('bulk_orders');
    }
};
