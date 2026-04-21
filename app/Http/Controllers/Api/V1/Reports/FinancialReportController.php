<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialReportController extends Controller
{
    /**
     * SQL expression for debit in report (org default) currency.
     * Uses base_currency_debit when set, else debit_amount * exchange_rate.
     */
    private static function reportDebitSql(): string
    {
        return "COALESCE(NULLIF(base_currency_debit, 0), debit_amount * COALESCE(exchange_rate, 1))";
    }

    /**
     * SQL expression for credit in report (org default) currency.
     */
    private static function reportCreditSql(): string
    {
        return "COALESCE(NULLIF(base_currency_credit, 0), credit_amount * COALESCE(exchange_rate, 1))";
    }
    /**
     * Generate Trial Balance report.
     */
    public function trialBalance(Request $request)
    {
        $validated = $request->validate([
            'as_of_date' => 'required|date',
            'office_id' => 'nullable|exists:offices,id',
            'fund_id' => 'nullable|exists:funds,id',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $orgId = $request->user()->organization_id;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        $query = ChartOfAccount::where('organization_id', $orgId)
            ->where('is_active', true);
        $conn = config('database.default');
        if (Schema::connection($conn)->hasColumn('chart_of_accounts', 'is_posting')) {
            $query->where('is_posting', true);
        }
        $accounts = $query->orderBy('account_code_sort')->get();

        $reportData = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $query = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($validated, $orgId) {
                    $q->where('organization_id', $orgId)
                      ->where('status', 'posted')
                      ->where('entry_date', '<=', $validated['as_of_date']);
                });

            if (isset($validated['office_id'])) {
                $query->whereHas('journalEntry', fn($q) => $q->where('office_id', $validated['office_id']));
            }

            if (isset($validated['fund_id'])) {
                $query->where('fund_id', $validated['fund_id']);
            }

            if (isset($validated['project_id'])) {
                $query->where('project_id', $validated['project_id']);
            }

            $balance = $query->selectRaw(
                'SUM(' . self::reportDebitSql() . ') as total_debit, SUM(' . self::reportCreditSql() . ') as total_credit'
            )->first();

            $debit = (float) ($balance->total_debit ?? 0);
            $credit = (float) ($balance->total_credit ?? 0);
            $netBalance = $debit - $credit;

            // Determine debit/credit balance based on normal balance
            $debitBalance = 0;
            $creditBalance = 0;

            if ($account->normal_balance === 'debit') {
                if ($netBalance >= 0) {
                    $debitBalance = $netBalance;
                } else {
                    $creditBalance = abs($netBalance);
                }
            } else {
                if ($netBalance <= 0) {
                    $creditBalance = abs($netBalance);
                } else {
                    $debitBalance = $netBalance;
                }
            }

            if ($debitBalance != 0 || $creditBalance != 0) {
                $reportData[] = [
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'account_type' => $account->account_type,
                    'debit' => $debitBalance,
                    'credit' => $creditBalance,
                ];

                $totalDebit += $debitBalance;
                $totalCredit += $creditBalance;
            }
        }

        return $this->success([
            'report_type' => 'Trial Balance',
            'as_of_date' => $validated['as_of_date'],
            'report_currency' => $defaultCurrency,
            'accounts' => $reportData,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'balanced' => abs($totalDebit - $totalCredit) < 0.01,
            ],
        ]);
    }

    /**
     * Generate Income Statement (Profit & Loss).
     */
    public function incomeStatement(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'office_id' => 'nullable|exists:offices,id',
            'project_id' => 'nullable|exists:projects,id',
            'fund_id' => 'nullable|exists:funds,id',
        ]);

        $orgId = $request->user()->organization_id;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        // Revenue accounts (type: revenue)
        $revenueAccounts = $this->getAccountBalances(
            $orgId,
            'revenue',
            $validated['start_date'],
            $validated['end_date'],
            $validated['office_id'] ?? null,
            $validated['project_id'] ?? null,
            $validated['fund_id'] ?? null
        );

        // Expense accounts (type: expense)
        $expenseAccounts = $this->getAccountBalances(
            $orgId,
            'expense',
            $validated['start_date'],
            $validated['end_date'],
            $validated['office_id'] ?? null,
            $validated['project_id'] ?? null,
            $validated['fund_id'] ?? null
        );

        $totalRevenue = collect($revenueAccounts)->sum('balance');
        $totalExpenses = collect($expenseAccounts)->sum('balance');
        $netIncome = $totalRevenue - $totalExpenses;

        return $this->success([
            'report_type' => 'Income Statement',
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'report_currency' => $defaultCurrency,
            'revenue' => [
                'accounts' => $revenueAccounts,
                'total' => $totalRevenue,
            ],
            'expenses' => [
                'accounts' => $expenseAccounts,
                'total' => $totalExpenses,
            ],
            'net_income' => $netIncome,
            'net_income_label' => $netIncome >= 0 ? 'Net Surplus' : 'Net Deficit',
        ]);
    }

    /**
     * Generate Balance Sheet.
     */
    public function balanceSheet(Request $request)
    {
        $validated = $request->validate([
            'as_of_date' => 'required|date',
            'office_id' => 'nullable|exists:offices,id',
            'fund_id' => 'nullable|exists:funds,id',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $orgId = $request->user()->organization_id;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        $projectId = $validated['project_id'] ?? null;

        // Assets
        $assets = $this->getAccountBalancesAsOf(
            $orgId,
            'asset',
            $validated['as_of_date'],
            $validated['office_id'] ?? null,
            $validated['fund_id'] ?? null,
            $projectId
        );

        // Liabilities
        $liabilities = $this->getAccountBalancesAsOf(
            $orgId,
            'liability',
            $validated['as_of_date'],
            $validated['office_id'] ?? null,
            $validated['fund_id'] ?? null,
            $projectId
        );

        // Equity
        $equity = $this->getAccountBalancesAsOf(
            $orgId,
            'equity',
            $validated['as_of_date'],
            $validated['office_id'] ?? null,
            $validated['fund_id'] ?? null,
            $projectId
        );

        // Calculate retained earnings (revenue - expenses for current period)
        $retainedEarnings = $this->calculateRetainedEarnings(
            $orgId,
            $validated['as_of_date'],
            $validated['office_id'] ?? null,
            $validated['fund_id'] ?? null,
            $projectId
        );

        $totalAssets = collect($assets)->sum('balance');
        $totalLiabilities = collect($liabilities)->sum('balance');
        $totalEquity = collect($equity)->sum('balance') + $retainedEarnings;

        return $this->success([
            'report_type' => 'Balance Sheet',
            'as_of_date' => $validated['as_of_date'],
            'report_currency' => $defaultCurrency,
            'assets' => [
                'accounts' => $assets,
                'total' => $totalAssets,
            ],
            'liabilities' => [
                'accounts' => $liabilities,
                'total' => $totalLiabilities,
            ],
            'equity' => [
                'accounts' => $equity,
                'retained_earnings' => $retainedEarnings,
                'total' => $totalEquity,
            ],
            'total_liabilities_and_equity' => $totalLiabilities + $totalEquity,
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ]);
    }

    /**
     * Generate Cash Flow Statement.
     */
    public function cashFlow(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'office_id' => 'nullable|exists:offices,id',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $orgId = $request->user()->organization_id;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        $projectId = $validated['project_id'] ?? null;

        // Get cash account movements
        $cashAccounts = ChartOfAccount::where('organization_id', $orgId)
            ->where('account_type', 'asset')
            ->where(function ($q) {
                $q->where('account_code', 'like', '1%') // Cash and bank accounts
                  ->where(function ($q2) {
                      $q2->where('account_name', 'like', '%cash%')
                         ->orWhere('account_name', 'like', '%bank%');
                  });
            })
            ->pluck('id');

        // Operating activities (simplified)
        $operatingCash = $this->getCashMovement(
            $orgId,
            $validated['start_date'],
            $validated['end_date'],
            $cashAccounts,
            'operating',
            $validated['office_id'] ?? null,
            $projectId
        );

        // Investing activities
        $investingCash = $this->getCashMovement(
            $orgId,
            $validated['start_date'],
            $validated['end_date'],
            $cashAccounts,
            'investing',
            $validated['office_id'] ?? null,
            $projectId
        );

        // Financing activities
        $financingCash = $this->getCashMovement(
            $orgId,
            $validated['start_date'],
            $validated['end_date'],
            $cashAccounts,
            'financing',
            $validated['office_id'] ?? null,
            $projectId
        );

        // Opening and closing balances
        $openingBalance = $this->getCashBalance($orgId, $cashAccounts, $validated['start_date'], true, $projectId);
        $closingBalance = $this->getCashBalance($orgId, $cashAccounts, $validated['end_date'], false, $projectId);

        $netChange = $operatingCash + $investingCash + $financingCash;

        return $this->success([
            'report_type' => 'Cash Flow Statement',
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'report_currency' => $defaultCurrency,
            'opening_balance' => $openingBalance,
            'operating_activities' => $operatingCash,
            'investing_activities' => $investingCash,
            'financing_activities' => $financingCash,
            'net_change_in_cash' => $netChange,
            'closing_balance' => $closingBalance,
        ]);
    }

    /**
     * Generate General Ledger report.
     */
    public function generalLedger(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'required|exists:chart_of_accounts,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'project_id' => 'nullable|exists:projects,id',
            'office_id' => 'nullable|exists:offices,id',
        ]);

        $orgId = $request->user()->organization_id;
        $projectId = $validated['project_id'] ?? null;
        $officeId = $validated['office_id'] ?? null;
        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'USD';

        $account = ChartOfAccount::where('id', $validated['account_id'])
            ->where('organization_id', $orgId)
            ->first();

        if (!$account) {
            return $this->error('Account not found', 404);
        }

        $openingQuery = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($validated, $orgId, $officeId) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->where('entry_date', '<', $validated['start_date']);
                if ($officeId) {
                    $q->where('office_id', $officeId);
                }
            });
        if ($projectId) {
            $openingQuery->where('project_id', $projectId);
        }
        if ($officeId) {
            $openingQuery->where('office_id', $officeId);
        }
        $openingBalance = (float) ($openingQuery->selectRaw('SUM(' . self::reportDebitSql() . ') - SUM(' . self::reportCreditSql() . ') as balance')->value('balance') ?? 0);

        $transactionsQuery = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($validated, $orgId, $officeId) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->whereBetween('entry_date', [$validated['start_date'], $validated['end_date']]);
                if ($officeId) {
                    $q->where('office_id', $officeId);
                }
            });
        if ($projectId) {
            $transactionsQuery->where('project_id', $projectId);
        }
        if ($officeId) {
            $transactionsQuery->where('office_id', $officeId);
        }
        $transactions = $transactionsQuery
            ->with('journalEntry:id,entry_number,entry_date,description')
            ->orderBy('created_at')
            ->get();

        // Amount in report currency per line (base when set, else amount * rate)
        $runningBalance = $openingBalance;
        $transactionsWithBalance = $transactions->map(function ($txn) use (&$runningBalance) {
            $lineDebit = (float) ($txn->base_currency_debit ?: ($txn->debit_amount * ($txn->exchange_rate ?: 1)));
            $lineCredit = (float) ($txn->base_currency_credit ?: ($txn->credit_amount * ($txn->exchange_rate ?: 1)));
            $runningBalance += $lineDebit - $lineCredit;
            $arr = $txn->toArray();
            $arr['report_debit'] = $lineDebit;
            $arr['report_credit'] = $lineCredit;
            $arr['running_balance'] = $runningBalance;
            return $arr;
        });

        $totalReportDebit = $transactions->sum(fn ($t) => (float) ($t->base_currency_debit ?: $t->debit_amount * ($t->exchange_rate ?: 1)));
        $totalReportCredit = $transactions->sum(fn ($t) => (float) ($t->base_currency_credit ?: $t->credit_amount * ($t->exchange_rate ?: 1)));

        return $this->success([
            'report_type' => 'General Ledger',
            'report_currency' => $defaultCurrency,
            'account' => [
                'code' => $account->account_code,
                'name' => $account->account_name,
            ],
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'opening_balance' => $openingBalance,
            'transactions' => $transactionsWithBalance,
            'closing_balance' => $runningBalance,
            'total_debit' => $totalReportDebit,
            'total_credit' => $totalReportCredit,
        ]);
    }

    /**
     * Helper: Get account balances for a period.
     */
    private function getAccountBalances($orgId, $accountType, $startDate, $endDate, $officeId = null, $projectId = null, $fundId = null)
    {
        $accounts = ChartOfAccount::where('organization_id', $orgId)
            ->where('account_type', $accountType)
            ->where('is_active', true)
            ->orderBy('account_code_sort')
            ->get();

        $result = [];

        foreach ($accounts as $account) {
            $query = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($orgId, $startDate, $endDate) {
                    $q->where('organization_id', $orgId)
                      ->where('status', 'posted')
                      ->whereBetween('entry_date', [$startDate, $endDate]);
                });

            if ($officeId) {
                $query->whereHas('journalEntry', fn($q) => $q->where('office_id', $officeId));
            }
            if ($projectId) {
                $query->where('project_id', $projectId);
            }
            if ($fundId) {
                $query->where('fund_id', $fundId);
            }

            $totals = $query->selectRaw(
                'SUM(' . self::reportDebitSql() . ') as debit, SUM(' . self::reportCreditSql() . ') as credit'
            )->first();

            $balance = $accountType === 'revenue'
                ? (float) ($totals->credit ?? 0) - (float) ($totals->debit ?? 0)
                : (float) ($totals->debit ?? 0) - (float) ($totals->credit ?? 0);

            if ($balance != 0) {
                $result[] = [
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'balance' => abs($balance),
                ];
            }
        }

        return $result;
    }

    /**
     * Helper: Get account balances as of date.
     */
    private function getAccountBalancesAsOf($orgId, $accountType, $asOfDate, $officeId = null, $fundId = null, $projectId = null)
    {
        $accounts = ChartOfAccount::where('organization_id', $orgId)
            ->where('account_type', $accountType)
            ->where('is_active', true)
            ->orderBy('account_code_sort')
            ->get();

        $result = [];

        foreach ($accounts as $account) {
            $query = JournalEntryLine::where('account_id', $account->id)
                ->whereHas('journalEntry', function ($q) use ($orgId, $asOfDate) {
                    $q->where('organization_id', $orgId)
                      ->where('status', 'posted')
                      ->where('entry_date', '<=', $asOfDate);
                });

            if ($officeId) {
                $query->whereHas('journalEntry', fn($q) => $q->where('office_id', $officeId));
            }
            if ($fundId) {
                $query->where('fund_id', $fundId);
            }
            if ($projectId) {
                $query->where('project_id', $projectId);
            }

            $totals = $query->selectRaw(
                'SUM(' . self::reportDebitSql() . ') as debit, SUM(' . self::reportCreditSql() . ') as credit'
            )->first();

            $balance = in_array($accountType, ['asset', 'expense'])
                ? (float) ($totals->debit ?? 0) - (float) ($totals->credit ?? 0)
                : (float) ($totals->credit ?? 0) - (float) ($totals->debit ?? 0);

            if ($balance != 0) {
                $result[] = [
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'balance' => abs($balance),
                ];
            }
        }

        return $result;
    }

    /**
     * Helper: Calculate retained earnings.
     */
    private function calculateRetainedEarnings($orgId, $asOfDate, $officeId = null, $fundId = null, $projectId = null)
    {
        // Revenue
        $revenue = ChartOfAccount::where('organization_id', $orgId)
            ->where('account_type', 'revenue')
            ->pluck('id');

        $revenueQuery = JournalEntryLine::whereIn('account_id', $revenue)
            ->whereHas('journalEntry', function ($q) use ($orgId, $asOfDate) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->where('entry_date', '<=', $asOfDate);
            });

        if ($officeId) {
            $revenueQuery->whereHas('journalEntry', fn($q) => $q->where('office_id', $officeId));
        }
        if ($fundId) {
            $revenueQuery->where('fund_id', $fundId);
        }
        if ($projectId) {
            $revenueQuery->where('project_id', $projectId);
        }

        $totalRevenue = (float) ($revenueQuery->selectRaw(
            'SUM(' . self::reportCreditSql() . ') - SUM(' . self::reportDebitSql() . ') as balance'
        )->value('balance') ?? 0);

        // Expenses
        $expenses = ChartOfAccount::where('organization_id', $orgId)
            ->where('account_type', 'expense')
            ->pluck('id');

        $expenseQuery = JournalEntryLine::whereIn('account_id', $expenses)
            ->whereHas('journalEntry', function ($q) use ($orgId, $asOfDate) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->where('entry_date', '<=', $asOfDate);
            });

        if ($officeId) {
            $expenseQuery->whereHas('journalEntry', fn($q) => $q->where('office_id', $officeId));
        }
        if ($fundId) {
            $expenseQuery->where('fund_id', $fundId);
        }
        if ($projectId) {
            $expenseQuery->where('project_id', $projectId);
        }

        $totalExpenses = (float) ($expenseQuery->selectRaw(
            'SUM(' . self::reportDebitSql() . ') - SUM(' . self::reportCreditSql() . ') as balance'
        )->value('balance') ?? 0);

        return $totalRevenue - $totalExpenses;
    }

    /**
     * Helper: Get cash movement.
     */
    private function getCashMovement($orgId, $startDate, $endDate, $cashAccounts, $activityType, $officeId = null, $projectId = null)
    {
        // Simplified: return total cash movement for the period
        // In a real implementation, would categorize by activity type
        $query = JournalEntryLine::whereIn('account_id', $cashAccounts)
            ->whereHas('journalEntry', function ($q) use ($orgId, $startDate, $endDate) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted')
                  ->whereBetween('entry_date', [$startDate, $endDate]);
            });

        if ($officeId) {
            $query->whereHas('journalEntry', fn($q) => $q->where('office_id', $officeId));
        }
        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        $totals = $query->selectRaw(
            'SUM(' . self::reportDebitSql() . ') as debit, SUM(' . self::reportCreditSql() . ') as credit'
        )->first();

        // For operating activities, return net change (simplified)
        if ($activityType === 'operating') {
            return (float) ($totals->debit ?? 0) - (float) ($totals->credit ?? 0);
        }

        return 0; // Investing and financing would need more specific logic
    }

    /**
     * Helper: Get cash balance.
     */
    private function getCashBalance($orgId, $cashAccounts, $date, $before = true, $projectId = null)
    {
        $query = JournalEntryLine::whereIn('account_id', $cashAccounts)
            ->whereHas('journalEntry', function ($q) use ($orgId, $date, $before) {
                $q->where('organization_id', $orgId)
                  ->where('status', 'posted');

                if ($before) {
                    $q->where('entry_date', '<', $date);
                } else {
                    $q->where('entry_date', '<=', $date);
                }
            });

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        return (float) ($query->selectRaw(
            'SUM(' . self::reportDebitSql() . ') - SUM(' . self::reportCreditSql() . ') as balance'
        )->value('balance') ?? 0);
    }
}
