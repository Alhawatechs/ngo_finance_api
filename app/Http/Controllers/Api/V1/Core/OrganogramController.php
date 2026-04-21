<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\ReportingLine;
use App\Models\SegregationOfDuties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OrganogramController extends Controller
{
    /**
     * Get full organogram data for visualization
     */
    public function getOrganogram(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        
        // Get all organizational units with hierarchy
        $units = OrganizationalUnit::where('organization_id', $organization->id)
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->with(['allChildren', 'head', 'positions.activeAssignments.user'])
            ->orderBy('sort_order')
            ->get();
        
        // Get all positions with reporting relationships
        $positions = Position::where('organization_id', $organization->id)
            ->where('is_active', true)
            ->with(['organizationalUnit', 'reportsTo', 'activeAssignments.user'])
            ->orderBy('sort_order')
            ->get();
        
        return response()->json([
            'data' => [
                'units' => $this->formatUnitsTree($units),
                'positions' => $this->formatPositionsTree($positions),
                'statistics' => [
                    'total_units' => OrganizationalUnit::where('organization_id', $organization->id)->where('is_active', true)->count(),
                    'total_positions' => Position::where('organization_id', $organization->id)->where('is_active', true)->count(),
                    'filled_positions' => PositionAssignment::whereHas('position', fn($q) => $q->where('organization_id', $organization->id))->where('is_active', true)->count(),
                    'vacant_positions' => Position::where('organization_id', $organization->id)->where('is_active', true)->whereDoesntHave('activeAssignments')->count(),
                ],
            ],
        ]);
    }

    /**
     * Get organizational units
     */
    public function getUnits(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        
        $units = OrganizationalUnit::where('organization_id', $organization->id)
            ->with(['parent', 'head', 'positions'])
            ->orderBy('level')
            ->orderBy('sort_order')
            ->get();
        
        return response()->json(['data' => $units]);
    }

    /**
     * Create organizational unit
     */
    public function createUnit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'type' => 'required|in:division,department,unit,section,team',
            'parent_id' => 'nullable|exists:organizational_units,id',
            'description' => 'nullable|string',
            'head_title' => 'nullable|string|max:100',
            'head_user_id' => 'nullable|exists:users,id',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $organization = $request->user()->organization;
        
        // Calculate level
        $level = 0;
        if ($request->parent_id) {
            $parent = OrganizationalUnit::find($request->parent_id);
            $level = $parent ? $parent->level + 1 : 0;
        }

        $unit = OrganizationalUnit::create([
            'organization_id' => $organization->id,
            'parent_id' => $request->parent_id,
            'name' => $request->name,
            'code' => $request->code,
            'type' => $request->type,
            'description' => $request->description,
            'head_title' => $request->head_title,
            'head_user_id' => $request->head_user_id,
            'level' => $level,
            'color' => $request->color,
            'sort_order' => $request->sort_order ?? 0,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Organizational unit created successfully',
            'data' => $unit->load(['parent', 'head']),
        ], 201);
    }

    /**
     * Update organizational unit
     */
    public function updateUnit(Request $request, int $id): JsonResponse
    {
        $unit = OrganizationalUnit::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50',
            'type' => 'sometimes|in:division,department,unit,section,team',
            'parent_id' => 'nullable|exists:organizational_units,id',
            'description' => 'nullable|string',
            'head_title' => 'nullable|string|max:100',
            'head_user_id' => 'nullable|exists:users,id',
            'color' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Prevent circular reference
        if ($request->parent_id && $request->parent_id == $id) {
            return response()->json(['message' => 'Cannot set unit as its own parent'], 422);
        }

        // Recalculate level if parent changed
        $data = $validator->validated();
        if (isset($data['parent_id'])) {
            if ($data['parent_id']) {
                $parent = OrganizationalUnit::find($data['parent_id']);
                $data['level'] = $parent ? $parent->level + 1 : 0;
            } else {
                $data['level'] = 0;
            }
        }

        $unit->update($data);

        return response()->json([
            'message' => 'Organizational unit updated successfully',
            'data' => $unit->load(['parent', 'head']),
        ]);
    }

    /**
     * Delete organizational unit
     */
    public function deleteUnit(int $id): JsonResponse
    {
        $unit = OrganizationalUnit::findOrFail($id);
        
        // Check for children
        if ($unit->children()->count() > 0) {
            return response()->json(['message' => 'Cannot delete unit with child units'], 422);
        }
        
        // Check for positions
        if ($unit->positions()->count() > 0) {
            return response()->json(['message' => 'Cannot delete unit with assigned positions'], 422);
        }
        
        $unit->delete();

        return response()->json(['message' => 'Organizational unit deleted successfully']);
    }

    /**
     * Get positions
     */
    public function getPositions(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        
        $positions = Position::where('organization_id', $organization->id)
            ->with(['organizationalUnit', 'reportsTo', 'activeAssignments.user'])
            ->orderBy('level')
            ->orderBy('sort_order')
            ->get();
        
        return response()->json(['data' => $positions]);
    }

    /**
     * Create position
     */
    public function createPosition(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'organizational_unit_id' => 'nullable|exists:organizational_units,id',
            'reports_to_id' => 'nullable|exists:positions,id',
            'level' => 'required|in:executive,senior_management,middle_management,supervisory,professional,support',
            'description' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'qualifications' => 'nullable|string',
            'grade' => 'nullable|integer',
            'headcount' => 'nullable|integer|min:1',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0',
            'is_supervisory' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $organization = $request->user()->organization;

        $position = Position::create([
            'organization_id' => $organization->id,
            ...$validator->validated(),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Position created successfully',
            'data' => $position->load(['organizationalUnit', 'reportsTo']),
        ], 201);
    }

    /**
     * Update position
     */
    public function updatePosition(Request $request, int $id): JsonResponse
    {
        $position = Position::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50',
            'organizational_unit_id' => 'nullable|exists:organizational_units,id',
            'reports_to_id' => 'nullable|exists:positions,id',
            'level' => 'sometimes|in:executive,senior_management,middle_management,supervisory,professional,support',
            'description' => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'qualifications' => 'nullable|string',
            'grade' => 'nullable|integer',
            'headcount' => 'nullable|integer|min:1',
            'min_salary' => 'nullable|numeric|min:0',
            'max_salary' => 'nullable|numeric|min:0',
            'is_supervisory' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Prevent circular reference
        if ($request->reports_to_id && $request->reports_to_id == $id) {
            return response()->json(['message' => 'Position cannot report to itself'], 422);
        }

        $position->update($validator->validated());

        return response()->json([
            'message' => 'Position updated successfully',
            'data' => $position->load(['organizationalUnit', 'reportsTo', 'activeAssignments.user']),
        ]);
    }

    /**
     * Delete position
     */
    public function deletePosition(int $id): JsonResponse
    {
        $position = Position::findOrFail($id);
        
        // Check for active assignments
        if ($position->activeAssignments()->count() > 0) {
            return response()->json(['message' => 'Cannot delete position with active assignments'], 422);
        }
        
        // Check for direct reports
        if ($position->directReports()->count() > 0) {
            return response()->json(['message' => 'Cannot delete position with direct reports. Reassign them first.'], 422);
        }
        
        $position->delete();

        return response()->json(['message' => 'Position deleted successfully']);
    }

    /**
     * Assign user to position
     */
    public function assignUser(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'position_id' => 'required|exists:positions,id',
            'user_id' => 'required|exists:users,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_primary' => 'nullable|boolean',
            'is_acting' => 'nullable|boolean',
            'employment_type' => 'nullable|in:full_time,part_time,contract',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Check if user already has this position
        $existingAssignment = PositionAssignment::where('position_id', $request->position_id)
            ->where('user_id', $request->user_id)
            ->where('is_active', true)
            ->first();
        
        if ($existingAssignment) {
            return response()->json(['message' => 'User already assigned to this position'], 422);
        }

        $assignment = PositionAssignment::create([
            'position_id' => $request->position_id,
            'user_id' => $request->user_id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_primary' => $request->is_primary ?? true,
            'is_acting' => $request->is_acting ?? false,
            'employment_type' => $request->employment_type ?? 'full_time',
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'User assigned to position successfully',
            'data' => $assignment->load(['position', 'user']),
        ], 201);
    }

    /**
     * Remove user from position
     */
    public function unassignUser(int $assignmentId): JsonResponse
    {
        $assignment = PositionAssignment::findOrFail($assignmentId);
        
        $assignment->update([
            'is_active' => false,
            'end_date' => now(),
        ]);

        return response()->json(['message' => 'User removed from position successfully']);
    }

    /**
     * Get segregation of duties rules
     */
    public function getSodRules(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        
        $rules = SegregationOfDuties::where('organization_id', $organization->id)
            ->with(['positionA', 'positionB'])
            ->get();
        
        return response()->json(['data' => $rules]);
    }

    /**
     * Create segregation of duties rule
     */
    public function createSodRule(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'rule_type' => 'required|in:incompatible_positions,incompatible_functions,approval_separation,custom',
            'position_a_id' => 'nullable|exists:positions,id',
            'position_b_id' => 'nullable|exists:positions,id',
            'function_a' => 'nullable|string|max:100',
            'function_b' => 'nullable|string|max:100',
            'severity' => 'required|in:warning,block',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $organization = $request->user()->organization;

        $rule = SegregationOfDuties::create([
            'organization_id' => $organization->id,
            ...$validator->validated(),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Segregation of duties rule created successfully',
            'data' => $rule->load(['positionA', 'positionB']),
        ], 201);
    }

    /**
     * Delete segregation of duties rule
     */
    public function deleteSodRule(int $id): JsonResponse
    {
        $rule = SegregationOfDuties::findOrFail($id);
        $rule->delete();

        return response()->json(['message' => 'Segregation of duties rule deleted successfully']);
    }

    /**
     * Get reporting lines
     */
    public function getReportingLines(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;
        
        $lines = ReportingLine::where('organization_id', $organization->id)
            ->where('is_active', true)
            ->with(['subordinatePosition', 'supervisorPosition'])
            ->get();
        
        return response()->json(['data' => $lines]);
    }

    /**
     * Create reporting line
     */
    public function createReportingLine(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subordinate_position_id' => 'required|exists:positions,id',
            'supervisor_position_id' => 'required|exists:positions,id|different:subordinate_position_id',
            'relationship_type' => 'required|in:direct,dotted,functional,project',
            'description' => 'nullable|string',
            'is_primary' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $organization = $request->user()->organization;

        // Check for existing
        $existing = ReportingLine::where('organization_id', $organization->id)
            ->where('subordinate_position_id', $request->subordinate_position_id)
            ->where('supervisor_position_id', $request->supervisor_position_id)
            ->where('relationship_type', $request->relationship_type)
            ->first();
        
        if ($existing) {
            return response()->json(['message' => 'This reporting line already exists'], 422);
        }

        $line = ReportingLine::create([
            'organization_id' => $organization->id,
            ...$validator->validated(),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Reporting line created successfully',
            'data' => $line->load(['subordinatePosition', 'supervisorPosition']),
        ], 201);
    }

    /**
     * Delete reporting line
     */
    public function deleteReportingLine(int $id): JsonResponse
    {
        $line = ReportingLine::findOrFail($id);
        $line->delete();

        return response()->json(['message' => 'Reporting line deleted successfully']);
    }

    // Helper methods
    private function formatUnitsTree($units): array
    {
        return $units->map(function ($unit) {
            return [
                'id' => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
                'type' => $unit->type,
                'level' => $unit->level,
                'color' => $unit->color,
                'head_title' => $unit->head_title,
                'head' => $unit->head ? [
                    'id' => $unit->head->id,
                    'name' => $unit->head->name,
                    'avatar' => $unit->head->avatar_url,
                ] : null,
                'positions_count' => $unit->positions->count(),
                'staff_count' => $unit->positions->sum(fn($p) => $p->activeAssignments->count()),
                'children' => $this->formatUnitsTree($unit->children),
            ];
        })->toArray();
    }

    private function formatPositionsTree($positions): array
    {
        // Find root positions (no reports_to)
        $rootPositions = $positions->whereNull('reports_to_id');
        
        return $this->buildPositionTree($rootPositions, $positions);
    }

    private function buildPositionTree($currentPositions, $allPositions): array
    {
        return $currentPositions->map(function ($position) use ($allPositions) {
            $directReports = $allPositions->where('reports_to_id', $position->id);
            $holder = $position->activeAssignments->first();
            
            return [
                'id' => $position->id,
                'title' => $position->title,
                'code' => $position->code,
                'level' => $position->level,
                'department' => $position->organizationalUnit?->name,
                'department_id' => $position->organizational_unit_id,
                'is_supervisory' => $position->is_supervisory,
                'is_vacant' => $position->activeAssignments->isEmpty(),
                'holder' => $holder ? [
                    'id' => $holder->user->id,
                    'name' => $holder->user->name,
                    'avatar' => $holder->user->avatar_url ?? null,
                    'is_acting' => $holder->is_acting,
                ] : null,
                'direct_reports' => $this->buildPositionTree($directReports, $allPositions),
            ];
        })->values()->toArray();
    }
}
