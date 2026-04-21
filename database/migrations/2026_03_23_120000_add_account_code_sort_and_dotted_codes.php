<?php

use App\Services\CoaDottedMigrationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds account_code_sort and remaps legacy 5+ digit numeric NGO codes to dotted notation (1, 11, 11.1, 11.1.1).
 * Replaces the previous fixed-width numeric coding structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        if (! Schema::hasColumn('chart_of_accounts', 'account_code_sort')) {
            Schema::table('chart_of_accounts', function ($table) {
                $table->string('account_code_sort', 64)->nullable()->after('account_code');
            });
        }

        $orgIds = DB::table('chart_of_accounts')->distinct()->pluck('organization_id');
        $migrator = new CoaDottedMigrationService;

        foreach ($orgIds as $orgId) {
            if ($orgId === null) {
                continue;
            }
            $migrator->ensureOrganizationDottedAndSort((int) $orgId);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chart_of_accounts', 'account_code_sort')) {
            Schema::table('chart_of_accounts', function ($table) {
                $table->dropColumn('account_code_sort');
            });
        }
    }
};
