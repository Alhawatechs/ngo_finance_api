<?php

namespace App\Http\Controllers\Api\V1\Payables;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    /**
     * Display a listing of vendors.
     */
    public function index(Request $request)
    {
        $query = Vendor::where('organization_id', $request->user()->organization_id);

        if ($request->has('vendor_type')) {
            $query->where('vendor_type', $request->vendor_type);
        }

        if ($request->boolean('is_active', true)) {
            $query->active();
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('vendor_code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $vendors = $query->orderBy('name')->paginate($request->input('per_page', 25));

        return $this->paginated($vendors);
    }

    /**
     * Store a newly created vendor.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'vendor_type' => 'required|in:supplier,contractor,consultant,service_provider,other',
            'tax_id' => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:50',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'credit_limit' => 'nullable|numeric|min:0',
            'ap_account_id' => 'nullable|exists:chart_of_accounts,id',
            'notes' => 'nullable|string',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['vendor_code'] = $this->generateVendorCode($request->user()->organization_id, $validated['vendor_type']);
        $validated['current_balance'] = 0;
        $validated['is_active'] = true;

        $vendor = Vendor::create($validated);

        return $this->success($vendor, 'Vendor created successfully', 201);
    }

    /**
     * Display the specified vendor.
     */
    public function show(Request $request, Vendor $vendor)
    {
        if ($vendor->organization_id !== $request->user()->organization_id) {
            return $this->error('Vendor not found', 404);
        }

        $vendor->load('apAccount');

        // Get recent invoices
        $recentInvoices = $vendor->invoices()
            ->with('office:id,name')
            ->orderBy('invoice_date', 'desc')
            ->limit(10)
            ->get();

        // Get outstanding balance
        $outstandingBalance = $vendor->invoices()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum('balance_due');

        return $this->success([
            'vendor' => $vendor,
            'recent_invoices' => $recentInvoices,
            'outstanding_balance' => $outstandingBalance,
        ]);
    }

    /**
     * Update the specified vendor.
     */
    public function update(Request $request, Vendor $vendor)
    {
        if ($vendor->organization_id !== $request->user()->organization_id) {
            return $this->error('Vendor not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'vendor_type' => 'sometimes|in:supplier,contractor,consultant,service_provider,other',
            'tax_id' => 'nullable|string|max:50',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'payment_terms' => 'nullable|string|max:50',
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string',
        ]);

        $vendor->update($validated);

        return $this->success($vendor, 'Vendor updated successfully');
    }

    /**
     * Remove the specified vendor (soft delete). Cannot delete if there are unpaid invoices.
     */
    public function destroy(Request $request, Vendor $vendor)
    {
        if ($vendor->organization_id !== $request->user()->organization_id) {
            return $this->error('Vendor not found', 404);
        }

        $outstanding = $vendor->invoices()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum('balance_due');

        if ($outstanding > 0) {
            return $this->error('Cannot delete vendor with outstanding invoices. Clear or write off balances first.', 422);
        }

        $vendor->delete();

        return $this->success(null, 'Vendor deleted successfully');
    }

    /**
     * Get vendor invoices.
     */
    public function invoices(Request $request, Vendor $vendor)
    {
        if ($vendor->organization_id !== $request->user()->organization_id) {
            return $this->error('Vendor not found', 404);
        }

        $query = $vendor->invoices()->with(['office:id,name', 'project:id,project_name']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->orderBy('invoice_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($invoices);
    }

    /**
     * Get vendor payments.
     */
    public function payments(Request $request, Vendor $vendor)
    {
        if ($vendor->organization_id !== $request->user()->organization_id) {
            return $this->error('Vendor not found', 404);
        }

        $payments = $vendor->payments()
            ->orderBy('payment_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($payments);
    }

    /**
     * Get vendor summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $vendors = Vendor::where('organization_id', $orgId)->active()->get();

        $totalPayable = $vendors->sum('current_balance');
        
        $overdueAmount = \App\Models\VendorInvoice::where('organization_id', $orgId)
            ->whereIn('status', ['approved', 'partially_paid'])
            ->where('due_date', '<', now())
            ->sum('balance_due');

        $byType = $vendors->groupBy('vendor_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'balance' => $group->sum('current_balance'),
            ];
        });

        return $this->success([
            'total_vendors' => $vendors->count(),
            'total_payable' => $totalPayable,
            'overdue_amount' => $overdueAmount,
            'by_type' => $byType,
        ]);
    }

    /**
     * Generate vendor code.
     */
    private function generateVendorCode(int $organizationId, string $type): string
    {
        $prefix = match($type) {
            'supplier' => 'SUP',
            'contractor' => 'CON',
            'consultant' => 'CST',
            'service_provider' => 'SVC',
            default => 'VEN',
        };

        $lastVendor = Vendor::where('organization_id', $organizationId)
            ->where('vendor_code', 'like', "{$prefix}-%")
            ->orderBy('vendor_code', 'desc')
            ->first();

        if ($lastVendor) {
            $lastNumber = (int) substr($lastVendor->vendor_code, strlen($prefix) + 1);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('%s-%05d', $prefix, $newNumber);
    }
}
