<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a budget format template to a Google Spreadsheet (for import/sync).
     * Supports many sheets (tabs) via column_definition.sheets[].
     */
    public function up(): void
    {
        Schema::table('budget_format_templates', function (Blueprint $table) {
            $table->string('google_spreadsheet_id', 128)->nullable()->after('column_definition');
        });
    }

    public function down(): void
    {
        Schema::table('budget_format_templates', function (Blueprint $table) {
            $table->dropColumn('google_spreadsheet_id');
        });
    }
};
