<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add sheet_key to budget_lines for multi-annex budget formats.
     * Each line can belong to a specific sheet (e.g. "0" = main, "1" = Annex A).
     */
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->string('sheet_key', 64)->nullable()->after('budget_id');
        });

        // Allow same account on different sheets: drop old unique, add new one including sheet_key
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropUnique(['budget_id', 'account_id', 'line_code']);
        });
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->unique(['budget_id', 'account_id', 'line_code', 'sheet_key'], 'budget_lines_budget_account_line_sheet_unique');
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropUnique('budget_lines_budget_account_line_sheet_unique');
        });
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->unique(['budget_id', 'account_id', 'line_code']);
        });
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropColumn('sheet_key');
        });
    }
};
