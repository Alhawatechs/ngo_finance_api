<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\BudgetFormatTemplate;
use App\Models\Donor;
use App\Models\Project;
use App\Services\GoogleSheetsImportService;
use Illuminate\Http\Request;

class BudgetFormatTemplateController extends Controller
{
    /**
     * List budget format templates for the organization.
     * Use ?include_inactive=1 to include inactive templates (for admin).
     * Budget format templates live in central DB; donor_id references central donors.
     * Donor model uses OfficeContext, so we load donors explicitly from default connection.
     */
    public function index(Request $request)
    {
        $query = BudgetFormatTemplate::where('organization_id', $request->user()->organization_id)
            ->orderBy('code');
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        $templates = $query->get();

        if ($request->boolean('with_donor') && $templates->isNotEmpty()) {
            $donorIds = $templates->pluck('donor_id')->unique()->filter()->values()->all();
            $donors = Donor::on(config('database.default'))
                ->whereIn('id', $donorIds)
                ->get(['id', 'code', 'name'])
                ->keyBy('id');
            foreach ($templates as $t) {
                if ($t->donor_id && isset($donors[$t->donor_id])) {
                    $t->setRelation('donor', $donors[$t->donor_id]);
                }
            }
        }

        return $this->success($templates);
    }

    /**
     * Get a single budget format template.
     */
    public function show(Request $request, int $id)
    {
        $template = BudgetFormatTemplate::where('organization_id', $request->user()->organization_id)
            ->findOrFail($id);
        return $this->success($template);
    }

    /**
     * Create a budget format template.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'donor_id' => 'nullable|exists:donors,id',
            'structure_type' => 'required|in:account_based,donor_code_based,activity_based,hybrid',
            'column_definition' => 'nullable|array',
            'google_spreadsheet_id' => 'nullable|string|max:128',
            'is_active' => 'boolean',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        // Enforce unique code per organization
        $exists = BudgetFormatTemplate::where('organization_id', $validated['organization_id'])
            ->where('code', $validated['code'])
            ->exists();
        if ($exists) {
            return $this->error('A format template with this code already exists.', 422);
        }

        $template = BudgetFormatTemplate::create($validated);
        return $this->success($template, 'Format template created', 201);
    }

    /**
     * Update a budget format template.
     */
    public function update(Request $request, int $id)
    {
        $template = BudgetFormatTemplate::where('organization_id', $request->user()->organization_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'sometimes|string|max:50',
            'donor_id' => 'nullable|exists:donors,id',
            'structure_type' => 'sometimes|in:account_based,donor_code_based,activity_based,hybrid',
            'column_definition' => 'nullable|array',
            'google_spreadsheet_id' => 'nullable|string|max:128',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['code']) && $validated['code'] !== $template->code) {
            $exists = BudgetFormatTemplate::where('organization_id', $template->organization_id)
                ->where('code', $validated['code'])
                ->exists();
            if ($exists) {
                return $this->error('A format template with this code already exists.', 422);
            }
        }

        $template->update($validated);
        return $this->success($template);
    }

    /**
     * Delete a budget format template.
     */
    public function destroy(Request $request, int $id)
    {
        $template = BudgetFormatTemplate::where('organization_id', $request->user()->organization_id)
            ->findOrFail($id);

        $budgetCount = $template->budgets()->count();
        if ($budgetCount > 0) {
            return $this->error("Cannot delete: {$budgetCount} budget(s) use this format. Deactivate instead.", 422);
        }

        $template->delete();
        return response()->json(null, 204);
    }

    /**
     * Get suggested budget format for a project (from project → grant → donor.default_budget_format_id).
     */
    public function suggested(Request $request)
    {
        $request->validate(['project_id' => 'required|exists:projects,id']);

        $project = Project::with(['grant.donor.defaultBudgetFormat'])
            ->findOrFail($request->project_id);

        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $suggested = null;
        if ($project->grant?->donor?->default_budget_format_id) {
            $suggested = BudgetFormatTemplate::find($project->grant->donor->default_budget_format_id);
        }

        // Fallback to Legacy if no donor default
        if (!$suggested) {
            $suggested = BudgetFormatTemplate::where('organization_id', $request->user()->organization_id)
                ->where('code', 'legacy')
                ->where('is_active', true)
                ->first();
        }

        return $this->success([
            'suggested_format' => $suggested,
            'project_id' => $project->id,
            'grant_id' => $project->grant_id,
            'donor_id' => $project->grant?->donor_id,
        ]);
    }

    /**
     * Import format structure from a Google Spreadsheet URL.
     * Returns column_definition with many sheets (one per tab). Sheet must be shared "Anyone with the link can view" if using API key.
     */
    public function importFromGoogleSheet(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|string|max:2048',
        ]);

        try {
            $service = new GoogleSheetsImportService;
            $columnDefinition = $service->importFormatFromUrl($validated['url']);
            return $this->success([
                'column_definition' => $columnDefinition,
                'sheet_count' => count($columnDefinition['sheets'] ?? []),
            ]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }
}
