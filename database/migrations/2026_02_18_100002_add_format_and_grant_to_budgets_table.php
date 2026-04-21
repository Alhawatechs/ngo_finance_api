<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->foreignId('budget_format_template_id')
                ->nullable()
                ->after('fund_id')
                ->constrained('budget_format_templates')
                ->onDelete('set null');
            $table->foreignId('grant_id')
                ->nullable()
                ->after('budget_format_template_id')
                ->constrained('grants')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropForeign(['budget_format_template_id']);
            $table->dropForeign(['grant_id']);
        });
    }
};
