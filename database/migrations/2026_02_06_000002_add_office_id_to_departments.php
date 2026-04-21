<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('departments', 'office_id')) {
            return;
        }
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('organization_id')->constrained()->onDelete('set null');
            $table->index(['organization_id', 'office_id']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('departments', 'office_id')) {
            return;
        }
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->dropIndex(['organization_id', 'office_id']);
        });
    }
};
