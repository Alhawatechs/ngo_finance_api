<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\User;
use App\Models\Voucher;
use App\Support\ApprovalWorkflow;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Unified inbox for pending approvals (vouchers, budgets, …).
 * Read-only aggregation; approve/reject stays on domain endpoints.
 */
class ApprovalCenterController extends Controller
{
    private const MAX_MERGE_PER_TYPE = 2000;

    public function items(Request $request)
    {
        $validated = $request->validate([
            'type' => 'sometimes|in:all,voucher,budget,treasury',
            'office_id' => 'nullable|integer|exists:offices,id',
            'department' => 'nullable|string|max:200',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'search' => 'nullable|string|max:100',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $type = $validated['type'] ?? 'all';
        $perPage = min((int) ($validated['per_page'] ?? 25), 100);
        $page = max(1, (int) ($validated['page'] ?? 1));

        if ($type === 'voucher') {
            $query = $this->voucherBaseQuery($orgId, $validated);
            $paginator = $query->orderByDesc('submitted_at')->paginate($perPage, ['*'], 'page', $page);
            $mapped = $paginator->getCollection()->map(fn (Voucher $v) => $this->mapVoucherItem($user, $v));
            $paginator->setCollection($mapped);

            return $this->approvalCenterItemsResponse($paginator);
        }

        if ($type === 'treasury') {
            $query = $this->treasuryVoucherQuery($orgId, $validated);
            $paginator = $query->orderByDesc('submitted_at')->paginate($perPage, ['*'], 'page', $page);
            $mapped = $paginator->getCollection()->map(fn (Voucher $v) => $this->mapVoucherItem($user, $v));
            $paginator->setCollection($mapped);

            return $this->approvalCenterItemsResponse($paginator);
        }

        if ($type === 'budget') {
            $query = $this->budgetBaseQuery($orgId, $validated);
            $paginator = $query->orderByDesc('updated_at')->paginate($perPage, ['*'], 'page', $page);
            $mapped = $paginator->getCollection()->map(fn (Budget $b) => $this->mapBudgetItem($user, $b));
            $paginator->setCollection($mapped);

            return $this->approvalCenterItemsResponse($paginator);
        }

        // all — merge and paginate in memory (capped per type)
        $merged = new Collection;

        $vQuery = $this->voucherBaseQuery($orgId, $validated);
        foreach ($vQuery->orderByDesc('submitted_at')->limit(self::MAX_MERGE_PER_TYPE)->get() as $v) {
            $merged->push($this->mapVoucherItem($user, $v));
        }

        $bQuery = $this->budgetBaseQuery($orgId, $validated);
        foreach ($bQuery->orderByDesc('updated_at')->limit(self::MAX_MERGE_PER_TYPE)->get() as $b) {
            $merged->push($this->mapBudgetItem($user, $b));
        }

        $sorted = $merged->sortByDesc('submitted_at')->values();
        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return $this->approvalCenterItemsResponse($paginator);
    }

    /**
     * Paginated list JSON with shared workflow definition for the Approval Center UI.
     */
    private function approvalCenterItemsResponse(LengthAwarePaginator $paginator, string $message = 'Success')
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'workflow_definition' => ApprovalWorkflow::definitions(),
        ]);
    }

    /**
     * Pending queue sizes (same scope as the list: org + optional filters, no pagination).
     */
    public function counts(Request $request)
    {
        $validated = $request->validate([
            'office_id' => 'nullable|integer|exists:offices,id',
            'department' => 'nullable|string|max:200',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'search' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $orgId = (int) $user->organization_id;

        $voucher = (int) $this->voucherBaseQuery($orgId, $validated)->count();
        $budget = (int) $this->budgetBaseQuery($orgId, $validated)->count();
        $treasury = (int) $this->treasuryVoucherQuery($orgId, $validated)->count();

        return response()->json([
            'success' => true,
            'message' => 'Approval center counts',
            'data' => [
                'voucher' => $voucher,
                'budget' => $budget,
                'treasury' => $treasury,
                'all' => $voucher + $budget,
                'workflow_definition' => ApprovalWorkflow::definitions(),
            ],
        ]);
    }

    private function voucherBaseQuery(int $orgId, array $validated)
    {
        return Voucher::query()
            ->where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->when($validated['office_id'] ?? null, fn ($q, $oid) => $q->where('office_id', $oid))
            ->when($validated['date_from'] ?? null, fn ($q, $d) => $q->whereDate('submitted_at', '>=', $d))
            ->when($validated['date_to'] ?? null, fn ($q, $d) => $q->whereDate('submitted_at', '<=', $d))
            ->when($validated['department'] ?? null, function ($q) use ($validated) {
                $dept = $validated['department'];
                $q->whereHas('submitter', fn ($q2) => $q2->where('department', 'like', '%'.$dept.'%'));
            })
            ->when($validated['search'] ?? null, function ($q) use ($validated) {
                $s = $validated['search'];
                $q->where(function ($q2) use ($s) {
                    $q2->where('voucher_number', 'like', "%{$s}%")
                        ->orWhere('payee_name', 'like', "%{$s}%")
                        ->orWhere('description', 'like', "%{$s}%");
                });
            })
            ->with(['office:id,name', 'submitter:id,name,department', 'project:id,project_name']);
    }

    /**
     * Pending vouchers that relate to Treasury & Cash: payments/receipts (cash & bank rails) and
     * journal/contra lines that reference staff advances (description/payee heuristic).
     */
    private function treasuryVoucherQuery(int $orgId, array $validated)
    {
        return $this->voucherBaseQuery($orgId, $validated)
            ->where(function ($q) {
                $q->whereIn('voucher_type', ['payment', 'receipt'])
                    ->orWhere(function ($q2) {
                        $q2->whereIn('voucher_type', ['journal', 'contra'])
                            ->where(function ($q3) {
                                $q3->where('description', 'like', '%advance%')
                                    ->orWhere('payee_name', 'like', '%advance%');
                            });
                    });
            });
    }

    private function budgetBaseQuery(int $orgId, array $validated)
    {
        return Budget::query()
            ->where('organization_id', $orgId)
            ->where('status', 'pending_approval')
            ->when($validated['office_id'] ?? null, fn ($q, $oid) => $q->where('office_id', $oid))
            ->when($validated['date_from'] ?? null, fn ($q, $d) => $q->whereDate('updated_at', '>=', $d))
            ->when($validated['date_to'] ?? null, fn ($q, $d) => $q->whereDate('updated_at', '<=', $d))
            ->when($validated['department'] ?? null, function ($q) use ($validated) {
                $dept = $validated['department'];
                $q->whereHas('preparer', fn ($q2) => $q2->where('department', 'like', '%'.$dept.'%'));
            })
            ->when($validated['search'] ?? null, function ($q) use ($validated) {
                $s = $validated['search'];
                $q->where(function ($q2) use ($s) {
                    $q2->where('budget_code', 'like', "%{$s}%")
                        ->orWhere('name', 'like', "%{$s}%")
                        ->orWhere('description', 'like', "%{$s}%");
                });
            })
            ->with(['office:id,name', 'preparer:id,name,department', 'fiscalYear:id,name', 'project:id,project_name']);
    }

    private function mapVoucherItem(User $user, Voucher $v): array
    {
        $submittedAt = $v->submitted_at?->toIso8601String();
        $canApprove = $this->userCanApproveVoucher($user, $v);
        $canReject = $v->canBeApproved();

        return [
            'resource_type' => 'voucher',
            'id' => $v->id,
            'reference' => $v->voucher_number,
            'title' => $v->description ?: $v->payee_name ?: 'Voucher',
            'subtitle' => $v->payee_name,
            'amount' => (string) $v->total_amount,
            'base_currency_amount' => (string) $v->base_currency_amount,
            'currency' => $v->currency ?? 'USD',
            'status' => $v->status,
            'submitted_at' => $submittedAt,
            'submitted_by' => $v->submitter ? [
                'id' => $v->submitter->id,
                'name' => $v->submitter->name,
                'department' => $v->submitter->department,
            ] : null,
            'department' => $v->submitter?->department,
            'office' => $v->office ? ['id' => $v->office->id, 'name' => $v->office->name] : null,
            'project' => $v->project ? ['id' => $v->project->id, 'name' => $v->project->project_name] : null,
            'meta' => [
                'voucher_type' => $v->voucher_type,
                'current_approval_level' => $v->current_approval_level,
                'required_approval_level' => $v->required_approval_level,
                'workflow' => ApprovalWorkflow::forVoucher($v),
                'treasury_lane' => $this->treasuryLaneForVoucher($v),
            ],
            'actions' => [
                'can_approve' => $canApprove,
                'can_reject' => $canReject,
            ],
            'deep_link' => '/vouchers/'.$v->id.'/edit',
        ];
    }

    private function mapBudgetItem(User $user, Budget $b): array
    {
        $submittedAt = $b->updated_at?->toIso8601String();

        return [
            'resource_type' => 'budget',
            'id' => $b->id,
            'reference' => $b->budget_code ?? $b->name,
            'title' => $b->name,
            'subtitle' => $b->budget_type ?? '',
            'amount' => (string) $b->total_amount,
            'base_currency_amount' => (string) $b->total_amount,
            'currency' => $b->currency ?? 'USD',
            'status' => $b->status,
            'submitted_at' => $submittedAt,
            'submitted_by' => $b->preparer ? [
                'id' => $b->preparer->id,
                'name' => $b->preparer->name,
                'department' => $b->preparer->department,
            ] : null,
            'department' => $b->preparer?->department,
            'office' => $b->office ? ['id' => $b->office->id, 'name' => $b->office->name] : null,
            'project' => $b->project ? ['id' => $b->project->id, 'name' => $b->project->project_name] : null,
            'meta' => [
                'fiscal_year' => $b->fiscalYear?->name,
                'workflow' => ApprovalWorkflow::forBudgetPending(),
            ],
            'actions' => [
                'can_approve' => $this->userCanApproveBudget($user, $b),
                'can_reject' => $b->status === 'pending_approval',
            ],
            'deep_link' => '/projects/budget/edit/'.$b->id,
        ];
    }

    /**
     * @return 'cash'|'bank'|'advance'|null
     */
    private function treasuryLaneForVoucher(Voucher $v): ?string
    {
        $text = strtolower((string) $v->description.' '.($v->payee_name ?? ''));
        if (str_contains($text, 'advance')) {
            return 'advance';
        }
        if (in_array($v->voucher_type, ['payment', 'receipt'], true)) {
            return ($v->payment_method ?? '') === 'cash' ? 'cash' : 'bank';
        }
        if (in_array($v->voucher_type, ['journal', 'contra'], true)) {
            return 'advance';
        }

        return null;
    }

    private function userCanApproveVoucher(User $user, Voucher $v): bool
    {
        if (! $v->canBeApproved()) {
            return false;
        }
        if (($user->approval_level ?? 0) <= $v->current_approval_level) {
            return false;
        }
        if (! $user->canApproveAmount((float) $v->base_currency_amount)) {
            return false;
        }

        return true;
    }

    private function userCanApproveBudget(User $user, Budget $b): bool
    {
        return $b->status === 'pending_approval'
            && $b->organization_id === $user->organization_id;
    }
}
