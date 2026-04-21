<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add archive support: nullable documentable for standalone docs, archive_category.
     */
    public function up(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->string('documentable_type')->nullable()->change();
            $table->unsignedBigInteger('documentable_id')->nullable()->change();
            $table->string('archive_category', 50)->nullable()->after('document_type');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('documents')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('archive_category');
            $table->string('documentable_type')->nullable(false)->change();
            $table->unsignedBigInteger('documentable_id')->nullable(false)->change();
        });
    }
};
