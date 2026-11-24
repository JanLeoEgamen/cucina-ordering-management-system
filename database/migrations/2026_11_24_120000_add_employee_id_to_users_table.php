<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The company's own number for a person. Registration asks for it from everyone whose company is
// not Guest (Company::employeeIdRules), so staff can tell who a customer really is. Nullable:
// Guests have none, and accounts made before this column simply have none. Not unique on purpose:
// two companies can number their people the same way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id', 50)->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
