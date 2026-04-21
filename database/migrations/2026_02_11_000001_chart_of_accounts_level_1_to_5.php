<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN `level` INT NOT NULL DEFAULT 1 COMMENT 'Hierarchy level 1-5'");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN `level` INT NOT NULL DEFAULT 1 COMMENT 'Hierarchy level 1-4'");
        }
    }
};
