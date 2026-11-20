<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Maybe later" on the Google Chat sign-in prompt used to be remembered for good
// (`2026_11_20_140000`). The prompt now comes back at every login until the person is synced, so
// "Maybe later" only closes it for that visit and there is nothing left to store.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_chat_prompt_dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('google_chat_prompt_dismissed_at')->nullable()->after('google_chat_welcomed_at');
        });
    }
};
