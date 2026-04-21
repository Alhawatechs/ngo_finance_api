<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Services\OfficeContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Run database/migrations_office on each provisioned office database (adds columns such as journal_id).
 */
class MigrateOfficeFinancial extends Command
{
    protected $signature = 'office:migrate-financial {--office= : Limit to one office ID}';

    protected $description = 'Run database/migrations_office on provisioned office database(s)';

    public function handle(): int
    {
        $query = Office::query()
            ->whereNotNull('database_name')
            ->whereNotNull('database_connection');

        if ($this->option('office')) {
            $query->where('id', (int) $this->option('office'));
        }

        $offices = $query->get();
        if ($offices->isEmpty()) {
            $this->warn('No offices with provisioned databases found.');

            return self::SUCCESS;
        }

        foreach ($offices as $office) {
            OfficeContext::registerOfficeConnection($office);
            $this->info("Migrating {$office->database_connection} (office {$office->id}: {$office->name})...");
            Artisan::call('migrate', [
                '--database' => $office->database_connection,
                '--path' => 'database/migrations_office',
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());
        }

        return self::SUCCESS;
    }
}
