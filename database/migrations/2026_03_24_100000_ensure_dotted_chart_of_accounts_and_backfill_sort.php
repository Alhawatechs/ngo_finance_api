<?php

use App\Services\CoaDottedMigrationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent pass: converts any remaining legacy 5+ digit numeric codes to dotted notation,
 * and ensures account_code_sort is populated. Safe if 2026_03_23 already ran.
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
        // No-op: same structural state as after 2026_03_23; rollback is not reversible.
    }
};
