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
            $table->foreignId('parent_grant_id')->nullable()->after('donor_id')->constrained('grants')->onDelete('set null')->comment('When set, this contract is an amendment to the parent');
        });

        // Add 'amendment' to documents.document_type enum (MySQL)
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE documents MODIFY COLUMN document_type ENUM('invoice', 'receipt', 'contract', 'amendment', 'report', 'correspondence', 'other') DEFAULT 'other'");
        } else {
            Schema::table('documents', function (Blueprint $table) {
                $table->string('document_type', 50)->default('other')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropForeign(['parent_grant_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE documents MODIFY COLUMN document_type ENUM('invoice', 'receipt', 'contract', 'report', 'correspondence', 'other') DEFAULT 'other'");
        }
    }
};
