<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $query = Department::where('organization_id', $orgId)
            ->with(['parent:id,name', 'manager:id,name', 'office:id,name,code'])
            ->withCount('users');

        if ($request->has('office_id')) {
            $query->where(function ($q) use ($request) {
                if ($request->office_id) {
                    $q->where('office_id', $request->office_id);
                } else {
                    $q->whereNull('office_id');
                }
            });
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter root only
        if ($request->boolean('root_only')) {
            $query->whereNull('parent_id');
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $departments = $query->orderBy('sort_order')->orderBy('name')->get();

        return $this->success($departments);
    }

    /**
     * Get departments as a tree structure.
     */
    public function tree(Request $request)
    {
        $departments = Department::where('organization_id', $request->user()->organization_id)
            ->whereNull('parent_id')
            ->with(['descendants', 'manager:id,name'])
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success($departments);
    }

    /**
     * Store a newly created department.
     */
    public function store(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('departments')->where('organization_id', $orgId),
            ],
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'office_id' => 'nullable|exists:offices,id',
            'parent_id' => 'nullable|exists:departments,id',
            'manager_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        // Verify office belongs to same organization
        if (!empty($validated['office_id'])) {
            $office = \App\Models\Office::find($validated['office_id']);
            if ($office && $office->organization_id !== $orgId) {
                return $this->error('Invalid office', 400);
            }
        }

        // Verify parent belongs to same organization
        if (isset($validated['parent_id'])) {
            $parent = Department::find($validated['parent_id']);
            if ($parent->organization_id !== $request->user()->organization_id) {
                return $this->error('Invalid parent department', 400);
            }
        }

        $department = Department::create([
            'organization_id' => $orgId,
            'office_id' => $validated['office_id'] ?? null,
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'parent_id' => $validated['parent_id'] ?? null,
            'manager_id' => $validated['manager_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        $department->load(['parent:id,name', 'manager:id,name']);

        return $this->success($department, 'Department created successfully', 201);
    }

    /**
     * Display the specified department.
     */
    public function show(Request $request, Department $department)
    {
        if ($department->organization_id !== $request->user()->organization_id) {
            return $this->error('Department not found', 404);
        }

        $department->load(['parent:id,name,code', 'manager:id,name,email', 'office:id,name,code', 'children', 'users:id,name,email,position']);
        $department->loadCount('users');

        return $this->success($department);
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, Department $department)
    {
        if ($department->organization_id !== $request->user()->organization_id) {
            return $this->error('Department not found', 404);
        }

        $orgId = $request->user()->organization_id;
        $validated = $request->validate([
            'code' => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('departments')->where('organization_id', $orgId)->ignore($department->id),
            ],
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'office_id' => 'nullable|exists:offices,id',
            'parent_id' => 'nullable|exists:departments,id',
            'manager_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        if (array_key_exists('office_id', $validated) && !empty($validated['office_id'])) {
            $office = \App\Models\Office::find($validated['office_id']);
            if ($office && $office->organization_id !== $orgId) {
                return $this->error('Invalid office', 400);
            }
        }

        // Prevent setting itself as parent
        if (isset($validated['parent_id']) && $validated['parent_id'] == $department->id) {
            return $this->error('Department cannot be its own parent', 400);
        }

        // Verify parent belongs to same organization
        if (isset($validated['parent_id'])) {
            $parent = Department::find($validated['parent_id']);
            if ($parent && $parent->organization_id !== $request->user()->organization_id) {
                return $this->error('Invalid parent department', 400);
            }
        }

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $department->update($validated);
        $department->load(['parent:id,name', 'manager:id,name']);

        return $this->success($department, 'Department updated successfully');
    }

    /**
     * Remove the specified department.
     */
    public function destroy(Request $request, Department $department)
    {
        if ($department->organization_id !== $request->user()->organization_id) {
            return $this->error('Department not found', 404);
        }

        // Check if department has users
        if ($department->users()->count() > 0) {
            return $this->error('Cannot delete department with assigned users', 400);
        }

        // Check if department has children
        if ($department->children()->count() > 0) {
            return $this->error('Cannot delete department with sub-departments', 400);
        }

        $department->delete();

        return $this->success(null, 'Department deleted successfully');
    }

    /**
     * Get users in a department.
     */
    public function users(Request $request, Department $department)
    {
        if ($department->organization_id !== $request->user()->organization_id) {
            return $this->error('Department not found', 404);
        }

        $users = $department->users()
            ->with('roles:id,name,display_name')
            ->select(['id', 'name', 'email', 'position', 'status', 'avatar_path'])
            ->get();

        return $this->success($users);
    }
}
