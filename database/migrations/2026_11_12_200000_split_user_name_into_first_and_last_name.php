<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A person is stored as a first and a last name, not one free text "name". The
// existing names are split on their LAST space ("Jan Leo Egamen" becomes first
// "Jan Leo", last "Egamen"), since several given names are common; a single word
// becomes the first name and leaves the last name empty, to be completed in the
// Users page. The columns default to '' so the rows can be filled before `name` goes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->default('')->after('id');
            $table->string('last_name')->default('')->after('first_name');
        });

        // Soft deleted users too: they still show up as "created by" and "verified by".
        DB::table('users')->orderBy('id')->get(['id', 'name'])->each(function (object $user) {
            $name = trim((string) preg_replace('/\s+/', ' ', (string) $user->name));
            $cut = strrpos($name, ' ');

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => $cut === false ? $name : substr($name, 0, $cut),
                'last_name' => $cut === false ? '' : substr($name, $cut + 1),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('')->after('id');
        });

        DB::table('users')->orderBy('id')->get(['id', 'first_name', 'last_name'])->each(function (object $user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => trim($user->first_name . ' ' . $user->last_name),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
