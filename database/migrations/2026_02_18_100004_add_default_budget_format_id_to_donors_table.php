<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donors', function (Blueprint $table) {
            $table->foreignId('default_budget_format_id')
                ->nullable()
                ->after('reporting_frequency')
                ->constrained('budget_format_templates')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('donors', function (Blueprint $table) {
            $table->dropForeign(['default_budget_format_id']);
        });
    }
};
