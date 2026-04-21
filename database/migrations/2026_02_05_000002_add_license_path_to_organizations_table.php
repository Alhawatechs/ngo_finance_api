<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('organizations', 'license_path')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->string('license_path')->nullable()->after('legal_status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('license_path');
        });
    }
};
