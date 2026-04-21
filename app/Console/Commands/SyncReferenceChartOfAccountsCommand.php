<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Database\Seeders\NGOChartOfAccountsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Applies {@see database/seeders/data/reference-coa-hierarchy.json} so the Account list matches the NGO workbook.
 */
class SyncReferenceChartOfAccountsCommand extends Command
{
    protected $signature = 'chart-of-accounts:sync-reference
                            {--organization= : Organization ID (default: first organization)}';

    protected $description = 'Apply the reference chart of accounts from reference-coa-hierarchy.json (updates DB and clears tree cache).';

    public function handle(): int
    {
        $path = database_path('seeders/data/reference-coa-hierarchy.json');
        if (! File::exists($path)) {
            $this->error('Missing file: database/seeders/data/reference-coa-hierarchy.json');
            $this->line('Generate it with: node backend/scripts/parse-reference-coa.cjs "path/to/AADA Final Chart of accounts.xlsx"');

            return self::FAILURE;
        }

        $orgOpt = $this->option('organization');
        $organization = $orgOpt !== null && $orgOpt !== ''
            ? Organization::find((int) $orgOpt)
            : Organization::orderBy('id')->first();

        if (! $organization) {
            $this->error('No organization found.');

            return self::FAILURE;
        }

        NGOChartOfAccountsSeeder::applyReferenceHierarchy((int) $organization->id, $path);

        $this->info('Reference chart applied for organization: '.$organization->name.' (ID '.$organization->id.').');
        $this->line('Open General Ledger → Account list (or click Refresh) to load the updated tree.');

        return self::SUCCESS;
    }
}
