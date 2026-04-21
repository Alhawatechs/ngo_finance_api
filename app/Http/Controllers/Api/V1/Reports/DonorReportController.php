<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Models\Donor;
use App\Models\ExchangeRate;
use App\Models\Grant;
use App\Models\JournalEntryLine;
use App\Models\Organization;
use App\Models\Project;
use App\Services\OfficeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DonorReportController extends Controller
{
    /**
     * Amount in report (org default) currency for a journal line debit.
     */
    private static function lineReportDebit(JournalEntryLine $line): float
    {
        $base = $line->base_currency_debit ?? 0;
        if ($base > 0) {
            return (float) $base;
        }
        return (float) ($line->debit_amount * ($line->exchange_rate ?? 1));
    }

    /**
     * Amount in report (org default) currency for a journal line credit.
     */
    private static function lineReportCredit(JournalEntryLine $line): float
    {
        $base = $line->base_currency_credit ?? 0;
        if ($base > 0) {
            return (float) $base;
        }
        return (float) ($line->credit_amount * ($line->exchange_rate ?? 1));
    }
    /**
     * Generate donor financial report.
     */
    public function donorReport(Request $request)
    {
        $validated = $request->validate([
            'donor_id' => 'required|exists:donors,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'format' => 'nullable|string|in:standard,unicef,who,eu,usaid',
        ]);

        $orgId = $request->user()->organization_id;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        $donor = Donor::with('defaultBudgetFormat:id,name,code,structure_type,column_definition')
            ->where('id', $validated['donor_id'])
            ->where('organization_id', $orgId)
            ->first();

        if (!$donor) {
            return $this->error('Donor not found', 404);
        }

        $budgetFormat = $donor->defaultBudgetFormat;

        // Get grants for this donor
        $grants = Grant::where('donor_id', $donor->id)->get();
        $grantIds = $grants->pluck('id');

        // Get projects under these grants
        $projects = Project::whereIn('grant_id', $grantIds)->get();
        $projectIds = $projects->pluck('id');

        // Get expenditures by project (include donor expenditure code for donor reporting)
        $expenditures = JournalEntryLine::whereIn('project_id', $projectIds)
            ->whereHas('journalEntry', function ($q) use ($orgId, $validated) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->whereBetween('entry_date', [$validated['start_date'], $validated['end_date']]);
            })
            ->with(['account:id,account_code,account_name', 'project:id,project_code,project_name', 'donorExpenditureCode:id,code,name'])
            ->get();

        // Convert grant/project amounts to report currency for totals
        $totalBudget = 0;
        $totalDisbursed = 0;
        foreach ($grants as $g) {
            $conv = ExchangeRate::convert((float) $g->total_amount, $g->currency ?? $defaultCurrency, $defaultCurrency, $orgId, $validated['end_date']);
            $totalBudget += $conv ?? (float) $g->total_amount;
            $dConv = ExchangeRate::convert((float) ($g->disbursed_amount ?? 0), $g->currency ?? $defaultCurrency, $defaultCurrency, $orgId, $validated['end_date']);
            $totalDisbursed += $dConv ?? (float) ($g->disbursed_amount ?? 0);
        }
        $totalExpenditure = $expenditures->sum(fn ($item) => self::lineReportDebit($item));

        // Group by project (amounts in report currency)
        $byProject = $expenditures->groupBy('project_id')->map(function ($items, $projectId) use ($projects, $defaultCurrency, $orgId) {
            $project = $projects->firstWhere('id', $projectId);
            $expReport = $items->sum(fn ($i) => self::lineReportDebit($i));
            $budgetReport = 0;
            if ($project && ($project->total_budget ?? 0) > 0) {
                $budgetReport = ExchangeRate::convert((float) $project->total_budget, $project->currency ?? $defaultCurrency, $defaultCurrency, $orgId, now()->toDateString())
                    ?? (float) $project->total_budget;
            }
            return [
                'project_code' => $project?->project_code,
                'project_name' => $project?->project_name,
                'total_budget' => $budgetReport,
                'expenditures' => $expReport,
                'utilization' => $budgetReport > 0 ? round(($expReport / $budgetReport) * 100, 2) : 0,
            ];
        })->values();

        // Group by account (expense category) in report currency — org CoA for internal use
        $byCategory = $expenditures->groupBy(function ($item) {
            return $item->account?->account_code . ' - ' . $item->account?->account_name;
        })->map(function ($items, $category) {
            return [
                'category' => $category,
                'amount' => $items->sum(fn ($i) => self::lineReportDebit($i)),
            ];
        })->values();

        // Group by donor expenditure code for donor reports (donor's expense codes)
        $byDonorExpenditureCode = $expenditures->filter(fn ($i) => $i->donor_expenditure_code_id !== null)
            ->groupBy('donor_expenditure_code_id')
            ->map(function ($items) {
                $code = $items->first()->donorExpenditureCode;
                return [
                    'donor_expenditure_code' => $code?->code,
                    'donor_expenditure_name' => $code?->name,
                    'amount' => $items->sum(fn ($i) => self::lineReportDebit($i)),
                ];
            })->values();

        // Expenditure lines with no donor code (for reporting completeness)
        $unmappedAmount = $expenditures->filter(fn ($i) => $i->donor_expenditure_code_id === null)
            ->sum(fn ($i) => self::lineReportDebit($i));

        $format = $validated['format'] ?? 'standard';

        return $this->success([
            'report_type' => 'Donor Financial Report',
            'format' => $format,
            'budget_format' => $budgetFormat ? [
                'id' => $budgetFormat->id,
                'name' => $budgetFormat->name,
                'code' => $budgetFormat->code,
                'structure_type' => $budgetFormat->structure_type,
                'column_definition' => $budgetFormat->column_definition,
            ] : null,
            'report_currency' => $defaultCurrency,
            'donor' => [
                'code' => $donor->code,
                'name' => $donor->name,
                'type' => $donor->donor_type,
            ],
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'summary' => [
                'total_grants' => $grants->count(),
                'total_projects' => $projects->count(),
                'total_budget' => $totalBudget,
                'total_disbursed' => $totalDisbursed,
                'total_expenditure' => $totalExpenditure,
                'remaining_budget' => $totalBudget - $totalExpenditure,
                'utilization_rate' => $totalBudget > 0 
                    ? round(($totalExpenditure / $totalBudget) * 100, 2) 
                    : 0,
            ],
            'by_project' => $byProject,
            'by_category' => $byCategory,
            'by_donor_expenditure_code' => $byDonorExpenditureCode,
            'expenditure_without_donor_code' => $unmappedAmount,
            'grants' => $grants->map(function ($grant) use ($defaultCurrency, $orgId, $validated) {
                $convTotal = ExchangeRate::convert((float) $grant->total_amount, $grant->currency ?? $defaultCurrency, $defaultCurrency, $orgId, $validated['end_date']);
                $convDisb = ExchangeRate::convert((float) ($grant->disbursed_amount ?? 0), $grant->currency ?? $defaultCurrency, $defaultCurrency, $orgId, $validated['end_date']);
                $convSpent = ExchangeRate::convert((float) ($grant->spent_amount ?? 0), $grant->currency ?? $defaultCurrency, $defaultCurrency, $orgId, $validated['end_date']);
                return [
                    'grant_code' => $grant->grant_code,
                    'grant_name' => $grant->grant_name,
                    'total_amount' => $convTotal ?? (float) $grant->total_amount,
                    'disbursed_amount' => $convDisb ?? (float) ($grant->disbursed_amount ?? 0),
                    'spent_amount' => $convSpent ?? (float) ($grant->spent_amount ?? 0),
                    'status' => $grant->status,
                ];
            }),
        ]);
    }

    /**
     * Generate project financial report.
     */
    public function projectReport(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $orgId = $request->user()->organization_id;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        $project = Project::where('id', $validated['project_id'])
            ->where('organization_id', $orgId)
            ->with(['grant.donor', 'office'])
            ->first();

        if (!$project) {
            return $this->error('Project not found', 404);
        }

        $startDate = $validated['start_date'] ?? $project->start_date;
        $endDate = $validated['end_date'] ?? now()->toDateString();

        // Get all expenditures for this project
        $expenditures = JournalEntryLine::where('project_id', $project->id)
            ->whereHas('journalEntry', function ($q) use ($orgId, $startDate, $endDate) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->whereBetween('entry_date', [$startDate, $endDate]);
            })
            ->with(['account:id,account_code,account_name', 'journalEntry:id,entry_number,entry_date,description'])
            ->get();

        // Group by account (amounts in report currency)
        $byAccount = $expenditures->groupBy(function ($item) {
            return $item->account_id;
        })->map(function ($items) {
            $account = $items->first()->account;
            return [
                'account_code' => $account?->account_code,
                'account_name' => $account?->account_name,
                'total' => $items->sum(fn ($i) => self::lineReportDebit($i)),
            ];
        })->values();

        // Monthly breakdown (report currency)
        $monthlyBreakdown = $expenditures->groupBy(function ($item) {
            return $item->journalEntry->entry_date->format('Y-m');
        })->map(function ($items, $month) {
            return [
                'month' => $month,
                'amount' => $items->sum(fn ($i) => self::lineReportDebit($i)),
            ];
        })->values();

        $totalExpenditure = $expenditures->sum(fn ($item) => self::lineReportDebit($item));

        $budgetReport = ExchangeRate::convert((float) ($project->total_budget ?? 0), $project->currency ?? $defaultCurrency, $defaultCurrency, $orgId, $endDate)
            ?? (float) ($project->total_budget ?? 0);
        $committedReport = ExchangeRate::convert((float) ($project->committed_amount ?? 0), $project->currency ?? $defaultCurrency, $defaultCurrency, $orgId, $endDate)
            ?? (float) ($project->committed_amount ?? 0);

        return $this->success([
            'report_type' => 'Project Financial Report',
            'report_currency' => $defaultCurrency,
            'project' => [
                'code' => $project->project_code,
                'name' => $project->project_name,
                'office' => $project->office?->name,
                'manager' => $project->project_manager,
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'status' => $project->status,
            ],
            'grant' => $project->grant ? [
                'code' => $project->grant->grant_code,
                'name' => $project->grant->grant_name,
                'donor' => $project->grant->donor?->name,
            ] : null,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'budget' => [
                'total_budget' => $budgetReport,
                'committed' => $committedReport,
                'spent' => $totalExpenditure,
                'available' => $budgetReport - $totalExpenditure - $committedReport,
                'utilization_rate' => $budgetReport > 0
                    ? round(($totalExpenditure / $budgetReport) * 100, 2)
                    : 0,
            ],
            'by_account' => $byAccount,
            'monthly_breakdown' => $monthlyBreakdown,
            'transactions' => $expenditures->take(50)->map(function ($item) {
                return [
                    'date' => $item->journalEntry->entry_date,
                    'entry_number' => $item->journalEntry->entry_number,
                    'description' => $item->description ?: $item->journalEntry->description,
                    'account' => $item->account?->account_name,
                    'amount' => self::lineReportDebit($item),
                ];
            }),
        ]);
    }

    /**
     * Generate fund utilization report.
     */
    public function fundReport(Request $request)
    {
        $validated = $request->validate([
            'fund_id' => 'required|exists:funds,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $orgId = $request->user()->organization_id;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        $fund = \App\Models\Fund::where('id', $validated['fund_id'])
            ->where('organization_id', $orgId)
            ->with(['donor', 'grant'])
            ->first();

        if (!$fund) {
            return $this->error('Fund not found', 404);
        }

        // Get transactions for this fund
        $transactions = JournalEntryLine::where('fund_id', $fund->id)
            ->whereHas('journalEntry', function ($q) use ($orgId, $validated) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->whereBetween('entry_date', [$validated['start_date'], $validated['end_date']]);
            })
            ->with(['account:id,account_code,account_name', 'journalEntry:id,entry_number,entry_date,description'])
            ->get();

        $reportDebitSql = 'COALESCE(NULLIF(base_currency_debit, 0), debit_amount * COALESCE(exchange_rate, 1))';
        $reportCreditSql = 'COALESCE(NULLIF(base_currency_credit, 0), credit_amount * COALESCE(exchange_rate, 1))';

        $totalDebits = $transactions->sum(fn ($t) => self::lineReportDebit($t));
        $totalCredits = $transactions->sum(fn ($t) => self::lineReportCredit($t));

        // Opening balance (report currency)
        $openingBalance = (float) (JournalEntryLine::where('fund_id', $fund->id)
            ->whereHas('journalEntry', function ($q) use ($orgId, $validated) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->where('entry_date', '<', $validated['start_date']);
            })
            ->selectRaw("SUM({$reportCreditSql}) - SUM({$reportDebitSql}) as balance")
            ->value('balance') ?? 0);

        $closingBalance = $openingBalance + $totalCredits - $totalDebits;

        return $this->success([
            'report_type' => 'Fund Utilization Report',
            'report_currency' => $defaultCurrency,
            'fund' => [
                'code' => $fund->code,
                'name' => $fund->name,
                'type' => $fund->fund_type,
                'donor' => $fund->donor?->name,
                'restrictions' => $fund->restriction_purpose,
            ],
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'balances' => [
                'opening_balance' => $openingBalance,
                'total_inflows' => $totalCredits,
                'total_outflows' => $totalDebits,
                'closing_balance' => $closingBalance,
            ],
            'transactions' => $transactions->map(function ($txn) {
                return [
                    'date' => $txn->journalEntry->entry_date,
                    'entry_number' => $txn->journalEntry->entry_number,
                    'description' => $txn->description ?: $txn->journalEntry->description,
                    'account' => $txn->account?->account_name,
                    'debit' => self::lineReportDebit($txn),
                    'credit' => self::lineReportCredit($txn),
                ];
            }),
        ]);
    }

    /**
     * Generate compliance/audit report.
     */
    public function complianceReport(Request $request)
    {
        $validated = $request->validate([
            'donor_id' => 'nullable|exists:donors,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $orgId = $request->user()->organization_id;

        // Get all vouchers requiring approval
        $pendingApprovals = DB::table('vouchers')
            ->where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->count();

        // Get vouchers without proper approvals
        $incompleteApprovals = DB::table('vouchers')
            ->where('organization_id', $orgId)
            ->where('status', 'approved')
            ->whereBetween('created_at', [$validated['start_date'], $validated['end_date']])
            ->whereColumn('current_approval_level', '<', 'required_approval_level')
            ->count();

        // Unreconciled bank accounts
        $unreconciledAccounts = DB::table('bank_accounts')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereNull('last_reconciliation_date')
            ->orWhereRaw("last_reconciliation_date < DATE_SUB(NOW(), INTERVAL 30 DAY)")
            ->count();

        // Journal entries without supporting documents
        $entriesWithoutDocs = DB::table('journal_entries')
            ->where('organization_id', $orgId)
            ->where('status', 'posted')
            ->whereBetween('entry_date', [$validated['start_date'], $validated['end_date']])
            ->whereNull('attachment_path')
            ->count();

        // Budget overruns (spent_amount column added by migration; skip if not present)
        $budgetOverruns = 0;
        // #region agent log
        try {
            $connection = OfficeContext::connection();
            debug_log('complianceReport budget-overrun', ['connection' => $connection, 'orgId' => $orgId, '_loc' => 'DonorReportController::complianceReport'], 'H4');
            $hasSpent = Schema::connection($connection)->hasColumn('projects', 'spent_amount');
            $hasBudget = Schema::connection($connection)->hasColumn('projects', 'budget_amount');
            debug_log('complianceReport hasColumn', ['hasSpent' => $hasSpent, 'hasBudget' => $hasBudget, '_loc' => 'DonorReportController::complianceReport'], 'H4');
            if ($hasSpent && $hasBudget) {
                $budgetOverruns = Project::on($connection)->where('organization_id', $orgId)
                    ->whereRaw('COALESCE(spent_amount, 0) > COALESCE(budget_amount, 0)')
                    ->count();
                debug_log('complianceReport success', ['count' => $budgetOverruns, '_loc' => 'DonorReportController::complianceReport'], 'H4');
            }
        } catch (\Throwable $e) {
            debug_log('complianceReport caught', ['error' => $e->getMessage(), 'class' => get_class($e), '_loc' => 'DonorReportController::complianceReport'], 'H4');
            Log::warning('DonorReport compliance: budget overruns query failed', ['error' => $e->getMessage()]);
        }
        // #endregion agent log

        return $this->success([
            'report_type' => 'Compliance Report',
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'findings' => [
                [
                    'category' => 'Pending Approvals',
                    'count' => $pendingApprovals,
                    'severity' => $pendingApprovals > 10 ? 'high' : ($pendingApprovals > 5 ? 'medium' : 'low'),
                    'description' => 'Vouchers awaiting approval',
                ],
                [
                    'category' => 'Incomplete Approval Workflow',
                    'count' => $incompleteApprovals,
                    'severity' => $incompleteApprovals > 0 ? 'high' : 'low',
                    'description' => 'Approved vouchers where recorded approval level is below required level',
                ],
                [
                    'category' => 'Unreconciled Bank Accounts',
                    'count' => $unreconciledAccounts,
                    'severity' => $unreconciledAccounts > 0 ? 'medium' : 'low',
                    'description' => 'Bank accounts not reconciled in the last 30 days',
                ],
                [
                    'category' => 'Missing Documentation',
                    'count' => $entriesWithoutDocs,
                    'severity' => $entriesWithoutDocs > 20 ? 'medium' : 'low',
                    'description' => 'Posted journal entries without attachments',
                ],
                [
                    'category' => 'Budget Overruns',
                    'count' => $budgetOverruns,
                    'severity' => $budgetOverruns > 0 ? 'high' : 'low',
                    'description' => 'Projects exceeding approved budget',
                ],
            ],
            'overall_score' => $this->calculateComplianceScore(
                $pendingApprovals,
                $incompleteApprovals,
                $unreconciledAccounts,
                $entriesWithoutDocs,
                $budgetOverruns
            ),
        ]);
    }

    /**
     * Calculate compliance score.
     */
    private function calculateComplianceScore($pending, $incomplete, $unreconciled, $noDocs, $overruns): int
    {
        $score = 100;
        $score -= min(20, $pending * 2);
        $score -= min(30, $incomplete * 10);
        $score -= min(15, $unreconciled * 5);
        $score -= min(15, $noDocs);
        $score -= min(20, $overruns * 10);
        return max(0, $score);
    }
}
