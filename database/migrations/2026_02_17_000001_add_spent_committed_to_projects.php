<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'spent_amount')) {
                $table->decimal('spent_amount', 18, 2)->default(0)->after('budget_amount');
            }
            if (!Schema::hasColumn('projects', 'committed_amount')) {
                $table->decimal('committed_amount', 18, 2)->default(0)->after('spent_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['spent_amount', 'committed_amount']);
        });
    }
};
