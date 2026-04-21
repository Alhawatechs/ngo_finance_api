<?php

namespace App\Console\Commands;

use App\Models\Office;
use App\Services\OfficeProvisioningService;
use Illuminate\Console\Command;

class ProvisionOfficeDatabase extends Command
{
    protected $signature = 'office:provision {office : Office ID or code}';
    protected $description = 'Create and migrate the financial database for an office';

    public function handle(OfficeProvisioningService $provisioning): int
    {
        $officeInput = $this->argument('office');
        $office = is_numeric($officeInput)
            ? Office::find($officeInput)
            : Office::where('code', $officeInput)->first();

        if (!$office) {
            $this->error('Office not found.');
            return self::FAILURE;
        }

        if ($provisioning->isProvisioned($office)) {
            $this->warn('Office already has a database: ' . $office->database_name);
            if (!$this->confirm('Re-run migrations?', false)) {
                return self::SUCCESS;
            }
        }

        $this->info('Provisioning database for office: ' . $office->name . ' (' . $office->code . ')');
        try {
            $provisioning->provision($office);
            $this->info('Database created: ' . $office->fresh()->database_name);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
