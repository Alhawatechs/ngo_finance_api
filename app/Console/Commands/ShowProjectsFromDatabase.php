<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShowProjectsFromDatabase extends Command
{
    protected $signature = 'projects:list {--connection= : Database connection name (default: default)}';
    protected $description = 'Read the projects table from the database and show project records';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');

        $this->info('Reading projects from database connection: ' . $connection);

        try {
            $projects = DB::connection($connection)
                ->table('projects')
                ->orderBy('start_date', 'desc')
                ->get();
        } catch (\Throwable $e) {
            $this->error('Could not read projects table: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($projects->isEmpty()) {
            $this->warn('No project records found in the projects table.');
            return self::SUCCESS;
        }

        $this->info('Total records: ' . $projects->count());
        $this->newLine();

        $rows = $projects->map(fn ($p) => [
            $p->id,
            $p->project_code ?? '-',
            $p->project_name ?? '-',
            $p->organization_id ?? '-',
            $p->grant_id ?? '-',
            $p->office_id ?? '-',
            $p->status ?? '-',
            $p->start_date ?? '-',
            $p->end_date ?? '-',
            number_format((float) ($p->budget_amount ?? 0), 2),
            $p->currency ?? '-',
        ])->toArray();

        $this->table(
            ['ID', 'Code', 'Project name', 'Org ID', 'Grant ID', 'Office ID', 'Status', 'Start', 'End', 'Budget', 'Currency'],
            $rows
        );

        return self::SUCCESS;
    }
}
