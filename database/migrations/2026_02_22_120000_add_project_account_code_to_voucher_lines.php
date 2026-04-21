<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('voucher_lines', 'project_account_code')) {
                $table->string('project_account_code', 50)->nullable()->after('cost_center');
            }
        });
    }

    public function down(): void
    {
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropColumn('project_account_code');
        });
    }
};
