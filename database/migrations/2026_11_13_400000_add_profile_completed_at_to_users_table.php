<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// When a person finished giving the details the app needs (name and company). Someone who
// signs up with Google arrives with only what Google tells us, so they stay NULL here and
// the `profile.complete` middleware holds them on "Complete your profile" until they fill
// it in. Every account that exists today is marked complete, so nobody is locked out.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('profile_completed_at')->nullable()->after('email_verified_at');
        });

        DB::table('users')->update(['profile_completed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_completed_at');
        });
    }
};
