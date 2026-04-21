<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Exports\BudgetExport;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Organization;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class BudgetController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Display a listing of budgets.
     */
    public function index(Request $request)
    {
        $query = Budget::where('organization_id', $request->user()->organization_id)
            ->with(['fiscalYear:id,name,start_date,end_date', 'office:id,name', 'project:id,project_code,project_name', 'budgetFormatTemplate:id,name,code', 'grant:id,grant_code,grant_name']);

        if ($request->has('fiscal_year_id')) {
            $query->where('fiscal_year_id', $request->fiscal_year_id);
        }

        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        if ($request->has('budget_type')) {
            $query->where('budget_type', $request->budget_type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('budget_format_template_id')) {
            $query->where('budget_format_template_id', $request->budget_format_template_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('budgets.name', 'like', "%{$search}%")
                    ->orWhere('budgets.description', 'like', "%{$search}%")
                    ->orWhereHas('project', function ($p) use ($search) {
                        $p->where('project_code', 'like', "%{$search}%")
                            ->orWhere('project_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('fiscalYear', function ($fy) use ($search) {
                        $fy->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 200);
        $budgets = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->paginated($budgets);
    }

    /**
     * Store a newly created budget.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'office_id' => 'nullable|exists:offices,id',
            'project_id' => 'nullable|exists:projects,id',
            'fund_id' => 'nullable|exists:funds,id',
            'budget_format_template_id' => 'nullable|exists:budget_format_templates,id',
            'grant_id' => 'nullable|exists:grants,id',
            'budget_type' => 'required|in:operational,project,departmental,consolidated',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'description' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.donor_expenditure_code_id' => 'nullable|exists:donor_expenditure_codes,id',
            'lines.*.description' => 'required|string|max:255',
            'lines.*.q1_amount' => 'required|numeric|min:0',
            'lines.*.q2_amount' => 'required|numeric|min:0',
            'lines.*.q3_amount' => 'required|numeric|min:0',
            'lines.*.q4_amount' => 'required|numeric|min:0',
            'lines.*.format_attributes' => 'nullable|array',
            'lines.*.sheet_key' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            // Calculate totals
            $totalBudget = collect($validated['lines'])->sum(function ($line) {
                return $line['q1_amount'] + $line['q2_amount'] + $line['q3_amount'] + $line['q4_amount'];
            });

            $budgetCode = 'B-' . now()->format('YmdHis') . '-' . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT);

            $budget = Budget::create([
                'organization_id' => $request->user()->organization_id,
                'name' => $validated['name'],
                'fiscal_year_id' => $validated['fiscal_year_id'],
                'office_id' => $validated['office_id'] ?? null,
                'project_id' => $validated['project_id'] ?? null,
                'fund_id' => $validated['fund_id'] ?? null,
                'budget_format_template_id' => $validated['budget_format_template_id'] ?? null,
                'grant_id' => $validated['grant_id'] ?? null,
                'budget_code' => $budgetCode,
                'budget_type' => $validated['budget_type'],
                'currency' => $validated['currency'],
                'total_amount' => $totalBudget,
                'description' => $validated['description'] ?? null,
                'status' => 'draft',
                'prepared_by' => $request->user()->id,
            ]);

            // Create budget lines
            foreach ($validated['lines'] as $index => $line) {
                $annual = $line['q1_amount'] + $line['q2_amount'] + $line['q3_amount'] + $line['q4_amount'];
                $lineData = [
                    'account_id' => $line['account_id'],
                    'donor_expenditure_code_id' => $line['donor_expenditure_code_id'] ?? null,
                    'parent_line_id' => $line['parent_line_id'] ?? null,
                    'sheet_key' => $line['sheet_key'] ?? null,
                    'description' => $line['description'],
                    'q1_amount' => $line['q1_amount'],
                    'q2_amount' => $line['q2_amount'],
                    'q3_amount' => $line['q3_amount'],
                    'q4_amount' => $line['q4_amount'],
                    'annual_amount' => $annual,
                    'actual_amount' => 0,
                    'available_amount' => $annual,
                ];
                if (!empty($line['format_attributes'])) {
                    $lineData['format_attributes'] = $line['format_attributes'];
                }
                $budget->lines()->create($lineData);
            }

            return $this->success($budget->load('lines.account', 'lines.donorExpenditureCode', 'budgetFormatTemplate', 'grant'), 'Budget created successfully', 201);
        });
    }

    /**
     * Display the specified budget.
     */
    public function show(Request $request, Budget $budget)
    {
        if ($budget->organization_id !== $request->user()->organization_id) {
            return $this->error('Budget not found', 404);
        }

        $budget->load(['fiscalYear', 'office', 'project', 'fund', 'budgetFormatTemplate', 'grant', 'lines.account', 'lines.donorExpenditureCode']);

        // Calculate variance for each line
        $lines = $budget->lines->map(function ($line) {
            $variance = $line->annual_amount - $line->actual_amount;
            $variancePercent = $line->annual_amount > 0 
                ? round(($variance / $line->annual_amount) * 100, 2) 
                : 0;
            
            return array_merge($line->toArray(), [
                'variance' => $variance,
                'variance_percent' => $variancePercent,
                'utilization' => $line->annual_amount > 0 
                    ? round(($line->actual_amount / $line->annual_amount) * 100, 2) 
                    : 0,
            ]);
        });

        $totalActual = $budget->lines->sum('actual_amount');
        $totalBudgeted = $budget->total_amount ?? $budget->lines->sum('annual_amount');
        $totalVariance = $totalBudgeted - $totalActual;

        return $this->success([
            'budget' => $budget,
            'lines' => $lines,
            'summary' => [
                'total_budget' => $totalBudgeted,
                'total_actual' => $totalActual,
                'total_variance' => $totalVariance,
                'utilization_rate' => $totalBudgeted > 0
                    ? round(($totalActual / $totalBudgeted) * 100, 2)
                    : 0,
            ],
        ]);
    }

    /**
     * Update the specified budget (header and optionally lines when draft).
     */
    public function update(Request $request, Budget $budget)
    {
        if ($budget->organization_id !== $request->user()->organization_id) {
            return $this->error('Budget not found', 404);
        }

        if ($budget->status === 'approved') {
            return $this->error('Cannot update an approved budget. Create a revision instead.', 400);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'budget_format_template_id' => 'nullable|exists:budget_format_templates,id',
            'grant_id' => 'nullable|exists:grants,id',
            'status' => 'sometimes|in:draft,pending_approval,approved,rejected',
            'lines' => 'sometimes|array|min:0',
            'lines.*.account_id' => 'required_with:lines|exists:chart_of_accounts,id',
            'lines.*.donor_expenditure_code_id' => 'nullable|exists:donor_expenditure_codes,id',
            'lines.*.description' => 'required_with:lines|string|max:255',
            'lines.*.q1_amount' => 'required_with:lines|numeric|min:0',
            'lines.*.q2_amount' => 'required_with:lines|numeric|min:0',
            'lines.*.q3_amount' => 'required_with:lines|numeric|min:0',
            'lines.*.q4_amount' => 'required_with:lines|numeric|min:0',
            'lines.*.format_attributes' => 'nullable|array',
            'lines.*.sheet_key' => 'nullable|string|max:64',
        ]);

        return DB::transaction(function () use ($validated, $budget, $request) {
            $header = [];
            if (array_key_exists('name', $validated)) $header['name'] = $validated['name'];
            if (array_key_exists('description', $validated)) $header['description'] = $validated['description'];
            if (array_key_exists('budget_format_template_id', $validated)) $header['budget_format_template_id'] = $validated['budget_format_template_id'];
            if (array_key_exists('grant_id', $validated)) $header['grant_id'] = $validated['grant_id'];
            if (array_key_exists('status', $validated)) $header['status'] = $validated['status'];
            if (!empty($header)) $budget->update($header);

            if (isset($validated['lines'])) {
                $totalBudget = collect($validated['lines'])->sum(fn ($line) =>
                    $line['q1_amount'] + $line['q2_amount'] + $line['q3_amount'] + $line['q4_amount']);
                $budget->update(['total_amount' => $totalBudget]);
                $budget->lines()->delete();
                foreach ($validated['lines'] as $index => $line) {
                    $annual = $line['q1_amount'] + $line['q2_amount'] + $line['q3_amount'] + $line['q4_amount'];
                    $lineData = [
                        'account_id' => $line['account_id'],
                        'donor_expenditure_code_id' => $line['donor_expenditure_code_id'] ?? null,
                        'sheet_key' => $line['sheet_key'] ?? null,
                        'description' => $line['description'],
                        'q1_amount' => $line['q1_amount'],
                        'q2_amount' => $line['q2_amount'],
                        'q3_amount' => $line['q3_amount'],
                        'q4_amount' => $line['q4_amount'],
                        'annual_amount' => $annual,
                        'actual_amount' => 0,
                        'available_amount' => $annual,
                    ];
                    if (!empty($line['format_attributes'])) {
                        $lineData['format_attributes'] = $line['format_attributes'];
                    }
                    $budget->lines()->create($lineData);
                }
            }

            return $this->success($budget->fresh()->load(['fiscalYear', 'office', 'project', 'budgetFormatTemplate', 'grant', 'lines.account', 'lines.donorExpenditureCode']), 'Budget updated successfully');
        });
    }

    /**
     * Export budget to Excel in UNICEF HER or UNFPA WHO format.
     */
    public function export(Request $request, Budget $budget)
    {
        if ($budget->organization_id !== $request->user()->organization_id) {
            return $this->error('Budget not found', 404);
        }

        $format = $request->input('format', 'unfpa_who');
        if (!in_array($format, ['unicef_her', 'unfpa_who'])) {
            $format = $budget->budgetFormatTemplate?->code === 'unicef_her' ? 'unicef_her' : 'unfpa_who';
        }

        $filename = 'budget-' . ($budget->name ?: $budget->id) . '-' . $format . '-' . now()->format('Ymd') . '.xlsx';
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

        return Excel::download(new BudgetExport($budget, $format), $filename, \Maatwebsite\Excel\Excel::XLSX);
    }

    /**
     * Remove the specified budget. Only draft budgets can be deleted.
     */
    public function destroy(Request $request, Budget $budget)
    {
        if ($budget->organization_id !== $request->user()->organization_id) {
            return $this->error('Budget not found', 404);
        }

        if ($budget->status !== 'draft') {
            return $this->error('Only draft budgets can be deleted.', 422);
        }

        $budget->lines()->delete();
        $budget->delete();

        return $this->success(null, 'Budget deleted successfully');
    }

    /**
     * Create a revision (new draft budget) from an approved budget.
     */
    public function revise(Request $request, Budget $budget)
    {
        if ($budget->organization_id !== $request->user()->organization_id) {
            return $this->error('Budget not found', 404);
        }

        if ($budget->status !== 'approved') {
            return $this->error('Only approved budgets can be revised.', 400);
        }

        return DB::transaction(function () use ($budget, $request) {
            $newName = $budget->name . ' (Revision)';
            $revision = Budget::create([
                'organization_id' => $budget->organization_id,
                'fiscal_year_id' => $budget->fiscal_year_id,
                'office_id' => $budget->office_id,
                'project_id' => $budget->project_id,
                'fund_id' => $budget->fund_id,
                'budget_format_template_id' => $budget->budget_format_template_id,
                'grant_id' => $budget->grant_id,
                'budget_code' => ($budget->budget_code ?? 'B' . $budget->id) . '-REV-' . now()->format('YmdHis'),
                'name' => $newName,
                'description' => $budget->description,
                'budget_type' => $budget->budget_type,
                'currency' => $budget->currency,
                'total_amount' => $budget->total_amount,
                'version' => ($budget->version ?? 1) + 1,
                'status' => 'draft',
                'prepared_by' => $request->user()->id,
            ]);

            $budget->load('lines');
            foreach ($budget->lines as $line) {
                $revision->lines()->create([
                    'account_id' => $line->account_id,
                    'donor_expenditure_code_id' => $line->donor_expenditure_code_id,
                    'parent_line_id' => null,
                    'description' => $line->description,
                    'q1_amount' => $line->q1_amount,
                    'q2_amount' => $line->q2_amount,
                    'q3_amount' => $line->q3_amount,
                    'q4_amount' => $line->q4_amount,
                    'annual_amount' => $line->annual_amount,
                    'actual_amount' => 0,
                    'available_amount' => $line->annual_amount,
                    'format_attributes' => $line->format_attributes,
                ]);
            }
            $revision->load(['fiscalYear', 'office', 'project', 'lines.account', 'lines.donorExpenditureCode']);
            return $this->success($revision, 'Budget revision created successfully', 201);
        });
    }

    /**
     * Submit budget for approval.
     */
    public function submit(Request $request, Budget $budget)
    {
        if ($budget->organization_id !== $request->user()->organization_id) {
            return $this->error('Budget not found', 404);
        }

        if ($budget->status !== 'draft') {
            return $this->error('Only draft budgets can be submitted', 400);
        }

        $budget->update(['status' => 'pending_approval']);
        $budget->refresh();

        $ids = $this->notificationService->approverUserIdsForLevel((int) $budget->organization_id, 1);
        $ids = array_values(array_diff($ids, [$request->user()->id]));
        if ($ids !== []) {
            $label = $budget->name ?: $budget->budget_code ?: 'Budget';
            $this->notificationService->notifyUsers(
                $ids,
                'budget',
                'Budget pending approval',
                "{$label} was submitted for approval.",
                '/approvals/budgets',
                ['budget_id' => $budget->id]
            );
        }

        return $this->success($budget, 'Budget submitted for approval');
    }

    /**
     * Approve budget.
     */
    public function approve(Request $request, Budget $budget)
    {
        if ($budget->organization_id !== $request->user()->organization_id) {
            return $this->error('Budget not found', 404);
        }

        if ($budget->status !== 'pending_approval') {
            return $this->error('Only pending budgets can be approved', 400);
        }

        $budget->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        $budget->refresh();

        if ($budget->prepared_by && (int) $budget->prepared_by !== (int) $request->user()->id) {
            $label = $budget->name ?: $budget->budget_code ?: 'Budget';
            $this->notificationService->notifyUser(
                (int) $budget->prepared_by,
                'success',
                'Budget approved',
                "{$label} has been approved.",
                '/projects/budget',
                ['budget_id' => $budget->id]
            );
        }

        return $this->success($budget, 'Budget approved successfully');
    }

    /**
     * Get budget vs actual comparison.
     */
    public function comparison(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $request->validate([
            'fiscal_year_id' => 'required|exists:fiscal_years,id',
            'office_id' => 'nullable|exists:offices,id',
        ]);

        $query = Budget::where('organization_id', $orgId)
            ->where('fiscal_year_id', $request->fiscal_year_id)
            ->where('status', 'approved');

        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        $budgets = $query->with('lines.account')->get();

        // Aggregate by account
        $byAccount = [];
        foreach ($budgets as $budget) {
            foreach ($budget->lines as $line) {
                $accountCode = $line->account->account_code;
                if (!isset($byAccount[$accountCode])) {
                    $byAccount[$accountCode] = [
                        'account_code' => $accountCode,
                        'account_name' => $line->account->account_name,
                        'budgeted' => 0,
                        'actual' => 0,
                    ];
                }
                $byAccount[$accountCode]['budgeted'] += $line->annual_amount;
                $byAccount[$accountCode]['actual'] += $line->actual_amount;
            }
        }

        // Calculate variances
        $comparison = collect($byAccount)->map(function ($item) {
            $item['variance'] = $item['budgeted'] - $item['actual'];
            $item['variance_percent'] = $item['budgeted'] > 0 
                ? round((($item['budgeted'] - $item['actual']) / $item['budgeted']) * 100, 2) 
                : 0;
            $item['utilization'] = $item['budgeted'] > 0 
                ? round(($item['actual'] / $item['budgeted']) * 100, 2) 
                : 0;
            return $item;
        })->values();

        $totals = [
            'total_budgeted' => $comparison->sum('budgeted'),
            'total_actual' => $comparison->sum('actual'),
            'total_variance' => $comparison->sum('variance'),
        ];

        return $this->success([
            'comparison' => $comparison,
            'totals' => $totals,
        ]);
    }

    /**
     * Get budget summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $budgets = Budget::where('organization_id', $orgId)->get();

        $totalBudget = $budgets->where('status', 'approved')->sum('total_amount');
        $totalActual = $budgets->where('status', 'approved')->sum(function ($b) {
            return $b->lines->sum('actual_amount');
        });

        $byType = $budgets->groupBy('budget_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total' => $group->sum('total_amount'),
            ];
        });

        $byStatus = $budgets->groupBy('status')->map->count();

        return $this->success([
            'total_budgets' => $budgets->count(),
            'approved_budgets' => $budgets->where('status', 'approved')->count(),
            'total_budgeted' => $totalBudget,
            'total_actual' => $totalActual,
            'utilization_rate' => $totalBudget > 0 ? round(($totalActual / $totalBudget) * 100, 2) : 0,
            'by_type' => $byType,
            'by_status' => $byStatus,
        ]);
    }
}
