<?php

namespace App\Services;

use App\Models\Office;
use Database\Seeders\OfficeDatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfficeProvisioningService
{
    public function __construct(
        protected OfficeContext $officeContext
    ) {}

    /**
     * Create database, run office migrations, optionally seed, and update office record.
     *
     * @param bool $seed Whether to seed default chart of accounts and current fiscal year
     */
    public function provision(Office $office, bool $seed = true): bool
    {
        $dbName = $this->databaseName($office);
        $connectionName = 'office_' . $office->id;

        try {
            $this->createDatabase($dbName);
            $this->registerAndRunMigrations($office, $dbName, $connectionName, $seed);
            $office->update([
                'database_name' => $dbName,
                'database_connection' => $connectionName,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Office DB provisioning failed: ' . $e->getMessage(), [
                'office_id' => $office->id,
                'database' => $dbName,
            ]);
            throw $e;
        }
    }

    /**
     * Create MySQL database.
     */
    protected function createDatabase(string $databaseName): void
    {
        $driver = config('database.default');
        $charset = config("database.connections.{$driver}.charset", 'utf8mb4');
        $collation = config("database.connections.{$driver}.collation", 'utf8mb4_unicode_ci');
        $databaseName = '`' . str_replace('`', '``', $databaseName) . '`';
        DB::connection(config('database.default'))->statement(
            "CREATE DATABASE IF NOT EXISTS {$databaseName} CHARACTER SET {$charset} COLLATE {$collation}"
        );
    }

    /**
     * Register dynamic connection and run office migrations; optionally seed COA and fiscal year.
     */
    protected function registerAndRunMigrations(Office $office, string $dbName, string $connectionName, bool $seed = true): void
    {
        $officeWithConnection = (clone $office)->forceFill([
            'database_name' => $dbName,
            'database_connection' => $connectionName,
        ]);
        OfficeContext::registerOfficeConnection($officeWithConnection);
        Artisan::call('migrate', [
            '--database' => $connectionName,
            '--path' => 'database/migrations_office',
            '--force' => true,
        ]);
        if (Artisan::output() && str_contains(Artisan::output(), 'Error')) {
            throw new \RuntimeException('Office migrations failed: ' . Artisan::output());
        }
        if ($seed) {
            OfficeContext::runWithOffice($officeWithConnection, function () {
                (new OfficeDatabaseSeeder())->run();
            });
        }
    }

    /**
     * Default database name for an office.
     */
    public function databaseName(Office $office): string
    {
        $prefix = config('database.office_prefix', 'aada_erp_office');
        $safeCode = preg_replace('/[^a-z0-9_]/i', '_', $office->code);
        return strtolower($prefix . '_' . $office->id . '_' . $safeCode);
    }

    /**
     * Check if office has a provisioned database.
     */
    public function isProvisioned(Office $office): bool
    {
        return !empty($office->database_name) && !empty($office->database_connection);
    }
}
