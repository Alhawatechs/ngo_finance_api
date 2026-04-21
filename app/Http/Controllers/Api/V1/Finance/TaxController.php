<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxController extends Controller
{
    /**
     * Display a listing of tax entries.
     */
    public function index(Request $request)
    {
        $query = DB::table('tax_entries')
            ->where('organization_id', $request->user()->organization_id)
            ->leftJoin('tax_types', 'tax_entries.tax_type_id', '=', 'tax_types.id')
            ->select('tax_entries.*', 'tax_types.name as tax_type_name', 'tax_types.rate as tax_rate');

        if ($request->has('tax_type_id')) {
            $query->where('tax_entries.tax_type_id', $request->tax_type_id);
        }

        if ($request->has('status')) {
            $query->where('tax_entries.status', $request->status);
        }

        if ($request->has('period')) {
            $query->where('tax_entries.tax_period', $request->period);
        }

        $entries = $query->orderBy('tax_entries.entry_date', 'desc')
            ->paginate($request->input('per_page', 25));

        return $this->paginated($entries);
    }

    /**
     * Store a newly created tax entry.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'tax_type_id' => 'required|exists:tax_types,id',
            'entry_date' => 'required|date',
            'tax_period' => 'required|string|max:20', // e.g., "2024-Q1"
            'description' => 'required|string|max:255',
            'taxable_amount' => 'required|numeric|min:0',
            'tax_amount' => 'required|numeric|min:0',
            'reference_type' => 'nullable|string|max:50', // voucher, invoice, etc.
            'reference_id' => 'nullable|integer',
            'vendor_id' => 'nullable|exists:vendors,id',
            'notes' => 'nullable|string',
        ]);

        $entryNumber = $this->generateEntryNumber($request->user()->organization_id);

        $entryId = DB::table('tax_entries')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'entry_number' => $entryNumber,
            'tax_type_id' => $validated['tax_type_id'],
            'entry_date' => $validated['entry_date'],
            'tax_period' => $validated['tax_period'],
            'description' => $validated['description'],
            'taxable_amount' => $validated['taxable_amount'],
            'tax_amount' => $validated['tax_amount'],
            'reference_type' => $validated['reference_type'] ?? null,
            'reference_id' => $validated['reference_id'] ?? null,
            'vendor_id' => $validated['vendor_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(
            DB::table('tax_entries')->find($entryId),
            'Tax entry created successfully',
            201
        );
    }

    /**
     * Display the specified tax entry.
     */
    public function show(Request $request, int $id)
    {
        $entry = DB::table('tax_entries')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$entry) {
            return $this->error('Tax entry not found', 404);
        }

        return $this->success($entry);
    }

    /**
     * Mark tax as paid.
     */
    public function markPaid(Request $request, int $id)
    {
        $entry = DB::table('tax_entries')
            ->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)
            ->first();

        if (!$entry) {
            return $this->error('Tax entry not found', 404);
        }

        $validated = $request->validate([
            'payment_date' => 'required|date',
            'payment_reference' => 'nullable|string|max:100',
        ]);

        DB::table('tax_entries')
            ->where('id', $id)
            ->update([
                'status' => 'paid',
                'payment_date' => $validated['payment_date'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'updated_at' => now(),
            ]);

        return $this->success(null, 'Tax entry marked as paid');
    }

    /**
     * Get tax types.
     */
    public function types(Request $request)
    {
        $types = DB::table('tax_types')
            ->where('organization_id', $request->user()->organization_id)
            ->orWhereNull('organization_id') // Global types
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->success($types);
    }

    /**
     * Create tax type.
     */
    public function createType(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
            'name' => 'required|string|max:100',
            'rate' => 'required|numeric|min:0|max:100',
            'description' => 'nullable|string',
            'is_withholding' => 'boolean',
            'account_id' => 'nullable|exists:chart_of_accounts,id',
        ]);

        $typeId = DB::table('tax_types')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'rate' => $validated['rate'],
            'description' => $validated['description'] ?? null,
            'is_withholding' => $validated['is_withholding'] ?? false,
            'account_id' => $validated['account_id'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->success(
            DB::table('tax_types')->find($typeId),
            'Tax type created successfully',
            201
        );
    }

    /**
     * Get tax report by period.
     */
    public function report(Request $request)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'tax_type_id' => 'nullable|exists:tax_types,id',
        ]);

        $query = DB::table('tax_entries')
            ->where('tax_entries.organization_id', $request->user()->organization_id)
            ->whereBetween('tax_entries.entry_date', [$validated['start_date'], $validated['end_date']])
            ->leftJoin('tax_types', 'tax_entries.tax_type_id', '=', 'tax_types.id')
            ->select('tax_entries.*', 'tax_types.name as tax_type_name');

        if (isset($validated['tax_type_id'])) {
            $query->where('tax_entries.tax_type_id', $validated['tax_type_id']);
        }

        $entries = $query->orderBy('tax_entries.entry_date')->get();

        $totalTaxable = $entries->sum('taxable_amount');
        $totalTax = $entries->sum('tax_amount');
        $totalPaid = $entries->where('status', 'paid')->sum('tax_amount');
        $totalPending = $entries->where('status', 'pending')->sum('tax_amount');

        $byType = $entries->groupBy('tax_type_name')->map(function ($group) {
            return [
                'count' => $group->count(),
                'taxable_amount' => $group->sum('taxable_amount'),
                'tax_amount' => $group->sum('tax_amount'),
            ];
        });

        return $this->success([
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
            ],
            'entries' => $entries,
            'summary' => [
                'total_entries' => $entries->count(),
                'total_taxable_amount' => $totalTaxable,
                'total_tax_amount' => $totalTax,
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
            ],
            'by_type' => $byType,
        ]);
    }

    /**
     * Get tax summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $entries = DB::table('tax_entries')
            ->where('organization_id', $orgId)
            ->get();

        $totalTax = $entries->sum('tax_amount');
        $totalPaid = $entries->where('status', 'paid')->sum('tax_amount');
        $totalPending = $entries->where('status', 'pending')->sum('tax_amount');

        // Current quarter
        $currentQuarter = ceil(date('n') / 3);
        $quarterStart = date('Y') . '-' . str_pad(($currentQuarter - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01';
        $quarterEnd = date('Y-m-t', strtotime('+' . ($currentQuarter * 3 - 1) . ' months', strtotime(date('Y-01-01'))));

        $quarterEntries = $entries->filter(function ($e) use ($quarterStart, $quarterEnd) {
            return $e->entry_date >= $quarterStart && $e->entry_date <= $quarterEnd;
        });

        return $this->success([
            'total_entries' => $entries->count(),
            'total_tax' => $totalTax,
            'total_paid' => $totalPaid,
            'total_pending' => $totalPending,
            'current_quarter' => [
                'quarter' => "Q{$currentQuarter}",
                'entries' => $quarterEntries->count(),
                'amount' => $quarterEntries->sum('tax_amount'),
            ],
        ]);
    }

    /**
     * Generate entry number.
     */
    private function generateEntryNumber(int $organizationId): string
    {
        $lastEntry = DB::table('tax_entries')
            ->where('organization_id', $organizationId)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastEntry && preg_match('/TAX-(\d{4})-(\d+)/', $lastEntry->entry_number, $matches)) {
            $year = date('Y');
            $sequence = $matches[1] === $year ? (int)$matches[2] + 1 : 1;
        } else {
            $sequence = 1;
        }

        return sprintf('TAX-%s-%05d', date('Y'), $sequence);
    }
}
