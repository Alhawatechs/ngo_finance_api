<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Services\OfficeContext;
use App\Models\Grant;
use App\Models\BankAccount;
use App\Models\CashAccount;
use App\Models\ExchangeRate;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard overview (cached 60s to reduce load).
     */
    public function index(Request $request)
    {
        // #region agent log
        debug_log('Dashboard index entry', ['orgId' => $request->user()?->organization_id, '_loc' => 'DashboardController::index'], 'H1');
        // #endregion agent log
        $orgId = $request->user()->organization_id;
        $officeId = $request->office_id ?? 0;
        $cacheKey = "dashboard_overview_{$orgId}_{$officeId}";

        $data = Cache::remember($cacheKey, 60, function () use ($orgId, $officeId) {
            $safe = function (callable $fn, $default = [], string $section = '') {
                try {
                    return $fn();
                } catch (\Throwable $e) {
                    // #region agent log
                    debug_log('index section failed', ['section' => $section, 'error' => $e->getMessage(), 'class' => get_class($e), '_loc' => 'DashboardController::index'], 'H5');
                    // #endregion agent log
                    Log::warning('Dashboard section failed', ['error' => $e->getMessage()]);
                    return $default;
                }
            };
            return [
                'summary' => $safe(fn () => $this->getSummary($orgId, $officeId ?: null), [], 'summary'),
                'recent_vouchers' => $safe(fn () => $this->getRecentVouchers($orgId, $officeId ?: null), [], 'recent_vouchers'),
                'pending_approvals' => $safe(fn () => $this->getPendingApprovals($orgId, $officeId ?: null), [], 'pending_approvals'),
                'cash_position' => $safe(fn () => $this->getCashPosition($orgId, $officeId ?: null), [], 'cash_position'),
                'budget_status' => $safe(fn () => $this->getBudgetStatus($orgId, $officeId ?: null), [], 'budget_status'),
            ];
        });

        // #region agent log
        debug_log('Dashboard index success', ['_loc' => 'DashboardController::index'], 'H1');
        // #endregion agent log
        return $this->success($data);
    }

    /**
     * Get financial summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $officeId = $request->office_id;

        return $this->success($this->getSummary($orgId, $officeId));
    }

    /**
     * Get cash and bank position.
     */
    public function cashPosition(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $officeId = $request->office_id;

        return $this->success($this->getCashPosition($orgId, $officeId));
    }

    /**
     * Get monthly trends data (single query grouped by month, cached 2 min).
     */
    public function trends(Request $request)
    {
        // #region agent log
        debug_log('Dashboard trends entry', ['orgId' => $request->user()?->organization_id, '_loc' => 'DashboardController::trends'], 'H3');
        // #endregion agent log
        $orgId = $request->user()->organization_id;
        $months = (int) $request->input('months', 12);
        $months = min(max($months, 1), 24);
        $cacheKey = "dashboard_trends_{$orgId}_{$months}";

        $result = Cache::remember($cacheKey, 120, function () use ($orgId, $months) {
            $startDate = now()->subMonths($months)->startOfMonth();
            $emptyResult = [
                'period' => ['start' => $startDate->format('Y-m-d'), 'end' => now()->format('Y-m-d')],
                'monthly_data' => [],
                'totals' => ['revenue' => 0, 'expenses' => 0, 'net' => 0],
            ];

            try {
                // #region agent log
                debug_log('trends: before journal_entry_lines query', ['orgId' => $orgId, '_loc' => 'DashboardController::trends'], 'H3');
                // #endregion agent log
                $rows = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_entry_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.organization_id', $orgId)
            ->where('journal_entries.status', 'posted')
            ->whereIn('chart_of_accounts.account_type', ['revenue', 'expense'])
            ->whereBetween('journal_entries.entry_date', [$startDate, now()])
            ->select(
                DB::raw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') as month_key"),
                'chart_of_accounts.account_type',
                DB::raw('SUM(journal_entry_lines.debit_amount) as total_debit'),
                DB::raw('SUM(journal_entry_lines.credit_amount) as total_credit')
            )
            ->groupBy('month_key', 'chart_of_accounts.account_type')
            ->get();
                // #region agent log
                debug_log('trends: after journal query', ['rowCount' => $rows->count(), '_loc' => 'DashboardController::trends'], 'H3');
                // #endregion agent log

            $byMonth = [];
            foreach ($rows as $r) {
                if (!isset($byMonth[$r->month_key])) {
                    $byMonth[$r->month_key] = ['revenue' => 0, 'expenses' => 0];
                }
                if ($r->account_type === 'revenue') {
                    $byMonth[$r->month_key]['revenue'] = round(($r->total_credit ?? 0) - ($r->total_debit ?? 0), 2);
                } else {
                    $byMonth[$r->month_key]['expenses'] = round(($r->total_debit ?? 0) - ($r->total_credit ?? 0), 2);
                }
            }

            $monthlyData = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $monthStart = now()->subMonths($i)->startOfMonth();
                $key = $monthStart->format('Y-m');
                $revenue = $byMonth[$key]['revenue'] ?? 0;
                $expenses = $byMonth[$key]['expenses'] ?? 0;
                $monthlyData[] = [
                    'month' => $monthStart->format('M Y'),
                    'revenue' => $revenue,
                    'expenses' => $expenses,
                    'net' => round($revenue - $expenses, 2),
                ];
            }

            return [
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => now()->format('Y-m-d'),
                ],
                'monthly_data' => $monthlyData,
                'totals' => [
                    'revenue' => collect($monthlyData)->sum('revenue'),
                    'expenses' => collect($monthlyData)->sum('expenses'),
                    'net' => collect($monthlyData)->sum('net'),
                ],
            ];
            } catch (\Throwable $e) {
                // #region agent log
                debug_log('trends: caught', ['error' => $e->getMessage(), 'class' => get_class($e), '_loc' => 'DashboardController::trends'], 'H3');
                // #endregion agent log
                Log::warning('Dashboard trends query failed', ['error' => $e->getMessage()]);
                return $emptyResult;
            }
        });

        return $this->success($result);
    }

    /**
     * Get project status overview.
     */
    public function projectStatus(Request $request)
    {
        $orgId = $request->user()->organization_id;

        try {
            $connection = OfficeContext::connection();
            $projects = Project::on($connection)->where('organization_id', $orgId)
                ->with(['grant:id,grant_code,grant_name', 'office:id,name'])
                ->get();
        } catch (\Throwable $e) {
            Log::warning('Dashboard projectStatus failed', ['error' => $e->getMessage()]);
            return $this->success([
                'total_projects' => 0,
                'active_projects' => 0,
                'total_budget' => 0,
                'total_spent' => 0,
                'by_status' => [],
                'top_projects' => [],
            ]);
        }

        $byStatus = $projects->groupBy('status')->map(function ($group) {
            return [
                'count' => $group->count(),
                'budget' => $group->sum('total_budget'),
                'spent' => $group->sum('spent_amount'),
            ];
        });

        // Top 5 projects by budget utilization (active only)
        $topProjects = $projects
            ->where('status', 'active')
            ->map(function ($p) {
                $utilization = $p->total_budget > 0 
                    ? round(($p->spent_amount / $p->total_budget) * 100, 2) 
                    : 0;
                return [
                    'id' => $p->id,
                    'code' => $p->project_code,
                    'name' => $p->project_name,
                    'budget' => $p->total_budget,
                    'spent' => $p->spent_amount,
                    'utilization' => $utilization,
                    'office' => $p->office?->name,
                ];
            })
            ->sortByDesc('spent')
            ->take(5)
            ->values();

        return $this->success([
            'total_projects' => $projects->count(),
            'active_projects' => $projects->where('status', 'active')->count(),
            'total_budget' => $projects->sum('total_budget'),
            'total_spent' => $projects->sum('spent_amount'),
            'by_status' => $byStatus,
            'top_projects' => $topProjects,
        ]);
    }

    /**
     * Get fund allocation overview.
     */
    public function fundAllocation(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $funds = DB::table('funds')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->get();

        $byType = $funds->groupBy('fund_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total_amount' => $group->sum('total_amount'),
                'current_balance' => $group->sum('current_balance'),
            ];
        });

        return $this->success([
            'total_funds' => $funds->count(),
            'total_amount' => $funds->sum('total_amount'),
            'total_balance' => $funds->sum('current_balance'),
            'by_type' => $byType,
        ]);
    }

    /**
     * Get alerts and notifications.
     */
    public function alerts(Request $request)
    {
        // #region agent log
        debug_log('Dashboard alerts entry', ['orgId' => $request->user()?->organization_id, '_loc' => 'DashboardController::alerts'], 'H2');
        // #endregion agent log
        $orgId = $request->user()->organization_id;

        $alerts = [];

        // Pending approvals
        $pendingVouchers = Voucher::where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->count();
        if ($pendingVouchers > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Pending Approvals',
                'message' => "{$pendingVouchers} vouchers awaiting approval",
                'link' => '/approvals',
            ];
        }

        // Expiring grants (within 30 days)
        $expiringGrants = Grant::where('organization_id', $orgId)
            ->where('status', 'active')
            ->whereBetween('end_date', [now(), now()->addDays(30)])
            ->count();
        if ($expiringGrants > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Expiring Grants',
                'message' => "{$expiringGrants} grants expiring within 30 days",
                'link' => '/projects/grants',
            ];
        }

        // Budget overruns (projects: budget_amount or total_budget, spent_amount if migration run)
        $overrunProjects = 0;
        try {
            $connection = OfficeContext::connection();
            $schema = \Illuminate\Support\Facades\Schema::connection($connection);
            $hasSpent = $schema->hasColumn('projects', 'spent_amount');
            $hasBudget = $schema->hasColumn('projects', 'budget_amount')
                || $schema->hasColumn('projects', 'total_budget');
            if ($hasSpent && $hasBudget) {
                $budgetCol = $schema->hasColumn('projects', 'budget_amount')
                    ? 'budget_amount' : 'total_budget';
                $overrunProjects = Project::on($connection)->where('organization_id', $orgId)
                    ->whereRaw("COALESCE(spent_amount, 0) > COALESCE({$budgetCol}, 0)")
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard alerts: budget overruns query failed', ['error' => $e->getMessage()]);
            $overrunProjects = 0;
        }
        if ($overrunProjects > 0) {
            $alerts[] = [
                'type' => 'error',
                'title' => 'Budget Overruns',
                'message' => "{$overrunProjects} projects have exceeded their budget",
                'link' => '/projects',
            ];
        }

        // Low cash balance (skip if minimum_balance column does not exist)
        $lowCashAccounts = 0;
        try {
            $connection = config('database.default');
            if (\Illuminate\Support\Facades\Schema::connection($connection)->hasColumn('cash_accounts', 'minimum_balance')) {
                $lowCashAccounts = CashAccount::where('organization_id', $orgId)
                    ->where('is_active', true)
                    ->whereRaw('current_balance < minimum_balance')
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard alerts: low cash query failed', ['error' => $e->getMessage()]);
        }
        if ($lowCashAccounts > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Low Cash Balance',
                'message' => "{$lowCashAccounts} cash accounts below minimum balance",
                'link' => '/treasury/cash',
            ];
        }

        // #region agent log
        debug_log('Dashboard alerts success', ['alertsCount' => count($alerts), '_loc' => 'DashboardController::alerts'], 'H2');
        // #endregion agent log
        return $this->success($alerts);
    }

    /**
     * Get activity feed.
     */
    public function activityFeed(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $limit = $request->input('limit', 20);

        // Get recent vouchers
        $recentVouchers = Voucher::where('organization_id', $orgId)
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($v) {
                return [
                    'type' => 'voucher',
                    'action' => $v->status === 'draft' ? 'created' : $v->status,
                    'title' => "Voucher {$v->voucher_number}",
                    'description' => $v->description,
                    'user' => $v->creator?->name,
                    'amount' => $v->total_amount,
                    'timestamp' => $v->created_at,
                ];
            });

        // Get recent journal entries
        $recentEntries = JournalEntry::where('organization_id', $orgId)
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($e) {
                return [
                    'type' => 'journal_entry',
                    'action' => $e->status,
                    'title' => "Journal Entry {$e->entry_number}",
                    'description' => $e->description,
                    'user' => $e->creator?->name,
                    'amount' => $e->total_debit,
                    'timestamp' => $e->created_at,
                ];
            });

        // Merge and sort by timestamp
        $activities = $recentVouchers->concat($recentEntries)
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values();

        return $this->success($activities);
    }

    // Helper methods

    private function getSummary($orgId, $officeId = null)
    {
        $currentMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        // Current month vouchers
        $currentMonthVouchers = Voucher::where('organization_id', $orgId)
            ->where('created_at', '>=', $currentMonth)
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->count();

        // Pending approvals
        $pendingApprovals = Voucher::where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->count();

        // Cash and bank accounts for liquidity (multi-currency aware)
        $cashAccounts = CashAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->get();
        $bankAccounts = BankAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->get();

        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'AFN';
        $liquidityByCurrency = collect($cashAccounts)->concat($bankAccounts)
            ->groupBy('currency')
            ->map(fn ($group) => round($group->sum('current_balance'), 2))
            ->map(fn ($total, $code) => ['currency' => $code, 'total' => $total])
            ->values()
            ->toArray();
        $totalLiquidityBase = $cashAccounts->sum(function ($a) use ($orgId, $defaultCurrency) {
            $converted = ExchangeRate::convert((float) $a->current_balance, $a->currency, $defaultCurrency, $orgId);
            return $converted ?? (float) $a->current_balance;
        }) + $bankAccounts->sum(function ($a) use ($orgId, $defaultCurrency) {
            $converted = ExchangeRate::convert((float) $a->current_balance, $a->currency, $defaultCurrency, $orgId);
            return $converted ?? (float) $a->current_balance;
        });

        // Active projects
        $connection = OfficeContext::connection();
        $activeProjects = Project::on($connection)->where('organization_id', $orgId)
            ->where('status', 'active')
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->count();

        return [
            'vouchers_this_month' => $currentMonthVouchers,
            'pending_approvals' => $pendingApprovals,
            'cash_balance' => $cashAccounts->sum('current_balance'),
            'bank_balance' => $bankAccounts->sum('current_balance'),
            'total_liquidity' => $cashAccounts->sum('current_balance') + $bankAccounts->sum('current_balance'),
            'total_liquidity_base' => round($totalLiquidityBase, 2),
            'liquidity_by_currency' => $liquidityByCurrency,
            'default_currency' => $defaultCurrency,
            'active_projects' => $activeProjects,
        ];
    }

    private function getRecentVouchers($orgId, $officeId = null)
    {
        return Voucher::where('organization_id', $orgId)
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->with(['office:id,name', 'creator:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($v) {
                return [
                    'id' => $v->id,
                    'voucher_number' => $v->voucher_number,
                    'type' => $v->voucher_type,
                    'payee' => $v->payee_name,
                    'amount' => $v->total_amount,
                    'currency' => $v->currency ?? 'AFN',
                    'status' => $v->status,
                    'date' => $v->voucher_date,
                    'office' => $v->office?->name,
                ];
            });
    }

    private function getPendingApprovals($orgId, $officeId = null)
    {
        return Voucher::where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->with(['office:id,name'])
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(function ($v) {
                return [
                    'id' => $v->id,
                    'voucher_number' => $v->voucher_number,
                    'type' => $v->voucher_type,
                    'amount' => $v->total_amount,
                    'currency' => $v->currency ?? 'AFN',
                    'current_level' => $v->current_approval_level,
                    'created_at' => $v->created_at,
                    'office' => $v->office?->name,
                ];
            });
    }

    private function getCashPosition($orgId, $officeId = null)
    {
        $cashAccounts = CashAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->with('office:id,name')
            ->get();

        $bankAccounts = BankAccount::where('organization_id', $orgId)
            ->where('is_active', true)
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->with('office:id,name')
            ->get();

        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'AFN';

        $cashByCurrency = $cashAccounts->groupBy('currency')->map(fn ($group) => $group->sum('current_balance'))->toArray();
        $bankByCurrency = $bankAccounts->groupBy('currency')->map(fn ($group) => $group->sum('current_balance'))->toArray();

        $cashTotalBase = $cashAccounts->sum(function ($a) use ($orgId, $defaultCurrency) {
            $converted = ExchangeRate::convert((float) $a->current_balance, $a->currency, $defaultCurrency, $orgId);
            return $converted ?? (float) $a->current_balance;
        });
        $bankTotalBase = $bankAccounts->sum(function ($a) use ($orgId, $defaultCurrency) {
            $converted = ExchangeRate::convert((float) $a->current_balance, $a->currency, $defaultCurrency, $orgId);
            return $converted ?? (float) $a->current_balance;
        });

        return [
            'cash' => [
                'total' => $cashAccounts->sum('current_balance'),
                'total_base' => round($cashTotalBase, 2),
                'by_currency' => collect($cashByCurrency)->map(fn ($total, $code) => ['currency' => $code, 'total' => round((float) $total, 2)])->values()->toArray(),
                'accounts' => $cashAccounts->map(function ($a) {
                    return [
                        'id' => $a->id,
                        'name' => $a->name ?? $a->account_name ?? '',
                        'type' => $a->cash_type,
                        'currency' => $a->currency,
                        'balance' => $a->current_balance,
                        'office' => $a->office?->name,
                    ];
                }),
            ],
            'bank' => [
                'total' => $bankAccounts->sum('current_balance'),
                'total_base' => round($bankTotalBase, 2),
                'by_currency' => collect($bankByCurrency)->map(fn ($total, $code) => ['currency' => $code, 'total' => round((float) $total, 2)])->values()->toArray(),
                'accounts' => $bankAccounts->map(function ($a) {
                    return [
                        'id' => $a->id,
                        'name' => $a->account_name,
                        'bank' => $a->bank_name,
                        'currency' => $a->currency,
                        'balance' => $a->current_balance,
                        'office' => $a->office?->name,
                    ];
                }),
            ],
            'default_currency' => $defaultCurrency,
        ];
    }

    private function getBudgetStatus($orgId, $officeId = null)
    {
        // #region agent log
        $connection = OfficeContext::connection();
        debug_log('getBudgetStatus: before Project::get', ['connection' => $connection, 'orgId' => $orgId, '_loc' => 'DashboardController::getBudgetStatus'], 'H3');
        // #endregion agent log
        $projects = Project::on($connection)->where('organization_id', $orgId)
            ->where('status', 'active')
            ->when($officeId, fn($q) => $q->where('office_id', $officeId))
            ->get();
        // #region agent log
        debug_log('getBudgetStatus: after Project::get', ['count' => $projects->count(), '_loc' => 'DashboardController::getBudgetStatus'], 'H3');
        // #endregion agent log

        $defaultCurrency = Organization::find($orgId)?->default_currency ?? 'AFN';

        $totalBudget = $projects->sum('total_budget');
        $totalSpent = $projects->sum('spent_amount');
        $totalCommitted = $projects->sum('committed_amount');

        $totalBudgetBase = $projects->sum(function ($p) use ($orgId, $defaultCurrency) {
            $converted = ExchangeRate::convert((float) $p->total_budget, $p->currency, $defaultCurrency, $orgId);
            return $converted ?? (float) $p->total_budget;
        });
        $totalSpentBase = $projects->sum(function ($p) use ($orgId, $defaultCurrency) {
            $converted = ExchangeRate::convert((float) $p->spent_amount, $p->currency, $defaultCurrency, $orgId);
            return $converted ?? (float) $p->spent_amount;
        });
        $totalCommittedBase = $projects->sum(function ($p) use ($orgId, $defaultCurrency) {
            $converted = ExchangeRate::convert((float) $p->committed_amount, $p->currency, $defaultCurrency, $orgId);
            return $converted ?? (float) $p->committed_amount;
        });

        $byCurrency = $projects->groupBy('currency')->map(function ($group) {
            $budget = $group->sum('total_budget');
            $spent = $group->sum('spent_amount');
            $committed = $group->sum('committed_amount');
            return [
                'total_budget' => round($budget, 2),
                'total_spent' => round($spent, 2),
                'total_committed' => round($committed, 2),
                'available' => round($budget - $spent - $committed, 2),
            ];
        })->map(fn ($data, $code) => array_merge(['currency' => $code], $data))->values()->toArray();

        return [
            'total_budget' => $totalBudget,
            'total_spent' => $totalSpent,
            'total_committed' => $totalCommitted,
            'available' => $totalBudget - $totalSpent - $totalCommitted,
            'total_budget_base' => round($totalBudgetBase, 2),
            'total_spent_base' => round($totalSpentBase, 2),
            'available_base' => round($totalBudgetBase - $totalSpentBase - $totalCommittedBase, 2),
            'by_currency' => $byCurrency,
            'default_currency' => $defaultCurrency,
            'utilization_rate' => $totalBudget > 0
                ? round(($totalSpent / $totalBudget) * 100, 2)
                : 0,
        ];
    }
}
