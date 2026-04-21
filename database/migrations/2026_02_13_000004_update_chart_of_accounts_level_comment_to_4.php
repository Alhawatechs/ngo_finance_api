<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Update level column comment to reflect 4-level structure (no subsidiary layer).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN `level` INT NOT NULL DEFAULT 1 COMMENT 'Hierarchy level 1-4 (Category, Subcategory, General Ledger, Account)'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE chart_of_accounts MODIFY COLUMN `level` INT NOT NULL DEFAULT 1 COMMENT 'Hierarchy level 1-5'");
    }
};
