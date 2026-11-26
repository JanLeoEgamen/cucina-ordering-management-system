<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When and how an order was closed, so the analytics can tell how long a hand-over took and why
// orders are cancelled. Orders closed before this keep NULLs on purpose: updated_at is moved by
// later writes too, so filling these from it would make up history.
//
//   completed_at    when the order was handed over
//   cancelled_at    when it was called off
//   cancelled_by    who called it off (the order's own user_id = the customer did it)
//   cancel_reason   what staff typed, optional; never shown to the customer
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['completed_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
