<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    /**
     * Display a listing of fixed assets.
     */
    public function index(Request $request)
    {
        $query = DB::table('fixed_assets')
            ->where('organization_id', $request->user()->organization_id)
            ->leftJoin('offices', 'fixed_assets.office_id', '=', 'offices.id')
            ->leftJoin('asset_categories', 'fixed_assets.category_id', '=', 'asset_categories.id')
            ->select(
                'fixed_assets.*',
                'offices.name as office_name',
                'asset_categories.name as category_name'
            );

        if ($request->has('status')) {
            $query->where('fixed_assets.status', $request->status);
        }

        if ($request->has('office_id')) {
            $query->where('fixed_assets.office_id', $request->office_id);
        }

        if ($request->has('category_id')) {
            $query->where('fixed_assets.category_id', $request->category_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('fixed_assets.asset_code', 'like', "%{$search}%")
                  ->orWhere('fixed_assets.name', 'like', "%{$search}%")
                  ->orWhere('fixed_assets.serial_number', 'like', "%{$search}%");
            });
        }

        $assets = $query->orderBy('fixed_assets.acquisition_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($assets);
    }

    /**
     * Store a newly created asset.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'office_id' => 'required|exists:offices,id',
            'category_id' => 'required|exists:asset_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'serial_number' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'manufacturer' => 'nullable|string|max:100',
            'acquisition_date' => 'required|date',
            'acquisition_cost' => 'required|numeric|min:0',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'useful_life_years' => 'required|integer|min:1',
            'salvage_value' => 'nullable|numeric|min:0',
            'depreciation_method' => 'required|in:straight_line,declining_balance,units_of_production',
            'location' => 'nullable|string|max:255',
            'custodian_id' => 'nullable|exists:users,id',
            'project_id' => 'nullable|exists:projects,id',
            'fund_id' => 'nullable|exists:funds,id',
            'warranty_expiry' => 'nullable|date',
        ]);

        $assetCode = $this->generateAssetCode($request->user()->organization_id);

        $assetId = DB::table('fixed_assets')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'office_id' => $validated['office_id'],
            'category_id' => $validated['category_id'],
            'asset_code' => $assetCode,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'serial_number' => $validated['serial_number'] ?? null,
            'model' => $validated['model'] ?? null,
            'manufacturer' => $validated['manufacturer'] ?? null,
            'acquisition_date' => $validated['acquisition_date'],
            'acquisition_cost' => $validated['acquisition_cost'],
            'currency' => $validated['currency'],
            'current_value' => $validated['acquisition_cost'],
            'accumulated_depreciation' => 0,
            'useful_life_years' => $validated['useful_life_years'],
            'salvage_value' => $validated['salvage_value'] ?? 0,
            'depreciation_method' => $validated['depreciation_method'],
            'location' => $validated['location'] ?? null,
            'custodian_id' => $validated['custodian_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'fund_id' => $validated['fund_id'] ?? null,
            'warranty_expiry' => $validated['warranty_expiry'] ?? null,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(
            DB::table('fixed_assets')->find($assetId),
            'Asset created successfully',
            201
        );
    }

    /**
     * Display the specified asset.
     */
    public function show(Request $request, int $id)
    {
        $asset = DB::table('fixed_assets')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$asset) {
            return $this->error('Asset not found', 404);
        }

        // Get depreciation history
        $depreciationHistory = DB::table('asset_depreciation')
            ->where('asset_id', $id)
            ->orderBy('depreciation_date', 'desc')
            ->get();

        // Get maintenance history
        $maintenanceHistory = DB::table('asset_maintenance')
            ->where('asset_id', $id)
            ->orderBy('maintenance_date', 'desc')
            ->get();

        return $this->success([
            'asset' => $asset,
            'depreciation_history' => $depreciationHistory,
            'maintenance_history' => $maintenanceHistory,
        ]);
    }

    /**
     * Update the specified asset.
     */
    public function update(Request $request, int $id)
    {
        $asset = DB::table('fixed_assets')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$asset) {
            return $this->error('Asset not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'custodian_id' => 'nullable|exists:users,id',
            'status' => 'sometimes|in:active,inactive,disposed,transferred',
        ]);

        DB::table('fixed_assets')
            ->where('id', $id)
            ->update(array_merge($validated, ['updated_at' => now()]));

        return $this->success(
            DB::table('fixed_assets')->find($id),
            'Asset updated successfully'
        );
    }

    /**
     * Remove the specified asset. Only assets with no depreciation history can be deleted.
     */
    public function destroy(Request $request, $assetId)
    {
        $id = (int) $assetId;
        $asset = DB::table('fixed_assets')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$asset) {
            return $this->error('Asset not found', 404);
        }

        $hasDepreciation = DB::table('asset_depreciation')->where('asset_id', $id)->exists();
        if ($hasDepreciation) {
            return $this->error('Cannot delete asset with depreciation history. Dispose the asset instead.', 422);
        }

        DB::table('fixed_assets')->where('id', $id)->delete();

        return $this->success(null, 'Asset deleted successfully');
    }

    /**
     * Calculate and record depreciation.
     */
    public function calculateDepreciation(Request $request, int $id)
    {
        $asset = DB::table('fixed_assets')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$asset) {
            return $this->error('Asset not found', 404);
        }

        if ($asset->status !== 'active') {
            return $this->error('Can only depreciate active assets', 400);
        }

        $validated = $request->validate([
            'depreciation_date' => 'required|date',
            'period' => 'required|string|max:20', // e.g., "2024-01" for monthly
        ]);

        // Check if already depreciated for this period
        $existing = DB::table('asset_depreciation')
            ->where('asset_id', $id)
            ->where('period', $validated['period'])
            ->exists();

        if ($existing) {
            return $this->error('Depreciation already recorded for this period', 400);
        }

        // Calculate depreciation amount
        $depreciableAmount = $asset->acquisition_cost - $asset->salvage_value;
        $monthlyDepreciation = 0;

        switch ($asset->depreciation_method) {
            case 'straight_line':
                $monthlyDepreciation = $depreciableAmount / ($asset->useful_life_years * 12);
                break;
            case 'declining_balance':
                $rate = 2 / $asset->useful_life_years; // Double declining
                $monthlyDepreciation = ($asset->current_value * $rate) / 12;
                break;
        }

        // Don't depreciate below salvage value
        $newAccumulated = $asset->accumulated_depreciation + $monthlyDepreciation;
        if ($newAccumulated > $depreciableAmount) {
            $monthlyDepreciation = $depreciableAmount - $asset->accumulated_depreciation;
        }

        if ($monthlyDepreciation <= 0) {
            return $this->error('Asset is fully depreciated', 400);
        }

        // Record depreciation
        DB::table('asset_depreciation')->insert([
            'asset_id' => $id,
            'depreciation_date' => $validated['depreciation_date'],
            'period' => $validated['period'],
            'amount' => $monthlyDepreciation,
            'accumulated_after' => $asset->accumulated_depreciation + $monthlyDepreciation,
            'book_value_after' => $asset->acquisition_cost - $asset->accumulated_depreciation - $monthlyDepreciation,
            'created_at' => now(),
        ]);

        // Update asset
        DB::table('fixed_assets')
            ->where('id', $id)
            ->update([
                'accumulated_depreciation' => $asset->accumulated_depreciation + $monthlyDepreciation,
                'current_value' => $asset->acquisition_cost - $asset->accumulated_depreciation - $monthlyDepreciation,
                'last_depreciation_date' => $validated['depreciation_date'],
                'updated_at' => now(),
            ]);

        return $this->success([
            'depreciation_amount' => $monthlyDepreciation,
            'new_book_value' => $asset->acquisition_cost - $asset->accumulated_depreciation - $monthlyDepreciation,
        ], 'Depreciation recorded successfully');
    }

    /**
     * Dispose asset.
     */
    public function dispose(Request $request, int $id)
    {
        $asset = DB::table('fixed_assets')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$asset) {
            return $this->error('Asset not found', 404);
        }

        $validated = $request->validate([
            'disposal_date' => 'required|date',
            'disposal_method' => 'required|in:sold,donated,scrapped,lost,stolen',
            'disposal_amount' => 'nullable|numeric|min:0',
            'disposal_notes' => 'nullable|string',
        ]);

        $gainLoss = ($validated['disposal_amount'] ?? 0) - $asset->current_value;

        DB::table('fixed_assets')
            ->where('id', $id)
            ->update([
                'status' => 'disposed',
                'disposal_date' => $validated['disposal_date'],
                'disposal_method' => $validated['disposal_method'],
                'disposal_amount' => $validated['disposal_amount'] ?? 0,
                'disposal_gain_loss' => $gainLoss,
                'disposal_notes' => $validated['disposal_notes'] ?? null,
                'updated_at' => now(),
            ]);

        return $this->success([
            'gain_loss' => $gainLoss,
        ], 'Asset disposed successfully');
    }

    /**
     * Get asset categories.
     */
    public function categories(Request $request)
    {
        $categories = DB::table('asset_categories')
            ->where('organization_id', $request->user()->organization_id)
            ->orWhereNull('organization_id') // Global categories
            ->orderBy('name')
            ->get();

        return $this->success($categories);
    }

    /**
     * Get asset summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $assets = DB::table('fixed_assets')
            ->where('organization_id', $orgId)
            ->get();

        $activeAssets = $assets->where('status', 'active');

        $totalCost = $activeAssets->sum('acquisition_cost');
        $totalDepreciation = $activeAssets->sum('accumulated_depreciation');
        $totalBookValue = $activeAssets->sum('current_value');

        $byCategory = DB::table('fixed_assets')
            ->where('fixed_assets.organization_id', $orgId)
            ->where('fixed_assets.status', 'active')
            ->leftJoin('asset_categories', 'fixed_assets.category_id', '=', 'asset_categories.id')
            ->groupBy('asset_categories.name')
            ->selectRaw('asset_categories.name as category, COUNT(*) as count, SUM(fixed_assets.current_value) as value')
            ->get();

        $byStatus = $assets->groupBy('status')->map->count();

        return $this->success([
            'total_assets' => $assets->count(),
            'active_assets' => $activeAssets->count(),
            'total_cost' => $totalCost,
            'total_depreciation' => $totalDepreciation,
            'total_book_value' => $totalBookValue,
            'by_category' => $byCategory,
            'by_status' => $byStatus,
        ]);
    }

    /**
     * Generate asset code.
     */
    private function generateAssetCode(int $organizationId): string
    {
        $lastAsset = DB::table('fixed_assets')
            ->where('organization_id', $organizationId)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastAsset && preg_match('/AST-(\d+)/', $lastAsset->asset_code, $matches)) {
            $sequence = (int)$matches[1] + 1;
        } else {
            $sequence = 1;
        }

        return sprintf('AST-%06d', $sequence);
    }
}
