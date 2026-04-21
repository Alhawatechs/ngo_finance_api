<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Grant;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GrantController extends Controller
{
    /**
     * Display a listing of grants.
     * Use default connection - grants live in central DB.
     */
    public function index(Request $request)
    {
        $connection = config('database.default');
        $query = Grant::on($connection)->where('organization_id', $request->user()->organization_id)
            ->with(['donor:id,code,name,short_name', 'parentGrant:id,grant_code,grant_name']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('donor_id')) {
            $query->where('donor_id', $request->donor_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('grant_name', 'like', "%{$search}%")
                  ->orWhere('grant_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('expiring_within_days')) {
            $days = (int) $request->expiring_within_days;
            if ($days > 0) {
                $query->where('end_date', '>=', now()->toDateString())
                    ->where('end_date', '<=', now()->addDays($days)->toDateString());
            }
        }

        $perPage = min((int) $request->input('per_page', 25), 500);
        $grants = $query->orderBy('start_date', 'desc')
            ->paginate($perPage);

        return $this->paginated($grants);
    }

    /**
     * Store a newly created grant.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'donor_id' => 'required|exists:donors,id',
            'grant_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('grants')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'grant_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'total_amount' => 'required|numeric|min:0',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'terms_conditions' => 'nullable|string',
            'contract_reference' => 'nullable|string|max:100',
            'contract_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'locations' => 'nullable|array',
            'locations.*' => 'string|max:255',
            'document_type' => 'nullable|string|max:100',
            'donor_contribution_amount' => 'nullable|numeric|min:0',
            'partner_contribution_amount' => 'nullable|numeric|min:0',
            'partner_name' => 'nullable|string|max:255',
            'partner_details' => 'nullable|string',
            'sub_partner_allocation_amount' => 'nullable|numeric|min:0',
            'grant_type' => 'nullable|in:restricted,unrestricted,temporarily_restricted',
            'reporting_frequency' => 'nullable|string|max:50',
            'contract_number' => 'nullable|string|max:100',
            'parent_grant_id' => 'nullable|exists:grants,id',
        ]);

        if (!empty($validated['parent_grant_id'])) {
            $parent = Grant::find($validated['parent_grant_id']);
            if ($parent->organization_id !== $request->user()->organization_id) {
                return $this->error('Parent contract must belong to your organization', 422);
            }
        }

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['status'] = 'draft';
        if (isset($validated['contract_number']) && empty($validated['contract_reference'])) {
            $validated['contract_reference'] = $validated['contract_number'];
        }
        unset($validated['contract_number']);

        $validated = $this->normalizeGrantLocations($validated);

        $grant = Grant::create($validated);

        return $this->success($grant->load('donor'), 'Grant created successfully', 201);
    }

    /**
     * Display the specified grant.
     */
    public function show(Request $request, Grant $grant)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $grant->load(['donor', 'parentGrant:id,grant_code,grant_name', 'amendments:id,parent_grant_id,grant_code,grant_name,start_date,end_date,total_amount,currency,status', 'projects', 'documents']);

        $spent = $grant->getReceivedAmount(); // or attribute if present
        $utilizationRate = $grant->total_amount > 0
            ? round(($spent / $grant->total_amount) * 100, 2)
            : 0;

        return $this->success([
            'grant' => $grant,
            'utilization_rate' => $utilizationRate,
            'available_amount' => $grant->getRemainingAmount(),
            'projects_count' => $grant->projects->count(),
        ]);
    }

    /**
     * Update the specified grant.
     */
    public function update(Request $request, Grant $grant)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $validated = $request->validate([
            'donor_id' => 'sometimes|exists:donors,id',
            'grant_code' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('grants')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                })->ignore($grant->id),
            ],
            'grant_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'total_amount' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:draft,pending_approval,approved,active,on_hold,completed,closed',
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'terms_conditions' => 'nullable|string',
            'contract_reference' => 'nullable|string|max:100',
            'contract_date' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'locations' => 'nullable|array',
            'locations.*' => 'string|max:255',
            'document_type' => 'nullable|string|max:100',
            'donor_contribution_amount' => 'nullable|numeric|min:0',
            'partner_contribution_amount' => 'nullable|numeric|min:0',
            'partner_name' => 'nullable|string|max:255',
            'partner_details' => 'nullable|string',
            'sub_partner_allocation_amount' => 'nullable|numeric|min:0',
            'reporting_frequency' => 'nullable|string|max:50',
            'contract_number' => 'nullable|string|max:100',
            'parent_grant_id' => 'nullable|exists:grants,id',
        ]);
        if (array_key_exists('location', $validated) || array_key_exists('locations', $validated)) {
            $validated = $this->normalizeGrantLocations($validated);
        }
        if (isset($validated['contract_number']) && empty($validated['contract_reference'])) {
            $validated['contract_reference'] = $validated['contract_number'];
        }
        unset($validated['contract_number']);
        if (array_key_exists('parent_grant_id', $validated)) {
            if (!empty($validated['parent_grant_id'])) {
                $parent = Grant::find($validated['parent_grant_id']);
                if (!$parent || $parent->organization_id !== $request->user()->organization_id) {
                    return $this->error('Parent contract must belong to your organization', 422);
                }
                if ((int) $validated['parent_grant_id'] === (int) $grant->id) {
                    return $this->error('Contract cannot be an amendment to itself', 422);
                }
            }
        }

        $grant->update($validated);

        return $this->success($grant->load('donor'), 'Grant updated successfully');
    }

    /**
     * Remove the specified grant (soft delete). Only draft grants with no projects can be deleted.
     */
    public function destroy(Request $request, Grant $grant)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        if ($grant->status !== 'draft') {
            return $this->error('Only draft contracts can be deleted. Close or complete the contract instead.', 422);
        }

        if ($grant->projects()->exists()) {
            return $this->error('Cannot delete contract with linked projects. Remove or reassign projects first.', 422);
        }

        $grant->delete();

        return $this->success(null, 'Contract deleted successfully');
    }

    /**
     * Get grant projects.
     */
    public function projects(Request $request, Grant $grant)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $projects = $grant->projects()
            ->with(['office:id,name', 'manager:id,name'])
            ->get();

        return $this->success($projects);
    }

    /**
     * Record disbursement for grant.
     */
    public function recordDisbursement(Request $request, Grant $grant)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'disbursement_date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        // Update disbursed amount
        $grant->disbursed_amount += $validated['amount'];
        $grant->save();

        // Log the disbursement (could create a disbursements table for tracking)
        
        return $this->success([
            'grant' => $grant,
            'new_disbursed_amount' => $grant->disbursed_amount,
        ], 'Disbursement recorded successfully');
    }

    /**
     * Get grants summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $grants = Grant::where('organization_id', $orgId)->with('donor:id,name')->get();

        $totalAmount = $grants->sum('total_amount');
        $totalDisbursed = $grants->sum('disbursed_amount');
        $totalSpent = $grants->sum('spent_amount');

        $byStatus = $grants->groupBy('status')->map(function ($group) {
            return [
                'count' => $group->count(),
                'amount' => $group->sum('total_amount'),
            ];
        });

        $byType = $grants->groupBy('grant_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'amount' => $group->sum('total_amount'),
            ];
        });

        // Grants expiring soon (within 90 days)
        $expiringSoon = $grants->filter(function ($grant) {
            return $grant->status === 'active' && 
                   $grant->end_date && 
                   now()->diffInDays($grant->end_date, false) <= 90 &&
                   now()->diffInDays($grant->end_date, false) > 0;
        })->count();

        return $this->success([
            'total_grants' => $grants->count(),
            'active_grants' => $grants->where('status', 'active')->count(),
            'total_amount' => $totalAmount,
            'total_disbursed' => $totalDisbursed,
            'total_spent' => $totalSpent,
            'available_amount' => $totalAmount - $totalSpent,
            'by_status' => $byStatus,
            'by_type' => $byType,
            'expiring_soon' => $expiringSoon,
        ]);
    }

    /**
     * List documents (contract PDFs etc.) for a grant.
     */
    public function documents(Request $request, Grant $grant)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $docs = $grant->documents()->orderBy('created_at', 'desc')->get();

        return $this->success($docs);
    }

    /**
     * Upload a contract document (PDF etc.) for a grant.
     */
    public function uploadDocument(Request $request, Grant $grant)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:51200', // 50MB (for zip/folders)
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,application/zip,application/x-zip-compressed',
            ],
            'title' => 'nullable|string|max:255',
            'document_type' => 'nullable|in:contract,amendment,budget,other',
        ]);

        $file = $request->file('file');
        $dir = 'contracts/' . $grant->id;
        $path = $file->store($dir, 'public');
        $docType = $request->input('document_type', 'contract');

        $document = $grant->documents()->create([
            'organization_id' => $grant->organization_id,
            'title' => $request->input('title', $file->getClientOriginalName()),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'document_type' => $docType,
            'uploaded_by' => $request->user()->id,
        ]);

        return $this->success([
            'document' => $document,
            'url' => url('api/v1/grants/' . $grant->id . '/documents/' . $document->id . '/download'),
        ], 'Document uploaded successfully', 201);
    }

    /**
     * Download a grant document.
     */
    public function downloadDocument(Request $request, Grant $grant, int $document)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $document = $grant->documents()->find($document);
        if (!$document) {
            return $this->error('Document not found', 404);
        }

        $path = Storage::disk('public')->path($document->file_path);
        if (!file_exists($path)) {
            return $this->error('File not found', 404);
        }

        return response()->download($path, $document->file_name, [
            'Content-Type' => $document->file_type,
        ]);
    }

    /**
     * Update a grant document (title, document_type).
     */
    public function updateDocument(Request $request, Grant $grant, int $document)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $doc = $grant->documents()->find($document);
        if (!$doc) {
            return $this->error('Document not found', 404);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'document_type' => 'nullable|in:contract,amendment,budget,other',
        ]);

        if (array_key_exists('title', $validated)) {
            $doc->title = $validated['title'] ?: $doc->file_name;
        }
        if (array_key_exists('document_type', $validated)) {
            $doc->document_type = $validated['document_type'];
        }
        $doc->save();

        return $this->success($doc, 'Document updated');
    }

    /**
     * Delete a grant document.
     */
    public function deleteDocument(Request $request, Grant $grant, int $document)
    {
        if ($grant->organization_id !== $request->user()->organization_id) {
            return $this->error('Grant not found', 404);
        }

        $doc = $grant->documents()->find($document);
        if (!$doc) {
            return $this->error('Document not found', 404);
        }

        $doc->delete();
        return $this->success(null, 'Document deleted');
    }

    /**
     * Normalize location/locations for grant: ensure locations is array and location is first element.
     */
    private function normalizeGrantLocations(array $validated): array
    {
        if (isset($validated['locations']) && is_array($validated['locations'])) {
            $validated['locations'] = array_values(array_filter(array_map('trim', $validated['locations']), fn ($v) => $v !== ''));
            $validated['location'] = $validated['locations'][0] ?? null;
            return $validated;
        }
        if (!empty($validated['location'])) {
            $validated['locations'] = [trim($validated['location'])];
            return $validated;
        }
        $validated['locations'] = null;
        $validated['location'] = null;
        return $validated;
    }
}
