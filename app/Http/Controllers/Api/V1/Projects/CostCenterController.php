<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Project;
use App\Services\OfficeContext;
use Illuminate\Http\Request;

class CostCenterController extends Controller
{
    /**
     * List cost centers for the organization (project class list).
     * Optional: ?project_id= filter, ?include_inactive=1, ?tree=1 for nested parent/children.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $query = CostCenter::where('organization_id', $orgId)->orderBy('code');

        // Filter by project link: cost centers that belong to this project's class list (project_id = X)
        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->project_id);
        }
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if ($request->boolean('tree')) {
            $all = $query->with('parent:id,code,name')->get();
            $this->attachProjectsCount($all);
            $tree = $this->buildTree($all->toArray(), null);
            return $this->success($tree);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $costCenters = $query->paginate($perPage);
        if ($request->boolean('with_projects') && $costCenters->isNotEmpty()) {
            $this->attachProjectsCount($costCenters->getCollection());
        }
        return $this->paginated($costCenters);
    }

    private function attachProjectsCount($collection): void
    {
        if ($collection->isEmpty()) {
            return;
        }
        $ids = $collection->pluck('id')->all();
        $projectCounts = Project::whereIn('cost_center_id', $ids)->selectRaw('cost_center_id, count(*) as project_count')
            ->groupBy('cost_center_id')
            ->pluck('project_count', 'cost_center_id');
        foreach ($collection as $cc) {
            $cc->setAttribute('projects_count', $projectCounts->get($cc->id, 0));
        }
    }

    /**
     * Build nested tree from flat list (id, parent_id).
     */
    private function buildTree(array $items, ?int $parentId): array
    {
        $branch = [];
        foreach ($items as $item) {
            $pid = isset($item['parent_id']) ? (int) $item['parent_id'] : null;
            if ($pid !== $parentId) {
                continue;
            }
            $node = $item;
            $node['children'] = $this->buildTree($items, (int) $item['id']);
            $branch[] = $node;
        }
        return $branch;
    }

    /**
     * Store a new cost center (project class). Optional parent_id for subclass.
     * Code is auto-generated: project_code:segment1:segment2 (e.g. AB:DH:2078-Want-Waigal).
     */
    public function store(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:cost_centers,id',
            'project_id' => 'nullable|exists:projects,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if (! empty($validated['parent_id'])) {
            $parent = CostCenter::where('organization_id', $orgId)->find($validated['parent_id']);
            if (! $parent) {
                return $this->error('Parent cost center not found or access denied.', 422);
            }
        }
        $projectId = $validated['project_id'] ?? null;
        if (! $projectId && ! empty($validated['parent_id'])) {
            $parent = CostCenter::where('organization_id', $orgId)->find($validated['parent_id']);
            $projectId = $parent?->project_id;
            if ($projectId) {
                $validated['project_id'] = $projectId;
            }
        }
        if ($projectId) {
            $project = Project::on(OfficeContext::connection())
                ->where('organization_id', $orgId)
                ->find($projectId);
            if (! $project) {
                return $this->error('Project not found or access denied.', 422);
            }
        }

        $validated['organization_id'] = $orgId;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['code'] = CostCenter::generateCode(
            $validated['name'],
            $validated['parent_id'] ?? null,
            $projectId,
            $orgId,
            null
        );

        $costCenter = CostCenter::create($validated);

        return $this->success($costCenter, 'Cost center created', 201);
    }

    /**
     * Show a single cost center.
     */
    public function show(Request $request, CostCenter $costCenter)
    {
        if ($costCenter->organization_id !== $request->user()->organization_id) {
            return $this->error('Cost center not found', 404);
        }
        $costCenter->load('projects:id,project_code,project_name,status,cost_center_id');
        return $this->success($costCenter);
    }

    /**
     * Update a cost center. parent_id cannot be self or a descendant (no cycles).
     * Code is regenerated automatically when name or parent_id changes.
     */
    public function update(Request $request, CostCenter $costCenter)
    {
        if ($costCenter->organization_id !== $request->user()->organization_id) {
            return $this->error('Cost center not found', 404);
        }
        $orgId = $request->user()->organization_id;
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:cost_centers,id',
            'project_id' => 'nullable|exists:projects,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if (array_key_exists('project_id', $validated) && $validated['project_id'] !== null) {
            $project = Project::on(OfficeContext::connection())
                ->where('organization_id', $orgId)
                ->find($validated['project_id']);
            if (! $project) {
                return $this->error('Project not found or access denied.', 422);
            }
        }

        if (array_key_exists('parent_id', $validated)) {
            $newParentId = $validated['parent_id'];
            if ($newParentId != null) {
                if ((int) $newParentId === $costCenter->id) {
                    return $this->error('Parent cannot be the same as the cost center.', 422);
                }
                $costCenter->load('parent');
                if ($costCenter->isDescendantOf((int) $newParentId)) {
                    return $this->error('Parent cannot be a descendant (would create a cycle).', 422);
                }
                $parent = CostCenter::where('organization_id', $orgId)->find($newParentId);
                if (! $parent) {
                    return $this->error('Parent cost center not found or access denied.', 422);
                }
            }
        }

        $costCenter->fill($validated);
        $nameChanged = array_key_exists('name', $validated) && $validated['name'] !== $costCenter->getOriginal('name');
        $parentChanged = array_key_exists('parent_id', $validated) && $validated['parent_id'] != $costCenter->getOriginal('parent_id');
        if ($nameChanged || $parentChanged) {
            $costCenter->code = CostCenter::generateCode(
                $costCenter->name,
                $costCenter->parent_id,
                $costCenter->project_id,
                $orgId,
                $costCenter->id
            );
        }
        $costCenter->save();

        if ($nameChanged || $parentChanged) {
            $costCenter->load('children');
            foreach ($costCenter->children as $child) {
                $child->regenerateCodeRecursive();
            }
        }

        return $this->success($costCenter->fresh(), 'Cost center updated');
    }

    /**
     * Remove a cost center. Fails if any project uses it. Children are cascade-deleted.
     */
    public function destroy(Request $request, CostCenter $costCenter)
    {
        if ($costCenter->organization_id !== $request->user()->organization_id) {
            return $this->error('Cost center not found', 404);
        }
        $inUse = Project::where('cost_center_id', $costCenter->id)->exists();
        if ($inUse) {
            return $this->error('Cannot delete: one or more projects use this cost center. Unlink them first.', 422);
        }
        $costCenter->delete();
        return response()->json(null, 204);
    }
}
