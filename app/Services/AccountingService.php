<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    /**
     * Get account balance.
     */
    public function getAccountBalance(int $accountId, ?string $startDate = null, ?string $endDate = null): float
    {
        $account = ChartOfAccount::findOrFail($accountId);
        return $account->getBalance($startDate, $endDate);
    }

    /**
     * Get trial balance.
     */
    public function getTrialBalance(int $organizationId, string $asOfDate): array
    {
        $accounts = ChartOfAccount::where('organization_id', $organizationId)
            ->where('is_posting', true)
            ->where('is_active', true)
            ->orderBy('account_code_sort')
            ->get();

        $trialBalance = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $balance = $account->getBalance(null, $asOfDate);

            if ($balance == 0) {
                continue;
            }

            $debit = 0;
            $credit = 0;

            if ($account->isDebitBalance()) {
                if ($balance >= 0) {
                    $debit = $balance;
                } else {
                    $credit = abs($balance);
                }
            } else {
                if ($balance >= 0) {
                    $credit = $balance;
                } else {
                    $debit = abs($balance);
                }
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            $trialBalance[] = [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_type' => $account->account_type,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
            ];
        }

        return [
            'as_of_date' => $asOfDate,
            'accounts' => $trialBalance,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'difference' => round($totalDebit - $totalCredit, 2),
        ];
    }

    /**
     * Get income statement.
     */
    public function getIncomeStatement(int $organizationId, string $startDate, string $endDate): array
    {
        $revenues = $this->getAccountTypeBalances($organizationId, 'revenue', $startDate, $endDate);
        $expenses = $this->getAccountTypeBalances($organizationId, 'expense', $startDate, $endDate);

        $totalRevenue = collect($revenues)->sum('balance');
        $totalExpenses = collect($expenses)->sum('balance');
        $netIncome = $totalRevenue - $totalExpenses;

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'revenues' => $revenues,
            'total_revenue' => round($totalRevenue, 2),
            'expenses' => $expenses,
            'total_expenses' => round($totalExpenses, 2),
            'net_income' => round($netIncome, 2),
        ];
    }

    /**
     * Get balance sheet.
     */
    public function getBalanceSheet(int $organizationId, string $asOfDate): array
    {
        $assets = $this->getAccountTypeBalances($organizationId, 'asset', null, $asOfDate);
        $liabilities = $this->getAccountTypeBalances($organizationId, 'liability', null, $asOfDate);
        $equity = $this->getAccountTypeBalances($organizationId, 'equity', null, $asOfDate);

        $totalAssets = collect($assets)->sum('balance');
        $totalLiabilities = collect($liabilities)->sum('balance');
        $totalEquity = collect($equity)->sum('balance');

        return [
            'as_of_date' => $asOfDate,
            'assets' => $assets,
            'total_assets' => round($totalAssets, 2),
            'liabilities' => $liabilities,
            'total_liabilities' => round($totalLiabilities, 2),
            'equity' => $equity,
            'total_equity' => round($totalEquity, 2),
            'total_liabilities_and_equity' => round($totalLiabilities + $totalEquity, 2),
        ];
    }

    /**
     * Get account type balances.
     */
    private function getAccountTypeBalances(int $organizationId, string $accountType, ?string $startDate, ?string $endDate): array
    {
        $accounts = ChartOfAccount::where('organization_id', $organizationId)
            ->where('account_type', $accountType)
            ->where('is_posting', true)
            ->where('is_active', true)
            ->orderBy('account_code_sort')
            ->get();

        $balances = [];

        foreach ($accounts as $account) {
            $balance = $account->getBalance($startDate, $endDate);

            if ($balance != 0) {
                $balances[] = [
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'balance' => round(abs($balance), 2),
                ];
            }
        }

        return $balances;
    }

    /**
     * Convert currency amount.
     */
    public function convertCurrency(float $amount, string $fromCurrency, string $toCurrency, int $organizationId, ?string $date = null): ?float
    {
        return ExchangeRate::convert($amount, $fromCurrency, $toCurrency, $organizationId, $date);
    }

    /**
     * Get budget vs actual comparison.
     */
    public function getBudgetVsActual(int $budgetId): array
    {
        // Implementation for budget vs actual comparison
        return [];
    }

    /**
     * Post journal entry.
     */
    public function postJournalEntry(JournalEntry $journalEntry): bool
    {
        if (!$journalEntry->canBePosted()) {
            return false;
        }

        return $journalEntry->post(auth()->user());
    }

    /**
     * Reverse journal entry.
     */
    public function reverseJournalEntry(JournalEntry $journalEntry, string $reversalDate): ?JournalEntry
    {
        if (!$journalEntry->canBeReversed()) {
            return null;
        }

        DB::beginTransaction();

        try {
            // Create reversal entry
            $reversalEntry = $journalEntry->replicate();
            $reversalEntry->entry_number = $this->generateReversalNumber($journalEntry);
            $reversalEntry->entry_date = $reversalDate;
            $reversalEntry->entry_type = 'reversing';
            $reversalEntry->reference = "Reversal of {$journalEntry->entry_number}";
            $reversalEntry->description = "Reversal: {$journalEntry->description}";
            $reversalEntry->status = 'draft';
            $reversalEntry->save();

            // Create reversed lines (swap debit and credit)
            foreach ($journalEntry->lines as $line) {
                $reversalEntry->lines()->create([
                    'account_id' => $line->account_id,
                    'fund_id' => $line->fund_id,
                    'project_id' => $line->project_id,
                    'office_id' => $line->office_id,
                    'line_number' => $line->line_number,
                    'description' => $line->description,
                    'debit_amount' => $line->credit_amount,
                    'credit_amount' => $line->debit_amount,
                    'currency' => $line->currency,
                    'exchange_rate' => $line->exchange_rate,
                    'base_currency_debit' => $line->base_currency_credit,
                    'base_currency_credit' => $line->base_currency_debit,
                ]);
            }

            // Link original entry to reversal
            $journalEntry->update([
                'reversal_entry_id' => $reversalEntry->id,
                'reversed_by' => auth()->id(),
                'reversed_at' => now(),
                'status' => 'reversed',
            ]);

            DB::commit();

            return $reversalEntry;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Generate reversal number.
     */
    private function generateReversalNumber(JournalEntry $originalEntry): string
    {
        return "REV-{$originalEntry->entry_number}";
    }
}
