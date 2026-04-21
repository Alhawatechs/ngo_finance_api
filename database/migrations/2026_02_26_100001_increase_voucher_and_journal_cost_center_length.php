<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Support hierarchical cost center codes (e.g. AB:DH:2078-Want-Waigal) up to 255 chars.
     */
    public function up(): void
    {
        if (Schema::hasTable('voucher_lines')) {
            Schema::table('voucher_lines', function (Blueprint $table) {
                $table->string('cost_center', 255)->nullable()->change();
            });
        }
        if (Schema::hasTable('journal_entry_lines')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->string('cost_center', 255)->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('voucher_lines')) {
            Schema::table('voucher_lines', function (Blueprint $table) {
                $table->string('cost_center', 50)->nullable()->change();
            });
        }
        if (Schema::hasTable('journal_entry_lines')) {
            Schema::table('journal_entry_lines', function (Blueprint $table) {
                $table->string('cost_center', 50)->nullable()->change();
            });
        }
    }
};
