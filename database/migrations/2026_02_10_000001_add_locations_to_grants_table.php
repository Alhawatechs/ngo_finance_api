<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->json('locations')->nullable()->after('location')->comment('One or more geographical locations for this contract');
        });

        // Migrate existing single location into locations array
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("
                UPDATE grants
                SET locations = JSON_ARRAY(location)
                WHERE location IS NOT NULL AND location != ''
            ");
        }
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn('locations');
        });
    }
};
