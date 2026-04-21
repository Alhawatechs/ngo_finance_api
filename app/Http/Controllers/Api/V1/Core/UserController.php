<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OfficeScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        protected OfficeScopeService $officeScope
    ) {}

    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $authUser = $request->user();
        $query = User::where('organization_id', $authUser->organization_id)
            ->with(['office:id,name,code', 'roles:id,name,display_name']);

        $allowedOfficeIds = $this->officeScope->listOfficeIdsForUsers($authUser);
        if ($allowedOfficeIds !== null) {
            if ($allowedOfficeIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('office_id', $allowedOfficeIds);
            }
        }

        // Filter by office (central admin can filter; regional is effectively single office)
        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by role (name or role_id)
        if ($request->has('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }
        if ($request->has('role_id')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('roles.id', $request->role_id);
            });
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%");
            });
        }

        // Sort (whitelist — invalid column/order caused SQL errors / 500)
        $allowedSort = [
            'id',
            'name',
            'email',
            'employee_id',
            'status',
            'position',
            'department',
            'phone',
            'created_at',
            'updated_at',
            'last_login_at',
        ];
        $sortBy = $request->input('sort_by', 'name');
        if (! is_string($sortBy) || ! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'name';
        }
        $sortDir = strtolower((string) $request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortBy, $sortDir);

        $users = $query->paginate($request->input('per_page', 15));

        // Append initials and avatar_url for API (avoids $appends conflict with traits)
        $users->getCollection()->each(fn (User $u) => $u->append(['initials', 'avatar_url']));

        return $this->paginated($users);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $authUser = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users')->where('organization_id', $authUser->organization_id),
            ],
            'password' => 'required|string|min:8',
            'office_id' => 'nullable|exists:offices,id',
            'can_manage_all_offices' => 'nullable|boolean',
            'employee_id' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'approval_level' => 'nullable|integer|min:1|max:4',
            'approval_limit' => 'nullable|numeric|min:0',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,id',
        ]);

        $officeId = $validated['office_id'] ?? null;
        if (!$this->officeScope->canActOnUserInOffice($authUser, $officeId)) {
            return $this->error('You do not have permission to create users for this office', 403);
        }

        $validated['organization_id'] = $authUser->organization_id;
        $validated['password'] = Hash::make($validated['password']);
        $validated['status'] = 'active';
        $validated['can_manage_all_offices'] = $validated['can_manage_all_offices'] ?? false;

        $user = User::create($validated);

        // Assign roles
        if (!empty($validated['roles'])) {
            $user->roles()->sync($validated['roles']);
        }

        $user->load(['office:id,name,code', 'roles:id,name,display_name']);

        return $this->success($user, 'User created successfully', 201);
    }

    /**
     * Display the specified user.
     */
    public function show(Request $request, User $user)
    {
        $authUser = $request->user();
        if ($user->organization_id !== $authUser->organization_id) {
            return $this->error('User not found', 404);
        }
        if (!$this->officeScope->canActOnUserInOffice($authUser, $user->office_id)) {
            return $this->error('You do not have permission to view this user', 403);
        }

        $user->load(['office', 'roles.permissions', 'organization']);

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'employee_id' => $user->employee_id,
            'position' => $user->position,
            'department' => $user->department,
            'status' => $user->status,
            'office_id' => $user->office_id,
            'can_manage_all_offices' => (bool) ($user->can_manage_all_offices ?? false),
            'approval_level' => $user->approval_level,
            'approval_limit' => $user->approval_limit,
            'avatar_url' => $user->avatar_url,
            'initials' => $user->initials,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at->toIso8601String(),
            'office' => $user->office,
            'roles' => $user->roles,
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $authUser = $request->user();
        if ($user->organization_id !== $authUser->organization_id) {
            return $this->error('User not found', 404);
        }
        if (!$this->officeScope->canActOnUserInOffice($authUser, $user->office_id)) {
            return $this->error('You do not have permission to edit this user', 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                Rule::unique('users')->where('organization_id', $authUser->organization_id)->ignore($user->id),
            ],
            'password' => 'nullable|string|min:8',
            'office_id' => 'nullable|exists:offices,id',
            'can_manage_all_offices' => 'nullable|boolean',
            'employee_id' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'approval_level' => 'nullable|integer|min:1|max:4',
            'approval_limit' => 'nullable|numeric|min:0',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,id',
        ]);

        $newOfficeId = $validated['office_id'] ?? $user->office_id;
        if (array_key_exists('office_id', $validated) && !$this->officeScope->canActOnUserInOffice($authUser, $validated['office_id'])) {
            return $this->error('You do not have permission to assign this office', 403);
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        if (isset($validated['can_manage_all_offices'])) {
            $validated['can_manage_all_offices'] = (bool) $validated['can_manage_all_offices'];
        }
        $user->update($validated);

        // Update roles if provided
        if (isset($validated['roles'])) {
            $user->roles()->sync($validated['roles']);
        }

        $user->load(['office:id,name,code', 'roles:id,name,display_name']);

        return $this->success($user, 'User updated successfully');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user)
    {
        $authUser = $request->user();
        if ($user->organization_id !== $authUser->organization_id) {
            return $this->error('User not found', 404);
        }
        if (!$this->officeScope->canActOnUserInOffice($authUser, $user->office_id)) {
            return $this->error('You do not have permission to delete this user', 403);
        }

        // Don't allow deleting yourself
        if ($user->id === $request->user()->id) {
            return $this->error('You cannot delete your own account', 400);
        }

        $user->delete();

        return $this->success(null, 'User deleted successfully');
    }

    /**
     * Activate a user.
     */
    public function activate(Request $request, User $user)
    {
        $authUser = $request->user();
        if ($user->organization_id !== $authUser->organization_id) {
            return $this->error('User not found', 404);
        }
        if (!$this->officeScope->canActOnUserInOffice($authUser, $user->office_id)) {
            return $this->error('You do not have permission to activate this user', 403);
        }

        $user->update(['status' => 'active']);

        return $this->success($user, 'User activated successfully');
    }

    /**
     * Deactivate a user.
     */
    public function deactivate(Request $request, User $user)
    {
        $authUser = $request->user();
        if ($user->organization_id !== $authUser->organization_id) {
            return $this->error('User not found', 404);
        }
        if (!$this->officeScope->canActOnUserInOffice($authUser, $user->office_id)) {
            return $this->error('You do not have permission to deactivate this user', 403);
        }

        if ($user->id === $request->user()->id) {
            return $this->error('You cannot deactivate your own account', 400);
        }

        $user->update(['status' => 'inactive']);
        $user->tokens()->delete(); // Revoke all tokens

        return $this->success($user, 'User deactivated successfully');
    }
}
