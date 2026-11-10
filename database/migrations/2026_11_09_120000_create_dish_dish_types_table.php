<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A dish can sit in several dish types, so dishes.dish_type_id is replaced by
    // dish_dish_types. A database that still has the old column has its values
    // copied across first; a fresh install never has the column (the create
    // migration no longer defines it), so those steps are skipped.
    public function up(): void
    {
        if (! Schema::hasTable('dish_dish_types')) {
            Schema::create('dish_dish_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('dish_id')->constrained('dishes', 'dish_id')->cascadeOnDelete();
                $table->foreignId('dish_type_id')->constrained('dish_types', 'dish_type_id')->cascadeOnDelete();
                $table->unique(['dish_id', 'dish_type_id']);
            });
        }

        if (! Schema::hasColumn('dishes', 'dish_type_id')) {
            return;
        }

        // Whatever each dish already points at becomes its first pivot row.
        foreach (DB::table('dishes')->whereNotNull('dish_type_id')->get(['dish_id', 'dish_type_id']) as $row) {
            DB::table('dish_dish_types')->insertOrIgnore([
                'dish_id' => $row->dish_id,
                'dish_type_id' => $row->dish_type_id,
            ]);
        }

        // MySQL will not drop a column a foreign key still uses, so the key goes
        // first. SQLite (the test suite) drops both with the column.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('dishes', function (Blueprint $table) {
                $table->dropForeign(['dish_type_id']);
            });
        }

        Schema::table('dishes', function (Blueprint $table) {
            $table->dropColumn('dish_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('dishes', function (Blueprint $table) {
            $table->foreignId('dish_type_id')->nullable()->constrained('dish_types', 'dish_type_id');
        });

        // One type per dish again: the lowest pivot row id wins.
        foreach (DB::table('dish_dish_types')->orderBy('id')->get() as $row) {
            DB::table('dishes')
                ->where('dish_id', $row->dish_id)
                ->whereNull('dish_type_id')
                ->update(['dish_type_id' => $row->dish_type_id]);
        }

        Schema::dropIfExists('dish_dish_types');
    }
};
