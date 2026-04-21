<?php

namespace App\Http\Controllers\Api\V1\Receivables;

use App\Http\Controllers\Controller;
use App\Models\Donor;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DonorController extends Controller
{
    /**
     * Display a listing of donors.
     */
    public function index(Request $request)
    {
        $query = Donor::where('organization_id', $request->user()->organization_id);

        if ($request->has('donor_type')) {
            $query->where('donor_type', $request->donor_type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('short_name', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 500);
        $donors = $query->orderBy('name')->paginate($perPage);

        return $this->paginated($donors);
    }

    /**
     * Store a newly created donor.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('donors')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'name' => 'required|string|max:255',
            'short_name' => 'nullable|string|max:50',
            'donor_type' => 'required|in:bilateral,multilateral,foundation,corporate,individual,government',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'country' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:255',
            'reporting_currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'reporting_frequency' => 'nullable|string|max:50',
            'default_budget_format_id' => 'nullable|exists:budget_format_templates,id',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active'] = true;

        $donor = Donor::create($validated);

        return $this->success($donor, 'Donor created successfully', 201);
    }

    /**
     * Display the specified donor.
     */
    public function show(Request $request, Donor $donor)
    {
        if ($donor->organization_id !== $request->user()->organization_id) {
            return $this->error('Donor not found', 404);
        }

        // Get grants
        $grants = $donor->grants()
            ->orderBy('start_date', 'desc')
            ->get();

        // Get total donations
        $totalDonations = $donor->donations()->sum('amount');

        // Get active projects count
        $activeProjectsCount = $donor->grants()
            ->whereHas('projects', fn($q) => $q->where('status', 'active'))
            ->count();

        return $this->success([
            'donor' => $donor,
            'grants' => $grants,
            'total_donations' => $totalDonations,
            'active_projects_count' => $activeProjectsCount,
        ]);
    }

    /**
     * Update the specified donor.
     */
    public function update(Request $request, Donor $donor)
    {
        if ($donor->organization_id !== $request->user()->organization_id) {
            return $this->error('Donor not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'short_name' => 'nullable|string|max:50',
            'donor_type' => 'sometimes|in:bilateral,multilateral,foundation,corporate,individual,government',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'country' => 'nullable|string|max:100',
            'website' => 'nullable|url|max:255',
            'reporting_currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'reporting_frequency' => 'nullable|string|max:50',
            'is_active' => 'sometimes|boolean',
            'default_budget_format_id' => 'nullable|exists:budget_format_templates,id',
        ]);

        $donor->update($validated);

        return $this->success($donor, 'Donor updated successfully');
    }

    /**
     * Remove the specified donor (soft delete).
     */
    public function destroy(Request $request, Donor $donor)
    {
        if ($donor->organization_id !== $request->user()->organization_id) {
            return $this->error('Donor not found', 404);
        }

        if ($donor->grants()->exists()) {
            return $this->error('Cannot delete donor with linked grants or contracts. Remove or reassign them first.', 422);
        }

        $donor->delete();

        return $this->success(null, 'Donor deleted successfully');
    }

    /**
     * Get donor grants.
     */
    public function grants(Request $request, Donor $donor)
    {
        if ($donor->organization_id !== $request->user()->organization_id) {
            return $this->error('Donor not found', 404);
        }

        $grants = $donor->grants()
            ->with('projects:id,grant_id,project_name,status')
            ->orderBy('start_date', 'desc')
            ->get();

        return $this->success($grants);
    }

    /**
     * Get donor donations.
     */
    public function donations(Request $request, Donor $donor)
    {
        if ($donor->organization_id !== $request->user()->organization_id) {
            return $this->error('Donor not found', 404);
        }

        $donations = $donor->donations()
            ->with(['grant:id,grant_code,grant_name'])
            ->orderBy('donation_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($donations);
    }

    /**
     * Get donor pledges.
     */
    public function pledges(Request $request, Donor $donor)
    {
        if ($donor->organization_id !== $request->user()->organization_id) {
            return $this->error('Donor not found', 404);
        }

        $pledges = $donor->pledges()
            ->with('grant:id,grant_code,grant_name')
            ->orderBy('pledge_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($pledges);
    }

    /**
     * Get donor summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $donors = Donor::where('organization_id', $orgId)->get();

        $byType = $donors->groupBy('donor_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'active' => $group->where('is_active', true)->count(),
            ];
        });

        $totalActive = $donors->where('is_active', true)->count();

        return $this->success([
            'total_donors' => $donors->count(),
            'active_donors' => $totalActive,
            'by_type' => $byType,
        ]);
    }
}
