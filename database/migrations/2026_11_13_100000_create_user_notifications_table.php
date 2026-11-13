<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app notifications: one row per person who should be told. The text is
     * written when the event happens (title / body / url), so reading a list or
     * counting the unread ones never joins another table.
     *
     * Not soft deleted, on purpose: a notification is not a record to keep. Read
     * ones are deleted for good by `php artisan notifications:prune`.
     *
     * The table is not called `notifications`: that is the name of Laravel's own
     * (uuid + morph) notifications table, which this app does not use.
     */
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            // Who is told. Users are soft deleted, so the cascade is a safety net only.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // order_placed, payment_verified, payment_rejected, order_completed,
            // order_cancelled, payment_resent, dish_sold_out (see App\Support\OrderNotifier).
            $table->string('type', 40);
            $table->string('title', 160);
            $table->string('body', 500)->nullable();
            // A local path (never a full URL) the notification opens, or null.
            $table->string('url', 255)->nullable();
            // The order it is about, when there is one. No foreign key: a row must
            // never block (or be removed with) an order.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The unread count: WHERE user_id = ? AND read_at IS NULL.
            $table->index(['user_id', 'read_at', 'id'], 'user_notifications_unread_index');
            // The list and the "anything new since id N" poll: WHERE user_id = ? [AND id > ?] ORDER BY id.
            $table->index(['user_id', 'id'], 'user_notifications_feed_index');
            // The prune command.
            $table->index('created_at', 'user_notifications_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
