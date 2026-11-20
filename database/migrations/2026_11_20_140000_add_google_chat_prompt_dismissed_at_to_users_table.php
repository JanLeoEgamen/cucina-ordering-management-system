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
        Schema::table('users', function (Blueprint $table) {
            // When the person closed the sign-in prompt that offers Google Chat with "Maybe later":
            // it is not shown again (the Profile page still has the card). Null = never dismissed.
            $table->timestamp('google_chat_prompt_dismissed_at')->nullable()->after('google_chat_welcomed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_chat_prompt_dismissed_at');
        });
    }
};
