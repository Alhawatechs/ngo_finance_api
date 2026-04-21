<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Fund;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FundController extends Controller
{
    /**
     * Display a listing of funds.
     */
    public function index(Request $request)
    {
        $query = Fund::where('organization_id', $request->user()->organization_id)
            ->with(['donor:id,code,name']);

        if ($request->has('fund_type')) {
            $query->where('fund_type', $request->fund_type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 500);
        $funds = $query->orderBy('code')->paginate($perPage);

        return $this->paginated($funds);
    }

    /**
     * Store a newly created fund.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fund_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('funds', 'code')->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                }),
            ],
            'fund_name' => 'required|string|max:255',
            'fund_type' => 'required|in:unrestricted,restricted,temporarily_restricted',
            'donor_id' => 'nullable|exists:donors,id',
            'description' => 'nullable|string',
            'restriction_start_date' => 'nullable|date',
            'restriction_end_date' => 'nullable|date|after_or_equal:restriction_start_date',
            'restriction_purpose' => 'nullable|string',
            'initial_amount' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $fund = Fund::create([
            'organization_id' => $request->user()->organization_id,
            'code' => $validated['fund_code'],
            'name' => $validated['fund_name'],
            'fund_type' => $validated['fund_type'],
            'donor_id' => $validated['donor_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'restriction_start_date' => $validated['restriction_start_date'] ?? null,
            'restriction_end_date' => $validated['restriction_end_date'] ?? null,
            'restriction_purpose' => $validated['restriction_purpose'] ?? null,
            'initial_amount' => $validated['initial_amount'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return $this->success($fund, 'Fund created successfully', 201);
    }

    /**
     * Display the specified fund.
     */
    public function show(Request $request, Fund $fund)
    {
        if ($fund->organization_id !== $request->user()->organization_id) {
            return $this->error('Fund not found', 404);
        }

        $fund->load(['donor']);

        // Get transactions (journal entry lines tagged with this fund)
        $transactions = \App\Models\JournalEntryLine::where('fund_id', $fund->id)
            ->with(['journalEntry:id,entry_number,entry_date,description', 'account:id,account_code,account_name'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        return $this->success([
            'fund' => $fund,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Update the specified fund.
     */
    public function update(Request $request, Fund $fund)
    {
        if ($fund->organization_id !== $request->user()->organization_id) {
            return $this->error('Fund not found', 404);
        }

        $validated = $request->validate([
            'fund_name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'restriction_end_date' => 'nullable|date',
            'restriction_purpose' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $updates = [];
        if (array_key_exists('fund_name', $validated)) {
            $updates['name'] = $validated['fund_name'];
        }
        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }
        if (array_key_exists('restriction_end_date', $validated)) {
            $updates['restriction_end_date'] = $validated['restriction_end_date'];
        }
        if (array_key_exists('restriction_purpose', $validated)) {
            $updates['restriction_purpose'] = $validated['restriction_purpose'];
        }
        if (array_key_exists('is_active', $validated)) {
            $updates['is_active'] = $validated['is_active'];
        }

        $fund->update($updates);

        return $this->success($fund, 'Fund updated successfully');
    }

    /**
     * Remove the specified fund (soft delete). Only funds with zero balance and no transactions can be deleted.
     */
    public function destroy(Request $request, Fund $fund)
    {
        if ($fund->organization_id !== $request->user()->organization_id) {
            return $this->error('Fund not found', 404);
        }

        $balance = (float) $fund->getBalance();
        if (abs($balance) > 0.0001) {
            return $this->error('Cannot delete fund with non-zero balance.', 422);
        }

        if ($fund->journalEntryLines()->exists()) {
            return $this->error('Cannot delete fund with existing transactions.', 422);
        }

        $fund->delete();

        return $this->success(null, 'Fund deleted successfully');
    }

    /**
     * Get fund balance summary.
     */
    public function balances(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $funds = Fund::where('organization_id', $orgId)
            ->where('is_active', true)
            ->get();

        $byType = $funds->groupBy('fund_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'initial_amount' => $group->sum(fn ($f) => (float) $f->initial_amount),
                'estimated_balance' => $group->sum(fn ($f) => $f->getBalance()),
            ];
        });

        return $this->success([
            'total_funds' => $funds->count(),
            'total_initial_amount' => $funds->sum(fn ($f) => (float) $f->initial_amount),
            'total_estimated_balance' => $funds->sum(fn ($f) => $f->getBalance()),
            'by_type' => $byType,
        ]);
    }

    /**
     * Get fund statement.
     */
    public function statement(Request $request, Fund $fund)
    {
        if ($fund->organization_id !== $request->user()->organization_id) {
            return $this->error('Fund not found', 404);
        }

        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $lines = \App\Models\JournalEntryLine::where('fund_id', $fund->id)
            ->whereHas('journalEntry', function ($q) use ($request) {
                $q->where('status', 'posted')
                  ->whereBetween('entry_date', [$request->start_date, $request->end_date]);
            })
            ->with(['journalEntry:id,entry_number,entry_date,description', 'account:id,account_code,account_name'])
            ->orderBy('created_at')
            ->get();

        $totalDebits = $lines->sum('debit_amount');
        $totalCredits = $lines->sum('credit_amount');

        // Opening balance would need to be calculated from transactions before start_date
        $openingBalance = \App\Models\JournalEntryLine::where('fund_id', $fund->id)
            ->whereHas('journalEntry', function ($q) use ($request) {
                $q->where('status', 'posted')
                  ->where('entry_date', '<', $request->start_date);
            })
            ->selectRaw('SUM(credit_amount) - SUM(debit_amount) as balance')
            ->value('balance') ?? 0;

        return $this->success([
            'fund' => $fund,
            'period' => [
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
            ],
            'opening_balance' => $openingBalance,
            'transactions' => $lines,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'closing_balance' => $openingBalance + $totalCredits - $totalDebits,
        ]);
    }

    /**
     * Get fund summary.
     */
    public function summary(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $funds = Fund::where('organization_id', $orgId)->get();

        $restricted = $funds->where('fund_type', 'restricted');
        $unrestricted = $funds->where('fund_type', 'unrestricted');
        $tempRestricted = $funds->where('fund_type', 'temporarily_restricted');

        return $this->success([
            'total_funds' => $funds->count(),
            'active_funds' => $funds->where('is_active', true)->count(),
            'restricted' => [
                'count' => $restricted->count(),
                'balance' => $restricted->sum(fn ($f) => $f->getBalance()),
            ],
            'unrestricted' => [
                'count' => $unrestricted->count(),
                'balance' => $unrestricted->sum(fn ($f) => $f->getBalance()),
            ],
            'temporarily_restricted' => [
                'count' => $tempRestricted->count(),
                'balance' => $tempRestricted->sum(fn ($f) => $f->getBalance()),
            ],
            'total_balance' => $funds->sum(fn ($f) => $f->getBalance()),
        ]);
    }
}
