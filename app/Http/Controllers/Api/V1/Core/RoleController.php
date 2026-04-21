<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
use App\Services\OfficeScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(
        protected OfficeScopeService $officeScope
    ) {}

    /**
     * Chart of accounts permissions that only Super Admin or Finance Director may attach to roles
     * (users with assign-chart-of-accounts-permissions).
     *
     * @param  \App\Models\User  $user
     * @param  array<int>  $permissionIds
     * @return array<int>
     */
    protected function filterAssignableCoaPermissions($user, array $permissionIds): array
    {
        $restrictedNames = [
            'edit-chart-of-accounts',
            'delete-chart-of-accounts',
            'delete-chart-of-accounts-permanently',
            'assign-chart-of-accounts-permissions',
            'view-opening-balances',
            'edit-opening-balances',
        ];
        $restrictedIds = Permission::whereIn('name', $restrictedNames)->pluck('id')->all();
        $wanted = array_intersect($permissionIds, $restrictedIds);
        if ($wanted === []) {
            return $permissionIds;
        }
        if ($user->can('assign-chart-of-accounts-permissions')) {
            return $permissionIds;
        }
        abort(403, 'Only Super Administrator or Finance Director can assign chart of accounts permissions to roles.');
    }

    /**
     * Display a listing of roles.
     */
    public function index(Request $request)
    {
        $authUser = $request->user();
        $query = Role::query()
            ->where('organization_id', $authUser->organization_id)
            ->with(['office:id,name,code'])
            ->withCount('permissions', 'users');

        $allowedOfficeIds = $this->officeScope->listOfficeIdsForRoles($authUser);
        if ($allowedOfficeIds !== null) {
            if ($this->officeScope->canManageOrganizationRoles($authUser)) {
                $query->where(function ($q) use ($allowedOfficeIds) {
                    $q->whereNull('office_id');
                    if ($allowedOfficeIds !== []) {
                        $q->orWhereIn('office_id', $allowedOfficeIds);
                    }
                });
            } else {
                $query->whereIn('office_id', $allowedOfficeIds);
            }
        }
        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('display_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by system roles
        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }

        $roles = $query->orderBy('display_name')->get();

        return $this->success($roles);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $authUser = $request->user();
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where(function ($q) use ($authUser, $request) {
                    $q->where('organization_id', $authUser->organization_id);
                    $oid = $request->input('office_id');
                    if ($oid === null || $oid === '') {
                        $q->whereNull('office_id');
                    } else {
                        $q->where('office_id', $oid);
                    }
                }),
            ],
            'display_name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'office_id' => 'nullable|exists:offices,id',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $officeId = array_key_exists('office_id', $validated) ? $validated['office_id'] : null;
        if (!$this->officeScope->canActOnRoleInOffice($authUser, $officeId)) {
            return $this->error($officeId === null
                ? 'You do not have permission to create organization-level roles'
                : 'You do not have permission to create roles for this office', 403);
        }

        $role = Role::create([
            'organization_id' => $authUser->organization_id,
            'office_id' => $officeId,
            'name' => $validated['name'],
            'display_name' => $validated['display_name'],
            'description' => $validated['description'] ?? null,
            'guard_name' => 'web',
            'is_system' => false,
        ]);

        if (isset($validated['permissions'])) {
            $validated['permissions'] = $this->filterAssignableCoaPermissions($authUser, $validated['permissions']);
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return $this->success($role, 'Role created successfully', 201);
    }

    /**
     * Display the specified role.
     */
    public function show(Request $request, Role $role)
    {
        $authUser = $request->user();
        if ($role->organization_id !== $authUser->organization_id) {
            return $this->error('Role not found', 404);
        }
        if (!$this->officeScope->canActOnRoleInOffice($authUser, $role->office_id)) {
            return $this->error('You do not have permission to view this role', 403);
        }

        $role->load(['permissions', 'office:id,name,code']);
        $role->loadCount('users');

        return $this->success($role);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, Role $role)
    {
        $authUser = $request->user();
        if ($role->organization_id !== $authUser->organization_id) {
            return $this->error('Role not found', 404);
        }
        if (!$this->officeScope->canActOnRoleInOffice($authUser, $role->office_id)) {
            return $this->error('You do not have permission to edit this role', 403);
        }

        if ($role->is_system) {
            return $this->error('System roles cannot be modified', 403);
        }

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('roles')->where(function ($q) use ($authUser, $role) {
                    $q->where('organization_id', $authUser->organization_id);
                    if ($role->office_id === null) {
                        $q->whereNull('office_id');
                    } else {
                        $q->where('office_id', $role->office_id);
                    }
                })->ignore($role->id),
            ],
            'display_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:500',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $role->update([
            'name' => $validated['name'] ?? $role->name,
            'display_name' => $validated['display_name'] ?? $role->display_name,
            'description' => $validated['description'] ?? $role->description,
        ]);

        if (isset($validated['permissions'])) {
            $validated['permissions'] = $this->filterAssignableCoaPermissions($authUser, $validated['permissions']);
            $role->syncPermissions($validated['permissions']);
        }

        $role->load('permissions');

        return $this->success($role, 'Role updated successfully');
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Request $request, Role $role)
    {
        $authUser = $request->user();
        if ($role->organization_id !== $authUser->organization_id) {
            return $this->error('Role not found', 404);
        }
        if (!$this->officeScope->canActOnRoleInOffice($authUser, $role->office_id)) {
            return $this->error('You do not have permission to delete this role', 403);
        }

        // Prevent deleting system roles
        if ($role->is_system) {
            return $this->error('System roles cannot be deleted', 403);
        }

        // Check if role is assigned to any users
        if ($role->users()->count() > 0) {
            return $this->error('Cannot delete role that is assigned to users', 400);
        }

        $role->delete();

        return $this->success(null, 'Role deleted successfully');
    }

    /**
     * Assign permissions to a role.
     */
    public function assignPermissions(Request $request, Role $role)
    {
        $authUser = $request->user();
        if ($role->organization_id !== $authUser->organization_id) {
            return $this->error('Role not found', 404);
        }
        if (!$this->officeScope->canActOnRoleInOffice($authUser, $role->office_id)) {
            return $this->error('You do not have permission to assign permissions to this role', 403);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,id',
        ]);

        $validated['permissions'] = $this->filterAssignableCoaPermissions($authUser, $validated['permissions']);
        $role->syncPermissions($validated['permissions']);
        $role->load('permissions');

        return $this->success($role, 'Permissions assigned successfully');
    }

    /**
     * Get access matrix (roles with their permissions).
     */
    public function accessMatrix(Request $request)
    {
        $authUser = $request->user();
        $query = Role::where('organization_id', $authUser->organization_id)
            ->with(['permissions:id,name,display_name,module', 'office:id,name,code'])
            ->withCount('users');
        $allowedOfficeIds = $this->officeScope->listOfficeIdsForRoles($authUser);
        if ($allowedOfficeIds !== null) {
            if ($this->officeScope->canManageOrganizationRoles($authUser)) {
                $query->where(function ($q) use ($allowedOfficeIds) {
                    $q->whereNull('office_id');
                    if ($allowedOfficeIds !== []) {
                        $q->orWhereIn('office_id', $allowedOfficeIds);
                    }
                });
            } else {
                $query->whereIn('office_id', $allowedOfficeIds);
            }
        }
        $roles = $query->orderBy('display_name')->get()->map(function ($role) {
            return [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                'office_id' => $role->office_id,
                'office' => $role->office,
                'users_count' => $role->users_count,
                'permission_ids' => $role->permissions->pluck('id')->toArray(),
            ];
        });

        // Get all permissions grouped by module
        $permissions = Permission::orderBy('module')
            ->orderBy('display_name')
            ->get();

        $permissionsByModule = $permissions->groupBy('module')->map(function ($group) {
            return $group->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->display_name,
                ];
            })->values();
        });

        return $this->success([
            'roles' => $roles,
            'permissions' => $permissions->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'display_name' => $p->display_name,
                    'module' => $p->module,
                ];
            }),
            'permissions_by_module' => $permissionsByModule,
            'modules' => $permissions->pluck('module')->unique()->values(),
        ]);
    }
}
