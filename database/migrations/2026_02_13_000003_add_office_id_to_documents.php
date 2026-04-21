<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add office_id for office-scope filtering (documents from projects/vouchers).
     */
    public function up(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('organization_id')->constrained('offices')->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
        });
    }
};
