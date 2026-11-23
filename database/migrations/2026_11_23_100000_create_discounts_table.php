<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The discounts staff define (Senior Citizen 20%, PWD 20%...). A customer picks one at checkout;
// the order keeps a COPY of its name and percent (see the add_discount_to_orders migration), so
// editing or deleting a discount here never changes an order that already used it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id('discount_id');
            $table->string('discount_name');
            // 0.01 - 100.00 (validated by the controller; 100 makes the order free).
            $table->decimal('percent', 5, 2);
            // When on, the customer must upload a proof (an ID) and staff approve or reject it.
            $table->boolean('requires_proof')->default(true);
            // Optional window, both ends included. Null = no limit on that side.
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
