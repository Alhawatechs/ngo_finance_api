<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DonorExpenditureCode;
use App\Models\Project;
use Illuminate\Http\Request;

class DonorExpenditureCodeController extends Controller
{
    /**
     * List donor expenditure codes (optionally scoped by project or donor).
     */
    public function index(Request $request)
    {
        $query = DonorExpenditureCode::where('organization_id', $request->user()->organization_id)
            ->with(['project:id,project_code,project_name', 'donor:id,code,name']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('donor_id')) {
            $query->where('donor_id', $request->donor_id);
        }

        $codes = $query->orderBy('sort_order')->orderBy('code')->get();

        return $this->success($codes);
    }

    /**
     * Store a donor expenditure code (typically for a project).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|exists:projects,id',
            'donor_id' => 'nullable|exists:donors,id',
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:donor_expenditure_codes,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $orgId = $request->user()->organization_id;

        if (!empty($validated['project_id'])) {
            $project = Project::where('id', $validated['project_id'])->where('organization_id', $orgId)->first();
            if (!$project) {
                return $this->error('Project not found', 404);
            }
        }

        $validated['organization_id'] = $orgId;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $code = DonorExpenditureCode::create($validated);

        return $this->success($code->load(['project', 'donor', 'parent']), 'Donor expenditure code created', 201);
    }

    /**
     * Update a donor expenditure code.
     */
    public function update(Request $request, DonorExpenditureCode $donorExpenditureCode)
    {
        if ($donorExpenditureCode->organization_id !== $request->user()->organization_id) {
            return $this->error('Not found', 404);
        }

        $validated = $request->validate([
            'code' => 'sometimes|string|max:50',
            'name' => 'sometimes|string|max:255',
            'parent_id' => 'nullable|exists:donor_expenditure_codes,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $donorExpenditureCode->update($validated);

        return $this->success($donorExpenditureCode->fresh(['project', 'donor', 'parent']), 'Donor expenditure code updated');
    }

    /**
     * Delete a donor expenditure code.
     */
    public function destroy(Request $request, DonorExpenditureCode $donorExpenditureCode)
    {
        if ($donorExpenditureCode->organization_id !== $request->user()->organization_id) {
            return $this->error('Not found', 404);
        }

        $donorExpenditureCode->delete();

        return $this->success(null, 'Donor expenditure code deleted');
    }
}
