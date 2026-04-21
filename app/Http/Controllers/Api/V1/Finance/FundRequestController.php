<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\FundRequest;
use App\Models\Grant;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FundRequestController extends Controller
{
    public function index(Request $request)
    {
        $query = FundRequest::where('organization_id', $request->user()->organization_id)
            ->with(['grant:id,grant_code,grant_name', 'project:id,project_code,project_name', 'creator:id,name']);

        if ($request->filled('grant_id')) {
            $query->where('grant_id', $request->grant_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $query->whereDate('request_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('request_date', '<=', $request->to);
        }

        $list = $query->orderByDesc('request_date')->paginate($request->input('per_page', 15));
        return $this->paginated($list);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'grant_id' => 'required|exists:grants,id',
            'project_id' => 'nullable|exists:projects,id',
            'request_date' => 'required|date',
            'request_type' => 'required|in:dct,reimbursement,advance,other',
            'description' => 'required|string',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'requested_amount' => 'required|numeric|min:0.01',
            'expected_receipt_date' => 'nullable|date',
        ]);

        $grant = Grant::findOrFail($validated['grant_id']);
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['created_by'] = $request->user()->id;
        $validated['status'] = 'draft';
        $validated['request_number'] = 'FR-' . str_pad(
            (string) (FundRequest::where('organization_id', $validated['organization_id'])->count() + 1),
            5,
            '0',
            STR_PAD_LEFT
        );

        $fundRequest = FundRequest::create($validated);
        $fundRequest->load(['grant:id,grant_code,grant_name', 'project:id,project_code,project_name']);

        return $this->success($fundRequest, 'Fund request created', 201);
    }

    public function show(Request $request, int $id)
    {
        $item = FundRequest::where('organization_id', $request->user()->organization_id)
            ->with(['grant.donor', 'project', 'creator', 'approver'])
            ->findOrFail($id);
        return $this->success($item);
    }

    public function update(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->organization_id !== $request->user()->organization_id) {
            return $this->error('Not found', 404);
        }
        if ($fundRequest->status !== 'draft') {
            return $this->error('Only draft requests can be updated', 422);
        }

        $validated = $request->validate([
            'request_date' => 'sometimes|date',
            'request_type' => 'sometimes|in:dct,reimbursement,advance,other',
            'description' => 'sometimes|string',
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'requested_amount' => 'sometimes|numeric|min:0.01',
            'expected_receipt_date' => 'nullable|date',
        ]);

        $fundRequest->update($validated);
        return $this->success($fundRequest->fresh(['grant', 'project']));
    }

    public function submit(Request $request, FundRequest $fundRequest)
    {
        if ($fundRequest->organization_id !== $request->user()->organization_id) {
            return $this->error('Not found', 404);
        }
        if ($fundRequest->status !== 'draft') {
            return $this->error('Only draft requests can be submitted', 422);
        }
        $fundRequest->update(['status' => 'submitted']);
        return $this->success($fundRequest->fresh(), 'Request submitted');
    }
}
