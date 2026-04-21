<?php

namespace App\Http\Controllers\Api\V1\Treasury;

use App\Http\Controllers\Controller;
use App\Models\InterprojectCashLoan;
use App\Models\Organization;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InterprojectCashLoanController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $query = InterprojectCashLoan::where('organization_id', $orgId)
            ->with([
                'lenderProject:id,project_code,project_name',
                'borrowerProject:id,project_code,project_name',
                'creator:id,name',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $loans = $query->orderByDesc('effective_date')->orderByDesc('id')->paginate($request->input('per_page', 25));

        return $this->paginated($loans);
    }

    public function store(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $validated = $request->validate([
            'lender_project_id' => [
                'required',
                Rule::exists('projects', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)),
            ],
            'borrower_project_id' => [
                'required',
                'different:lender_project_id',
                Rule::exists('projects', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)),
            ],
            'effective_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:effective_date',
            'principal' => 'required|numeric|min:0.01',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($orgId))],
            'status' => 'sometimes|in:draft,active,settled,cancelled',
            'notes' => 'nullable|string',
        ]);

        $prefix = 'IPL-' . now()->format('Ymd') . '-';
        $count = InterprojectCashLoan::where('organization_id', $orgId)
            ->whereDate('created_at', today())
            ->count() + 1;
        $validated['loan_number'] = $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
        $validated['organization_id'] = $orgId;
        $validated['created_by'] = $request->user()->id;
        $validated['status'] = $validated['status'] ?? 'draft';

        $loan = InterprojectCashLoan::create($validated);
        $loan->load(['lenderProject:id,project_code,project_name', 'borrowerProject:id,project_code,project_name', 'creator:id,name']);

        if ($loan->status === 'active') {
            $this->notifyApproversInterprojectLoanActivated($loan, $request->user());
        }

        return $this->success($loan, 'Inter-project loan created', 201);
    }

    public function show(Request $request, InterprojectCashLoan $interprojectCashLoan)
    {
        if ($interprojectCashLoan->organization_id !== $request->user()->organization_id) {
            return $this->error('Loan not found', 404);
        }

        $interprojectCashLoan->load(['lenderProject', 'borrowerProject', 'creator:id,name']);

        return $this->success($interprojectCashLoan);
    }

    public function update(Request $request, InterprojectCashLoan $interprojectCashLoan)
    {
        if ($interprojectCashLoan->organization_id !== $request->user()->organization_id) {
            return $this->error('Loan not found', 404);
        }

        $validated = $request->validate([
            'effective_date' => 'sometimes|date',
            'due_date' => 'nullable|date',
            'principal' => 'sometimes|numeric|min:0.01',
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'status' => 'sometimes|in:draft,active,settled,cancelled',
            'notes' => 'nullable|string',
        ]);

        $wasActive = $interprojectCashLoan->status === 'active';
        $interprojectCashLoan->update($validated);
        $interprojectCashLoan->refresh();
        $interprojectCashLoan->load(['lenderProject:id,project_code,project_name', 'borrowerProject:id,project_code,project_name']);

        if (! $wasActive && $interprojectCashLoan->status === 'active') {
            $this->notifyApproversInterprojectLoanActivated($interprojectCashLoan, $request->user());
        }

        return $this->success($interprojectCashLoan, 'Loan updated');
    }

    public function destroy(Request $request, InterprojectCashLoan $interprojectCashLoan)
    {
        if ($interprojectCashLoan->organization_id !== $request->user()->organization_id) {
            return $this->error('Loan not found', 404);
        }

        if (in_array($interprojectCashLoan->status, ['active', 'settled'], true)) {
            return $this->error('Only draft or cancelled loans can be deleted.', 422);
        }

        $interprojectCashLoan->delete();

        return $this->success(null, 'Loan deleted');
    }

    private function notifyApproversInterprojectLoanActivated(InterprojectCashLoan $loan, User $actor): void
    {
        $orgId = (int) $loan->organization_id;
        $ids = $this->notificationService->approverUserIdsForLevel($orgId, 1);
        $ids = array_values(array_diff($ids, [$actor->id]));
        if ($ids === []) {
            return;
        }
        $num = $loan->loan_number ?? ('#'.$loan->id);
        $from = $loan->lenderProject->project_code ?? '?';
        $to = $loan->borrowerProject->project_code ?? '?';
        $this->notificationService->notifyUsers(
            $ids,
            'treasury',
            'Inter-project loan activated',
            "{$num} is now active ({$from} → {$to}).",
            '/treasury/cash/interproject-loan',
            ['interproject_cash_loan_id' => $loan->id]
        );
    }
}
