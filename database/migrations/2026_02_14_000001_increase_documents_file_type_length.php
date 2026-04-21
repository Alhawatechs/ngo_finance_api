<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documents')) {
            DB::statement('ALTER TABLE documents MODIFY COLUMN file_type VARCHAR(255)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documents')) {
            DB::statement('ALTER TABLE documents MODIFY COLUMN file_type VARCHAR(50)');
        }
    }
};
