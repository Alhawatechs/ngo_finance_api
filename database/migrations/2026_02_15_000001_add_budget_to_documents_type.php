<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'budget' to documents.document_type so budget uploads in Edit work.
     */
    public function up(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE documents MODIFY COLUMN document_type ENUM('invoice', 'receipt', 'contract', 'amendment', 'budget', 'report', 'correspondence', 'other') DEFAULT 'other'");
        } else {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('document_type', 50)->default('other')->change();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE documents MODIFY COLUMN document_type ENUM('invoice', 'receipt', 'contract', 'amendment', 'report', 'correspondence', 'other') DEFAULT 'other'");
        }
    }
};
