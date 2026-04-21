<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Facades\Schema;
use App\Models\Project;
use App\Models\BankAccount;
use App\Models\CashAccount;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Builds a text summary of current finance context for the AI assistant.
 * Uses the same scope as the dashboard (organization + current office from OfficeContext).
 */
class AssistantContextService
{
    public function getContextForUser(Authenticatable $user): string
    {
        $orgId = $user->organization_id ?? null;
        if ($orgId === null || $orgId === '') {
            return "Organization ID: not set. User has no organization context.";
        }
        $orgId = (int) $orgId;
        $officeId = OfficeContext::getOfficeId();

        try {
            $summary = $this->getSummary($orgId, $officeId);
        } catch (\Throwable $e) {
            return "Organization ID: {$orgId}. Error loading financial data: " . $e->getMessage();
        }
        try {
            $cashPosition = $this->getCashPosition($orgId, $officeId);
            $budgetStatus = $this->getBudgetStatus($orgId, $officeId);
        } catch (\Throwable $e) {
            return "Organization ID: {$orgId}. Error loading financial data: " . $e->getMessage();
        }

        $officeLabel = $officeId ? " (office ID: {$officeId})" : ' (all offices)';
        $fmt = fn ($n) => number_format((float) $n, 2, '.', ',');
        $lines = [
            "Organization ID: {$orgId}{$officeLabel}",
            "---",
            "Summary: Vouchers this month: {$summary['vouchers_this_month']}, Pending approvals: {$summary['pending_approvals']}, Total liquidity (cash + bank): {$fmt($summary['total_liquidity'])}, Active projects: {$summary['active_projects']}.",
            "Cash balance: {$fmt($summary['cash_balance'])}. Bank balance: {$fmt($summary['bank_balance'])}.",
            "Budget: Total budget: {$fmt($budgetStatus['total_budget'])}, Total spent: {$fmt($budgetStatus['total_spent'])}, Available: {$fmt($budgetStatus['available'])}, Utilization rate: {$budgetStatus['utilization_rate']}%.",
            "Cash accounts: " . count($cashPosition['cash']['accounts']) . " accounts, total {$fmt($cashPosition['cash']['total'])}.",
            "Bank accounts: " . count($cashPosition['bank']['accounts']) . " accounts, total {$fmt($cashPosition['bank']['total'])}.",
        ];

        return implode("\n", $lines);
    }

    private function getSummary(int $orgId, ?int $officeId): array
    {
        $currentMonth = now()->startOfMonth();

        $currentMonthVouchers = Voucher::where('organization_id', $orgId)
            ->where('created_at', '>=', $currentMonth)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->count();

        $pendingApprovals = Voucher::where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->count();

        $cashBalance = CashAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->sum('current_balance');

        $bankBalance = BankAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->sum('current_balance');

        $activeProjects = Project::where('organization_id', $orgId)
            ->where('status', 'active')
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->count();

        return [
            'vouchers_this_month' => $currentMonthVouchers,
            'pending_approvals' => $pendingApprovals,
            'cash_balance' => $cashBalance,
            'bank_balance' => $bankBalance,
            'total_liquidity' => $cashBalance + $bankBalance,
            'active_projects' => $activeProjects,
        ];
    }

    private function getCashPosition(int $orgId, ?int $officeId): array
    {
        $cashQuery = CashAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId));
        $bankQuery = BankAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId));

        $cashRow = (clone $cashQuery)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(current_balance), 0) as total')->first();
        $bankRow = (clone $bankQuery)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(current_balance), 0) as total')->first();

        $cashCount = (int) ($cashRow->cnt ?? 0);
        $bankCount = (int) ($bankRow->cnt ?? 0);

        return [
            'cash' => [
                'total' => (float) ($cashRow->total ?? 0),
                'accounts' => array_fill(0, $cashCount, ['name' => 'Account', 'balance' => 0]),
            ],
            'bank' => [
                'total' => (float) ($bankRow->total ?? 0),
                'accounts' => array_fill(0, $bankCount, ['name' => 'Account', 'balance' => 0]),
            ],
        ];
    }

    private function getBudgetStatus(int $orgId, ?int $officeId): array
    {
        $connection = \App\Services\OfficeContext::connection();
        $query = Project::on($connection)->where('organization_id', $orgId)
            ->where('status', 'active')
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId));

        $totalBudget = 0.0;
        $totalSpent = 0.0;
        $totalCommitted = 0.0;
        if (Schema::connection($connection)->hasColumn('projects', 'spent_amount')) {
            $totals = (clone $query)->selectRaw('
                COALESCE(SUM(budget_amount), 0) as total_budget,
                COALESCE(SUM(spent_amount), 0) as total_spent,
                COALESCE(SUM(committed_amount), 0) as total_committed
            ')->first();
            $totalBudget = (float) ($totals->total_budget ?? 0);
            $totalSpent = (float) ($totals->total_spent ?? 0);
            $totalCommitted = (float) ($totals->total_committed ?? 0);
        } else {
            $totals = (clone $query)->selectRaw('COALESCE(SUM(budget_amount), 0) as total_budget')->first();
            $totalBudget = (float) ($totals->total_budget ?? 0);
        }
        $available = $totalBudget - $totalSpent - $totalCommitted;
        $utilizationRate = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 2) : 0;

        return [
            'total_budget' => $totalBudget,
            'total_spent' => $totalSpent,
            'total_committed' => $totalCommitted,
            'available' => $available,
            'utilization_rate' => $utilizationRate,
        ];
    }
}
