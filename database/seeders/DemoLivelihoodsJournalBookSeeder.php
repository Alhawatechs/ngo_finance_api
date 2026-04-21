<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Fund;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Office;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\CodingBlockVoucherNumberService;
use App\Services\OfficeContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the journal book "Livelihoods — Agriculture inputs" (demo project DEMO-PRJ-002)
 * with two balanced draft journal entries (materials expense vs operating cash).
 *
 * Requires: DemoProjectsSeeder (or same project in the office DB), NGOChartOfAccountsSeeder,
 * and an open fiscal period covering the entry date for that office connection.
 *
 * php artisan db:seed --class=DemoLivelihoodsJournalBookSeeder
 */
class DemoLivelihoodsJournalBookSeeder extends Seeder
{
    private const PROJECT_CODE = 'DEMO-PRJ-002';

    private const JOURNAL_CODE = 'LIV-AGR-BOOK';

    private const EXPENSE_ACCOUNT = '22.6.1';

    private const CASH_ACCOUNT = '31.1.1';

    public function run(): void
    {
        $organizations = Organization::query()->get();
        if ($organizations->isEmpty()) {
            $this->command?->error('No organization found.');

            return;
        }

        foreach ($organizations as $organization) {
            $this->seedForOrganization($organization);
        }
    }

    protected function seedForOrganization(Organization $organization): void
    {
        $orgId = $organization->id;

        $headOffice = Office::query()
            ->where('organization_id', $orgId)
            ->where('is_head_office', true)
            ->first()
            ?? Office::query()->where('organization_id', $orgId)->first();

        if (! $headOffice) {
            $this->command?->warn("Organization {$orgId}: no office — skip.");

            return;
        }

        OfficeContext::runWithOffice($headOffice, function () use ($organization, $orgId, $headOffice) {
            $project = Project::query()
                ->where('organization_id', $orgId)
                ->where(function ($q) {
                    $q->where('project_code', self::PROJECT_CODE)
                        ->orWhere('project_name', 'Livelihoods — Agriculture inputs');
                })
                ->first();

            if (! $project) {
                $this->command?->warn("Organization {$orgId}: project ".self::PROJECT_CODE.' / Livelihoods — Agriculture inputs not found on this connection. Run DemoProjectsSeeder first (same DB as office financial data).');

                return;
            }

            $expenseAccount = ChartOfAccount::query()
                ->where('organization_id', $orgId)
                ->where('account_code', self::EXPENSE_ACCOUNT)
                ->where('is_posting', true)
                ->first();

            $cashAccount = ChartOfAccount::query()
                ->where('organization_id', $orgId)
                ->where('account_code', self::CASH_ACCOUNT)
                ->where('is_posting', true)
                ->first();

            if (! $expenseAccount || ! $cashAccount) {
                $this->command?->warn("Organization {$orgId}: posting accounts ".self::EXPENSE_ACCOUNT.' and '.self::CASH_ACCOUNT.' not found. Run NGOChartOfAccountsSeeder.');

                return;
            }

            $fiscalPeriod = $this->resolveOrCreateOpenFiscalPeriod($orgId);

            if (! $fiscalPeriod) {
                $this->command?->warn("Organization {$orgId}: could not resolve fiscal period.");

                return;
            }

            $start = Carbon::parse($fiscalPeriod->start_date)->startOfDay();
            $end = Carbon::parse($fiscalPeriod->end_date)->startOfDay();
            $today = Carbon::now()->startOfDay();
            $entryDate = ($today->between($start, $end) ? $today : $start)->format('Y-m-d');

            $journal = Journal::query()->firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'code' => self::JOURNAL_CODE,
                ],
                [
                    'name' => 'Livelihoods — Agriculture inputs',
                    'project_id' => $project->id,
                    'office_id' => $headOffice->id,
                    'province_code' => '01',
                    'is_active' => true,
                ]
            );

            $journal->fill([
                'name' => 'Livelihoods — Agriculture inputs',
                'project_id' => $project->id,
                'office_id' => $headOffice->id,
                'province_code' => '01',
                'is_active' => true,
            ]);
            $journal->save();

            $userId = User::query()->where('organization_id', $orgId)->orderBy('id')->value('id');
            if (! $userId) {
                $this->command?->warn("Organization {$orgId}: no user — skip.");

                return;
            }

            $fundId = Fund::query()
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            $conn = OfficeContext::connection();

            if (JournalEntry::query()->where('journal_id', $journal->id)->where('reference', 'DEMO-AGR-SEED-1')->exists()) {
                $this->command?->info("Organization {$orgId}: demo journal entries already present for ".self::JOURNAL_CODE.'.');

                return;
            }

            $samples = [
                [
                    'reference' => 'DEMO-AGR-SEED-1',
                    'description' => 'Demo: agriculture inputs — seed and fertilizer (seasonal distribution).',
                    'debit' => 5000.00,
                    'credit' => 5000.00,
                ],
                [
                    'reference' => 'DEMO-AGR-SEED-2',
                    'description' => 'Demo: agriculture inputs — hand tools and storage materials.',
                    'debit' => 1750.00,
                    'credit' => 1750.00,
                ],
            ];

            foreach ($samples as $row) {
                DB::connection($conn)->beginTransaction();
                try {
                    $entryNumber = $this->nextEntryNumber($orgId);
                    $voucherNumber = null;
                    $journal->loadMissing(['project', 'office']);
                    if ($journal->project_id && $journal->province_code && $journal->office_id && $journal->project && $journal->office) {
                        try {
                            $voucherNumber = app(CodingBlockVoucherNumberService::class)->getNextNumberForJournalEntry(
                                $orgId,
                                $journal,
                                $entryDate,
                                $organization
                            );
                        } catch (\Throwable) {
                            $voucherNumber = null;
                        }
                    }

                    $entry = JournalEntry::create([
                        'organization_id' => $orgId,
                        'journal_id' => $journal->id,
                        'office_id' => $headOffice->id,
                        'fiscal_period_id' => $fiscalPeriod->id,
                        'entry_number' => $entryNumber,
                        'voucher_number' => $voucherNumber,
                        'entry_date' => $entryDate,
                        'posting_date' => null,
                        'entry_type' => 'standard',
                        'reference' => $row['reference'],
                        'description' => $row['description'],
                        'currency' => 'USD',
                        'exchange_rate' => 1,
                        'total_debit' => $row['debit'],
                        'total_credit' => $row['credit'],
                        'status' => 'draft',
                        'created_by' => $userId,
                    ]);

                    $lines = [
                        [
                            'journal_entry_id' => $entry->id,
                            'account_id' => $expenseAccount->id,
                            'fund_id' => $fundId,
                            'project_id' => $project->id,
                            'office_id' => $headOffice->id,
                            'line_number' => 1,
                            'description' => 'Program materials — agriculture inputs',
                            'debit_amount' => $row['debit'],
                            'credit_amount' => 0,
                            'currency' => 'USD',
                            'exchange_rate' => 1,
                            'base_currency_debit' => $row['debit'],
                            'base_currency_credit' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                        [
                            'journal_entry_id' => $entry->id,
                            'account_id' => $cashAccount->id,
                            'fund_id' => $fundId,
                            'project_id' => $project->id,
                            'office_id' => $headOffice->id,
                            'line_number' => 2,
                            'description' => 'Operating cash — payment',
                            'debit_amount' => 0,
                            'credit_amount' => $row['credit'],
                            'currency' => 'USD',
                            'exchange_rate' => 1,
                            'base_currency_debit' => 0,
                            'base_currency_credit' => $row['credit'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ];

                    JournalEntryLine::on($conn)->insert($lines);

                    DB::connection($conn)->commit();
                } catch (\Throwable $e) {
                    DB::connection($conn)->rollBack();
                    throw $e;
                }
            }

            $this->command?->info("Organization {$orgId}: seeded 2 draft journal entries in journal book ".self::JOURNAL_CODE.'.');
        });
    }

    /**
     * Prefer an existing open period; if none (empty DB), create a minimal current FY + first month period for demo seeding only.
     */
    protected function resolveOrCreateOpenFiscalPeriod(int $orgId): ?FiscalPeriod
    {
        $existing = FiscalPeriod::query()
            ->whereHas('fiscalYear', fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'open')
            ->orderBy('start_date')
            ->first();

        if ($existing) {
            return $existing;
        }

        $year = (int) date('Y');
        $fy = FiscalYear::query()->firstOrCreate(
            [
                'organization_id' => $orgId,
                'name' => "FY {$year} (demo seed)",
            ],
            [
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-12-31",
                'status' => 'open',
                'is_current' => true,
            ]
        );

        return FiscalPeriod::query()->firstOrCreate(
            [
                'fiscal_year_id' => $fy->id,
                'period_number' => 1,
            ],
            [
                'name' => "January {$year}",
                'start_date' => "{$year}-01-01",
                'end_date' => "{$year}-01-31",
                'status' => 'open',
                'is_adjustment_period' => false,
            ]
        );
    }

    private function nextEntryNumber(int $organizationId): string
    {
        $prefix = 'JE';
        $year = date('Y');
        $month = date('m');

        $lastEntry = JournalEntry::query()
            ->where('organization_id', $organizationId)
            ->where('entry_number', 'like', "{$prefix}-{$year}{$month}-%")
            ->orderByDesc('entry_number')
            ->first();

        if ($lastEntry) {
            $newNumber = (int) substr($lastEntry->entry_number, -5) + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('%s-%s%s-%05d', $prefix, $year, $month, $newNumber);
    }
}
