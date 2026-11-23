<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// What an order remembers about its discount. The name and percent are COPIED (like
// order_items.unit_price), so editing or deleting a discount later never rewrites an old order.
//
//   subtotal_amount    the price of the dishes before the discount (null on orders from before
//                      this existed: their total_amount IS their subtotal)
//   discount_amount    what the discount took off; 0 when there is none or it was rejected
//   total_amount       (already there) = subtotal - discount: what the customer pays
//   discount_status    none -> pending -> approved | rejected. A discount that needs no proof is
//                      approved at once. A rejected one puts the total back to the subtotal.
//   discount_proof     the customer's ID, on the PRIVATE disk (never the public one)
return new class extends Migration
{
    public function up(): void
    {
        foreach (['orders', 'pre_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('discount_id')->nullable()->constrained('discounts', 'discount_id')->restrictOnDelete();
                $table->string('discount_name')->nullable();
                $table->decimal('discount_percent', 5, 2)->nullable();
                $table->decimal('subtotal_amount', 10, 2)->nullable();
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->enum('discount_status', ['none', 'pending', 'approved', 'rejected'])->default('none');
                $table->string('discount_proof')->nullable();
                $table->string('discount_reject_reason', 500)->nullable();
                $table->foreignId('discount_decided_by')->nullable()->constrained('users');
                $table->timestamp('discount_decided_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'pre_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('discount_id');
                $table->dropConstrainedForeignId('discount_decided_by');
                $table->dropColumn([
                    'discount_name', 'discount_percent', 'subtotal_amount', 'discount_amount',
                    'discount_status', 'discount_proof', 'discount_reject_reason', 'discount_decided_at',
                ]);
            });
        }
    }
};
