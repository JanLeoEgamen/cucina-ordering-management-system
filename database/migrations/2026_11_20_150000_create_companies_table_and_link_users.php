<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A company used to be free text on the user (`users.company`). It is now a record of its own,
// managed on the Companies page and picked from a dropdown at registration, so two people can no
// longer write the same company two ways. The text already typed is kept: every distinct name
// (ignoring case and spaces at the ends) becomes a company and its users are pointed at it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id('company_id');
            $table->string('company_name');
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        // Nullable on purpose: accounts that predate this have no company until they pick one
        // (the profile form now requires it). `restrictOnDelete` is never reached in practice,
        // companies are soft deleted and refused while someone still belongs to them.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('last_name')
                ->constrained('companies', 'company_id')->restrictOnDelete();
        });

        $now = now();
        $ids = [];

        foreach (DB::table('users')->whereNotNull('company')->orderBy('id')->get(['id', 'company']) as $user) {
            $name = trim($user->company);

            if ($name === '') {
                continue;
            }

            // The first spelling met wins ("Acme Foods" over a later "acme foods").
            $key = mb_strtolower($name);
            $ids[$key] ??= DB::table('companies')->insertGetId([
                'company_name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('users')->where('id', $user->id)->update(['company_id' => $ids[$key]]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('company');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('company')->nullable()->after('last_name');
        });

        // Give the names back as text before the link goes.
        foreach (DB::table('companies')->get(['company_id', 'company_name']) as $company) {
            DB::table('users')->where('company_id', $company->company_id)->update(['company' => $company->company_name]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::dropIfExists('companies');
    }
};
