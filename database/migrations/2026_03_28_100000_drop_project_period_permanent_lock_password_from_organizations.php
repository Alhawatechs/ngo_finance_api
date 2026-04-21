<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'project_period_permanent_lock_password_hash')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('project_period_permanent_lock_password_hash');
            });
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('project_period_permanent_lock_password_hash', 255)->nullable()->after('default_currency');
        });
    }
};
