<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Services\OfficeProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class OfficeController extends Controller
{
    /**
     * Display a listing of offices for the current organization.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        // Skip cache when searching (results are dynamic)
        if ($request->has('search')) {
            $search = $request->search;
            $offices = Office::where('organization_id', $orgId)
                ->withCount(['users'])
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                })
                ->orderBy('is_head_office', 'desc')->orderBy('code')->get();

            return $this->success($offices);
        }

        $isActive = $request->has('is_active') ? $request->boolean('is_active') : null;
        $cacheKey = "offices_{$orgId}_" . ($isActive === null ? 'all' : ($isActive ? 'active' : 'inactive'));

        $offices = Cache::remember($cacheKey, 300, function () use ($orgId, $isActive) {
            $query = Office::where('organization_id', $orgId)->withCount(['users']);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
            return $query->orderBy('is_head_office', 'desc')->orderBy('code')->get();
        });

        return $this->success($offices);
    }

    /**
     * Store a newly created office.
     */
    public function store(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:10',
                Rule::unique('offices')->where('organization_id', $organizationId),
            ],
            'is_head_office' => 'boolean',
            'address' => 'nullable|string|max:500',
            'city' => 'required|string|max:100',
            'province' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'key_staff' => 'nullable|array',
            'key_staff.*.name' => 'required_with:key_staff.*|string|max:255',
            'key_staff.*.role' => 'nullable|string|max:100',
            'key_staff.*.email' => 'nullable|email|max:255',
            'key_staff.*.phone' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'cost_center_prefix' => 'nullable|string|max:20',
            'operating_hours' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'provision_database' => 'boolean',
        ]);

        $validated['organization_id'] = $organizationId;
        $provisionDatabase = $validated['provision_database'] ?? false;
        unset($validated['provision_database']);
        if (isset($validated['key_staff'])) {
            $validated['key_staff'] = array_values(array_filter(
                $validated['key_staff'],
                fn ($s) => !empty(trim($s['name'] ?? ''))
            ));
        }

        if (!empty($validated['is_head_office'])) {
            Office::where('organization_id', $organizationId)->update(['is_head_office' => false]);
        }

        $office = Office::create($validated);

        if ($provisionDatabase && !$office->is_head_office) {
            try {
                app(OfficeProvisioningService::class)->provision($office);
                $office->refresh();
            } catch (\Throwable $e) {
                return $this->success($office->load([]), 'Office created. Database provisioning failed: ' . $e->getMessage(), 201);
            }
        }

        $this->clearOfficeCache($organizationId);
        return $this->success($office, 'Office created successfully', 201);
    }

    /**
     * Provision financial database for an existing office.
     */
    public function provision(Request $request, Office $office)
    {
        if ($office->organization_id !== $request->user()->organization_id) {
            return $this->error('Office not found', 404);
        }
        if ($office->is_head_office) {
            return $this->error('Head office uses the central database', 400);
        }
        try {
            app(OfficeProvisioningService::class)->provision($office);
            $office->refresh();
            return $this->success($office, 'Office database provisioned successfully');
        } catch (\Throwable $e) {
            return $this->error('Provisioning failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified office.
     */
    public function show(Request $request, Office $office)
    {
        if ($office->organization_id !== $request->user()->organization_id) {
            return $this->error('Office not found', 404);
        }

        $office->loadCount(['users', 'bankAccounts', 'cashAccounts', 'vouchers', 'departments']);

        return $this->success($office);
    }

    /**
     * Update the specified office.
     */
    public function update(Request $request, Office $office)
    {
        if ($office->organization_id !== $request->user()->organization_id) {
            return $this->error('Office not found', 404);
        }

        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes',
                'string',
                'max:10',
                Rule::unique('offices')->where('organization_id', $organizationId)->ignore($office->id),
            ],
            'is_head_office' => 'boolean',
            'address' => 'nullable|string|max:500',
            'city' => 'sometimes|string|max:100',
            'province' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'manager_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'key_staff' => 'nullable|array',
            'key_staff.*.name' => 'required_with:key_staff.*|string|max:255',
            'key_staff.*.role' => 'nullable|string|max:100',
            'key_staff.*.email' => 'nullable|email|max:255',
            'key_staff.*.phone' => 'nullable|string|max:50',
            'timezone' => 'nullable|string|max:50',
            'cost_center_prefix' => 'nullable|string|max:20',
            'operating_hours' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['key_staff'])) {
            $validated['key_staff'] = array_values(array_filter(
                $validated['key_staff'],
                fn ($s) => !empty(trim($s['name'] ?? ''))
            ));
        }

        if (!empty($validated['is_head_office']) && !$office->is_head_office) {
            Office::where('organization_id', $organizationId)->where('id', '!=', $office->id)->update(['is_head_office' => false]);
        }

        $office->update($validated);
        $this->clearOfficeCache($office->organization_id);

        return $this->success($office, 'Office updated successfully');
    }

    /**
     * Remove the specified office (soft delete).
     */
    public function destroy(Request $request, Office $office)
    {
        if ($office->organization_id !== $request->user()->organization_id) {
            return $this->error('Office not found', 404);
        }

        if ($office->is_head_office) {
            return $this->error('Cannot delete the head office. Assign another office as head office first.', 422);
        }

        $orgId = $office->organization_id;
        $office->delete();
        $this->clearOfficeCache($orgId);

        return $this->success(null, 'Office deleted successfully');
    }

    private function clearOfficeCache(int $orgId): void
    {
        foreach (['all', 'active', 'inactive'] as $suffix) {
            Cache::forget("offices_{$orgId}_{$suffix}");
        }
    }
}
