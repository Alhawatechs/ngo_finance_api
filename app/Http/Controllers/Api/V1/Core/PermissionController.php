<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /**
     * Display a listing of permissions.
     */
    public function index(Request $request)
    {
        $query = Permission::query();

        // Filter by module
        if ($request->has('module')) {
            $query->where('module', $request->module);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('display_name', 'like', "%{$search}%");
            });
        }

        $permissions = $query->orderBy('module')->orderBy('display_name')->get();

        // Group by module
        $grouped = $permissions->groupBy('module');

        return $this->success([
            'permissions' => $permissions,
            'grouped' => $grouped,
            'modules' => $permissions->pluck('module')->unique()->values(),
        ]);
    }
}
