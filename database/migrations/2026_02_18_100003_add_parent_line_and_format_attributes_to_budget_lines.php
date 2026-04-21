<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('parent_line_id')
                ->nullable()
                ->after('budget_id')
                ->constrained('budget_lines')
                ->onDelete('cascade');
            $table->json('format_attributes')->nullable()->after('available_amount');
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropForeign(['parent_line_id']);
            $table->dropColumn('format_attributes');
        });
    }
};
