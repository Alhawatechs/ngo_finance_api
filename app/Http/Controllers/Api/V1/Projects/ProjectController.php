<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Grant;
use App\Models\Donor;
use App\Models\Office;
use App\Models\Document;
use App\Services\OfficeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects.
     * Use OfficeContext connection so the list matches where projects are created (store uses model connection).
     * When office_id is provided, use that office so the list matches the selected voucher office.
     * When all_offices=1, use head office context so the list includes all projects (e.g. for voucher dropdown).
     */
    public function index(Request $request)
    {
        if ($request->filled('office_id')) {
            $office = Office::where('id', (int) $request->office_id)
                ->where('organization_id', $request->user()->organization_id)
                ->first();
            if ($office) {
                return OfficeContext::runWithOffice($office, fn () => $this->indexWithConnection($request));
            }
        }
        if ($request->boolean('all_offices')) {
            return $this->indexAggregated($request);
        }

        return $this->indexWithConnection($request);
    }

    /**
     * Return projects from all offices merged into one list (for voucher dropdown etc).
     * Each project includes office_id so the frontend can use composite keys.
     */
    private function indexAggregated(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $offices = Office::where('organization_id', $orgId)->orderBy('is_head_office', 'desc')->orderBy('name')->get();
        $status = $request->input('status');
        $perPage = min((int) $request->input('per_page', 500), 1000);
        $merged = collect();

        foreach ($offices as $office) {
            $chunk = OfficeContext::runWithOffice($office, function () use ($request, $orgId, $status) {
                $query = Project::on(OfficeContext::connection())
                    ->where('organization_id', $orgId);
                if ($status !== null && $status !== '') {
                    $query->where('status', $status);
                }
                return $query->orderBy('project_name')
                    ->get(['id', 'office_id', 'cost_center_id', 'project_code', 'project_name', 'status', 'currency']);
            });
            foreach ($chunk as $project) {
                $merged->push($project);
            }
        }

        $merged = $merged->unique(fn ($p) => $p->office_id . '_' . $p->id)
            ->sortBy('project_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $this->attachCostCentersToProjects($merged);

        return $this->success($merged->take($perPage)->values()->all(), 'Success');
    }

    /**
     * Load cost centers and attach to each project in the collection (by cost_center_id).
     */
    private function attachCostCentersToProjects(?\Illuminate\Support\Collection $projects): void
    {
        if (! $projects || $projects->isEmpty()) {
            return;
        }
        $ids = $projects->pluck('cost_center_id')->unique()->filter()->values()->all();
        if ($ids === []) {
            return;
        }
        $map = CostCenter::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($projects as $project) {
            if ($project->cost_center_id && $map->has($project->cost_center_id)) {
                $project->setRelation('costCenter', $map->get($project->cost_center_id));
            }
        }
    }

    /**
     * Create the default cost center for a new project. Code uses project code as prefix (e.g. 0F:Project Name).
     * Returns the CostCenter model. project_id is set after project is created.
     */
    private function createCostCenterForProject(array $validated): CostCenter
    {
        $segment = CostCenter::segmentForCode($validated['project_name']);
        $baseCode = $validated['project_code'] . ':' . $segment;
        $code = $baseCode;
        $suffix = 0;
        while (CostCenter::where('organization_id', $validated['organization_id'])->where('code', $code)->exists()) {
            $code = $baseCode . '-' . (++$suffix);
        }

        return CostCenter::create([
            'organization_id' => $validated['organization_id'],
            'code' => $code,
            'name' => $validated['project_name'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * Run the projects index query using the current OfficeContext connection.
     */
    private function indexWithConnection(Request $request)
    {
        try {
            $connection = OfficeContext::connection();

            $query = Project::on($connection)
                ->where('organization_id', $request->user()->organization_id);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('office_id')) {
                $query->where('office_id', $request->office_id);
            }

            if ($request->has('grant_id')) {
                $query->where('grant_id', $request->grant_id);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $driver = $query->getConnection()->getDriverName();
                $castType = ($driver === 'pgsql' || $driver === 'sqlite') ? 'TEXT' : 'CHAR';
                $query->where(function ($q) use ($search, $castType) {
                    $q->where('project_name', 'like', "%{$search}%")
                      ->orWhere('project_code', 'like', "%{$search}%")
                      ->orWhere('sector', 'like', "%{$search}%")
                      ->orWhere('location', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%")
                      ->orWhere('currency', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhereRaw("CAST(budget_amount AS {$castType}) LIKE ?", ["%{$search}%"])
                      ->orWhereRaw("CAST(COALESCE(spent_amount, 0) AS {$castType}) LIKE ?", ["%{$search}%"])
                      ->orWhereHas('grant', function ($g) use ($search) {
                          $g->where('grant_code', 'like', "%{$search}%")
                            ->orWhere('grant_name', 'like', "%{$search}%")
                            ->orWhere('grant_type', 'like', "%{$search}%")
                            ->orWhereHas('donor', function ($d) use ($search) {
                                $d->where('name', 'like', "%{$search}%")
                                  ->orWhere('code', 'like', "%{$search}%")
                                  ->orWhere('short_name', 'like', "%{$search}%");
                            });
                      })
                      ->orWhereHas('office', function ($o) use ($search) {
                          $o->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                      });
                });
            }

            if ($request->has('sector') && $request->sector !== '' && $request->sector !== 'all') {
                $query->where('sector', $request->sector);
            }

            $perPage = min((int) $request->input('per_page', 10), 1000);
            $projects = $query->orderBy('created_at', 'desc')
                ->orderBy('start_date', 'desc')
                ->paginate($perPage);

            // Load grant, donor, and office from default connection so we never hit an empty office DB
            $grantIds = $projects->pluck('grant_id')->unique()->filter()->values()->all();
            $officeIds = $projects->pluck('office_id')->unique()->filter()->values()->all();

            $grants = collect();
            if (!empty($grantIds)) {
                $grants = Grant::on($connection)->whereIn('id', $grantIds)->get()->keyBy('id');
                $donorIds = $grants->pluck('donor_id')->unique()->filter()->values()->all();
                $donors = !empty($donorIds)
                    ? Donor::on($connection)->whereIn('id', $donorIds)->get()->keyBy('id')
                    : collect();
                foreach ($grants as $g) {
                    if ($g->donor_id && isset($donors[$g->donor_id])) {
                        $g->setRelation('donor', $donors[$g->donor_id]);
                    }
                }
                // Load documents for each grant (contracts/attachments) for project list attachments column
                $documents = Document::where('documentable_type', Grant::class)
                    ->whereIn('documentable_id', $grantIds)
                    ->orderBy('created_at', 'desc')
                    ->get();
                $docsByGrant = $documents->groupBy('documentable_id');
                foreach ($grants as $g) {
                    $g->setRelation('documents', $docsByGrant->get($g->id, collect()));
                }
            }

            $officesList = !empty($officeIds)
                ? Office::on($connection)->whereIn('id', $officeIds)->get()->keyBy('id')
                : collect();

            // Load project-level documents (attachments belong to the project only, not shared by grant)
            $projectIds = $projects->pluck('id')->values()->all();
            $projectDocuments = collect();
            if (!empty($projectIds)) {
                $projectDocuments = Document::where('documentable_type', Project::class)
                    ->whereIn('documentable_id', $projectIds)
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->groupBy('documentable_id');
            }

            foreach ($projects as $project) {
                if ($project->grant_id && $grants->has($project->grant_id)) {
                    $project->setRelation('grant', $grants->get($project->grant_id));
                }
                if ($project->office_id && $officesList->has($project->office_id)) {
                    $project->setRelation('office', $officesList->get($project->office_id));
                }
                $project->setRelation('documents', $projectDocuments->get($project->id, collect()));
            }
            $this->attachCostCentersToProjects($projects->getCollection());

            return $this->paginated($projects);
        } catch (\Throwable $e) {
            report($e);
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Store a newly created project.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'grant_id' => 'required|exists:grants,id',
            'parent_project_id' => 'nullable|exists:projects,id',
            'office_id' => 'required|exists:offices,id',
            'project_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('projects')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'project_name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'total_budget' => 'required|numeric|min:0',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'project_manager_id' => 'nullable|exists:users,id',
            'sector' => 'nullable|string|max:100',
            'target_beneficiaries' => 'nullable|integer|min:0',
            'location' => 'nullable|string|max:255',
            'locations' => 'nullable|array',
            'locations.*' => 'string|max:255',
            'status' => 'nullable|in:draft,planning,active,on_hold,completed,cancelled',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        if (empty($validated['status'])) {
            $validated['status'] = 'planning';
        }
        if (! empty($validated['parent_project_id'] ?? null)) {
            $parent = Project::on(OfficeContext::connection())->find($validated['parent_project_id']);
            if (! $parent || $parent->organization_id !== $request->user()->organization_id) {
                return $this->error('Parent project not found or access denied.', 422);
            }
        } else {
            $validated['parent_project_id'] = null;
        }
        // Map API/frontend names to DB columns
        $validated['budget_amount'] = (float) ($validated['total_budget'] ?? 0);
        $validated['beneficiaries_target'] = isset($validated['target_beneficiaries']) ? (int) $validated['target_beneficiaries'] : null;
        if (isset($validated['project_manager_id']) && $validated['project_manager_id']) {
            $user = \App\Models\User::find($validated['project_manager_id']);
            $validated['project_manager'] = $user?->name;
        }
        $validated = $this->normalizeProjectLocations($validated);
        unset(
            $validated['total_budget'],
            $validated['target_beneficiaries'],
            $validated['project_manager_id'],
            $validated['spent_amount'],
            $validated['committed_amount']
        );

        // Automatically create the project's class list (default cost center) when registering a project.
        $costCenter = $this->createCostCenterForProject($validated);
        $validated['cost_center_id'] = $costCenter->id;
        $project = Project::create($validated);
        $costCenter->update(['project_id' => $project->id]);

        return $this->success($project->load(['grant', 'office', 'costCenter']), 'Project created successfully', 201);
    }

    /**
     * Display the specified project.
     */
    public function show(Request $request, Project $project)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $project->load(['grant.donor', 'grant.documents', 'office', 'costCenter', 'budgetLines.account', 'documents', 'amendments.grant']);

        // Calculate budget utilization
        $budgetUtilization = $project->total_budget > 0 
            ? round(($project->spent_amount / $project->total_budget) * 100, 2) 
            : 0;

        // Get recent transactions
        $recentTransactions = $project->journalEntryLines()
            ->with('journalEntry:id,entry_number,entry_date,description')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return $this->success([
            'project' => $project,
            'amendments' => $project->amendments,
            'budget_utilization' => $budgetUtilization,
            'available_budget' => $project->total_budget - $project->spent_amount - $project->committed_amount,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    /**
     * Update the specified project.
     */
    public function update(Request $request, Project $project)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $validated = $request->validate([
            'grant_id' => 'sometimes|exists:grants,id',
            'parent_project_id' => 'nullable|exists:projects,id',
            'office_id' => 'sometimes|exists:offices,id',
            'cost_center_id' => 'nullable|exists:cost_centers,id',
            'project_code' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('projects')->ignore($project->id)->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'project_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'total_budget' => 'sometimes|numeric|min:0',
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'status' => 'sometimes|in:draft,planning,active,on_hold,completed,cancelled',
            'project_manager_id' => 'nullable|exists:users,id',
            'sector' => 'nullable|string|max:100',
            'target_beneficiaries' => 'nullable|integer|min:0',
            'location' => 'nullable|string|max:255',
            'locations' => 'nullable|array',
            'locations.*' => 'string|max:255',
        ]);

        if (! empty($validated['cost_center_id'])) {
            $exists = CostCenter::where('id', $validated['cost_center_id'])
                ->where('organization_id', $request->user()->organization_id)
                ->exists();
            if (! $exists) {
                return $this->error('Cost center not found or access denied.', 422);
            }
        } else {
            $validated['cost_center_id'] = null;
        }

        if (array_key_exists('location', $validated) || array_key_exists('locations', $validated)) {
            $validated = $this->normalizeProjectLocations($validated);
        }
        if (array_key_exists('total_budget', $validated)) {
            $validated['budget_amount'] = (float) $validated['total_budget'];
            unset($validated['total_budget']);
        }
        if (array_key_exists('target_beneficiaries', $validated)) {
            $validated['beneficiaries_target'] = $validated['target_beneficiaries'] !== null && $validated['target_beneficiaries'] !== '' ? (int) $validated['target_beneficiaries'] : null;
            unset($validated['target_beneficiaries']);
        }
        if (array_key_exists('project_manager_id', $validated)) {
            $validated['project_manager'] = $validated['project_manager_id']
                ? \App\Models\User::find($validated['project_manager_id'])?->name
                : null;
            unset($validated['project_manager_id']);
        }

        $project->update($validated);

        return $this->success($project->load(['grant', 'office', 'costCenter']), 'Project updated successfully');
    }

    /**
     * Remove the specified project (soft delete). Only planning or cancelled projects with no spending can be deleted.
     */
    public function destroy(Request $request, Project $project)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        if (!in_array($project->status, ['draft', 'planning', 'cancelled'])) {
            return $this->error('Only draft, planning or cancelled projects can be deleted.', 422);
        }

        if (($project->spent_amount ?? 0) > 0 || ($project->committed_amount ?? 0) > 0) {
            return $this->error('Cannot delete project with spending or commitments.', 422);
        }

        $project->delete();

        return $this->success(null, 'Project deleted successfully');
    }

    /**
     * Get project budget lines.
     */
    public function budgetLines(Request $request, Project $project)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $budgetLines = $project->budgetLines()
            ->with('account:id,account_code,account_name')
            ->get();

        return $this->success($budgetLines);
    }

    /**
     * Add budget line to project.
     */
    public function addBudgetLine(Request $request, Project $project)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $validated = $request->validate([
            'account_id' => 'required|exists:chart_of_accounts,id',
            'description' => 'required|string|max:255',
            'budgeted_amount' => 'required|numeric|min:0',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after:period_start',
        ]);

        $validated['project_id'] = $project->id;
        $validated['spent_amount'] = 0;

        $budgetLine = $project->budgetLines()->create($validated);

        return $this->success($budgetLine->load('account'), 'Budget line added successfully', 201);
    }

    /**
     * Get project summary/dashboard. Accepts same filters as index (status, office_id, grant_id, search, sector).
     */
    public function summary(Request $request)
    {
        $connection = OfficeContext::connection();

        $query = Project::on($connection)
            ->where('organization_id', $request->user()->organization_id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        if ($request->has('grant_id')) {
            $query->where('grant_id', $request->grant_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $driver = $query->getConnection()->getDriverName();
            $castType = ($driver === 'pgsql' || $driver === 'sqlite') ? 'TEXT' : 'CHAR';
            $query->where(function ($q) use ($search, $castType) {
                $q->where('project_name', 'like', "%{$search}%")
                  ->orWhere('project_code', 'like', "%{$search}%")
                  ->orWhere('sector', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhere('currency', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereRaw("CAST(budget_amount AS {$castType}) LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("CAST(COALESCE(spent_amount, 0) AS {$castType}) LIKE ?", ["%{$search}%"])
                  ->orWhereHas('grant', function ($g) use ($search) {
                      $g->where('grant_code', 'like', "%{$search}%")
                        ->orWhere('grant_name', 'like', "%{$search}%")
                        ->orWhere('grant_type', 'like', "%{$search}%")
                        ->orWhereHas('donor', function ($d) use ($search) {
                            $d->where('name', 'like', "%{$search}%")
                              ->orWhere('code', 'like', "%{$search}%")
                              ->orWhere('short_name', 'like', "%{$search}%");
                        });
                  })
                  ->orWhereHas('office', function ($o) use ($search) {
                      $o->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('sector') && $request->sector !== '' && $request->sector !== 'all') {
            $query->where('sector', $request->sector);
        }

        $projects = $query->get();

        // Aggregate by currency so we never sum different currencies together
        $byCurrency = $projects->groupBy('currency')->map(function ($group, $currency) {
            $budget = $group->sum(fn ($p) => (float) ($p->total_budget ?? 0));
            $spent = $group->sum(fn ($p) => (float) ($p->spent_amount ?? 0));
            return [
                'currency' => $currency ?: 'USD',
                'total_budget' => $budget,
                'total_spent' => $spent,
                'project_count' => $group->count(),
                'utilization_rate' => $budget > 0 ? round(($spent / $budget) * 100, 2) : 0,
            ];
        })->values()->all();

        $currenciesCount = count($byCurrency);
        $singleCurrency = $currenciesCount === 1 ? $byCurrency[0] : null;

        $byStatus = $projects->groupBy('status')->map(function ($group) {
            return [
                'count' => $group->count(),
                'budget' => $group->sum(fn ($p) => (float) ($p->total_budget ?? 0)),
                'spent' => $group->sum(fn ($p) => (float) ($p->spent_amount ?? 0)),
            ];
        });

        $activeProjects = $projects->where('status', 'active');

        $payload = [
            'total_projects' => $projects->count(),
            'active_projects' => $activeProjects->count(),
            'by_currency' => $byCurrency,
            'by_status' => $byStatus,
        ];

        // Only expose single total_budget/total_spent/util when all projects share one currency
        if ($singleCurrency) {
            $payload['total_budget'] = $singleCurrency['total_budget'];
            $payload['total_spent'] = $singleCurrency['total_spent'];
            $payload['utilization_rate'] = $singleCurrency['utilization_rate'];
            $payload['total_committed'] = $projects->sum('committed_amount');
            $payload['available_budget'] = $singleCurrency['total_budget'] - $singleCurrency['total_spent'] - $projects->sum('committed_amount');
        } else {
            $payload['total_budget'] = null;
            $payload['total_spent'] = null;
            $payload['utilization_rate'] = null;
            $payload['total_committed'] = null;
            $payload['available_budget'] = null;
        }

        return $this->success($payload);
    }

    /**
     * List documents (attachments) for a project.
     */
    public function documents(Request $request, Project $project)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $docs = $project->documents()->orderBy('created_at', 'desc')->get();
        return $this->success($docs);
    }

    /**
     * Upload a document (attachment) for a project.
     */
    public function uploadDocument(Request $request, Project $project)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
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
        $dir = 'projects/' . $project->id;
        $path = $file->store($dir, 'public');
        $docType = $request->input('document_type', 'other');

        $document = $project->documents()->create([
            'organization_id' => $project->organization_id,
            'office_id' => $project->office_id,
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
            'url' => url('api/v1/projects/' . $project->id . '/documents/' . $document->id . '/download'),
        ], 'Document uploaded successfully', 201);
    }

    /**
     * Download a project document.
     */
    public function downloadDocument(Request $request, Project $project, int $document)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $document = $project->documents()->find($document);
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
     * Update a project document (title, document_type).
     */
    public function updateDocument(Request $request, Project $project, int $document)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $doc = $project->documents()->find($document);
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
     * Delete a project document.
     */
    public function deleteDocument(Request $request, Project $project, int $document)
    {
        if ($project->organization_id !== $request->user()->organization_id) {
            return $this->error('Project not found', 404);
        }

        $doc = $project->documents()->find($document);
        if (!$doc) {
            return $this->error('Document not found', 404);
        }

        $doc->delete();
        return $this->success(null, 'Document deleted');
    }

    private function normalizeProjectLocations(array $validated): array
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
