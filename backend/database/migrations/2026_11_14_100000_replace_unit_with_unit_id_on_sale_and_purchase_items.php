<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['sale_items', 'purchase_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('unit_id')->nullable()->after('qty')->constrained('inventory_units')->nullOnDelete();
            });

            // Carry the old free-text units over where they match a real unit.
            foreach (DB::table('inventory_units')->get(['id', 'name', 'short_name']) as $unit) {
                DB::table($tableName)
                    ->whereNull('unit_id')
                    ->where(fn ($query) => $query->where('unit', $unit->name)->orWhere('unit', $unit->short_name))
                    ->update(['unit_id' => $unit->id]);
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['sale_items', 'purchase_items'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('unit', 50)->nullable()->after('qty');
            });

            DB::table($tableName)->whereNotNull('unit_id')->update([
                'unit' => DB::raw('(select name from inventory_units where inventory_units.id = '.$tableName.'.unit_id)'),
            ]);

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['unit_id']);
                $table->dropColumn('unit_id');
            });
        }
    }
};
