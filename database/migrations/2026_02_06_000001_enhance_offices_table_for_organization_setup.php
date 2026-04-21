<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->text('description')->nullable()->after('manager_name');
            $table->json('key_staff')->nullable()->after('description');
            $table->string('timezone', 50)->nullable()->after('key_staff');
            $table->string('cost_center_prefix', 20)->nullable()->after('timezone');
            $table->string('operating_hours', 50)->nullable()->after('cost_center_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'key_staff',
                'timezone',
                'cost_center_prefix',
                'operating_hours',
            ]);
        });
    }
};
