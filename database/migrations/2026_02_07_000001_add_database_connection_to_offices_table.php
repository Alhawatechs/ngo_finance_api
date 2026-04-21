<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->string('database_name', 64)->nullable()->after('is_active')->comment('MySQL database name for this office financial data');
            $table->string('database_connection', 64)->nullable()->after('database_name')->comment('Laravel connection name e.g. office_5');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn(['database_name', 'database_connection']);
        });
    }
};
