<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FiscalYearController extends Controller
{
    /**
     * List fiscal years for the organization.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $currentOnly = $request->boolean('current_only');
        $cacheKey = "fiscal_years_{$orgId}_" . ($currentOnly ? 'current' : 'all');

        $years = Cache::remember($cacheKey, 300, function () use ($orgId, $currentOnly) {
            $query = FiscalYear::where('organization_id', $orgId)
                ->withCount('periods')
                ->orderBy('start_date', 'desc');
            if ($currentOnly) {
                $query->where('is_current', true);
            }
            return $query->get();
        });

        return $this->success($years);
    }

    /**
     * Store a new fiscal year (and optionally create periods).
     */
    public function store(Request $request)
    {
        $organizationId = $request->user()->organization_id;

        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'nullable|in:draft,open,closed,locked',
            'is_current' => 'boolean',
            'create_periods' => 'boolean', // if true, create 12 monthly periods
        ]);

        $validated['organization_id'] = $organizationId;
        $validated['status'] = $validated['status'] ?? 'draft';
        $validated['is_current'] = $validated['is_current'] ?? false;

        if ($this->fiscalYearRangeOverlaps($organizationId, $validated['start_date'], $validated['end_date'])) {
            return $this->error('These dates overlap another fiscal year for your organization. Adjust the range or edit the existing year.', 422);
        }

        if ($validated['is_current']) {
            FiscalYear::where('organization_id', $organizationId)->update(['is_current' => false]);
        }

        $year = FiscalYear::create([
            'organization_id' => $validated['organization_id'],
            'name' => $validated['name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'status' => $validated['status'],
            'is_current' => $validated['is_current'],
        ]);

        if (!empty($validated['create_periods'])) {
            $this->createMonthlyPeriods($year);
        }

        $year->load('periods');
        $this->clearFiscalYearCache($organizationId);

        return $this->success($year, 'Fiscal year created successfully', 201);
    }

    /**
     * Show a fiscal year with periods.
     */
    public function show(Request $request, FiscalYear $fiscal_year)
    {
        if ($fiscal_year->organization_id !== $request->user()->organization_id) {
            return $this->error('Fiscal year not found', 404);
        }

        $fiscal_year->load(['periods' => fn ($q) => $q->orderBy('period_number')]);

        return $this->success($fiscal_year);
    }

    /**
     * Update a fiscal year.
     */
    public function update(Request $request, FiscalYear $fiscal_year)
    {
        if ($fiscal_year->organization_id !== $request->user()->organization_id) {
            return $this->error('Fiscal year not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:50',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'status' => 'nullable|in:draft,open,closed,locked',
            'is_current' => 'boolean',
        ]);

        if (!empty($validated['is_current'])) {
            FiscalYear::where('organization_id', $fiscal_year->organization_id)
                ->where('id', '!=', $fiscal_year->id)
                ->update(['is_current' => false]);
        }

        if (isset($validated['start_date']) || isset($validated['end_date'])) {
            $start = isset($validated['start_date'])
                ? $validated['start_date']
                : $fiscal_year->start_date->format('Y-m-d');
            $end = isset($validated['end_date'])
                ? $validated['end_date']
                : $fiscal_year->end_date->format('Y-m-d');
            if ($this->fiscalYearRangeOverlaps($fiscal_year->organization_id, $start, $end, $fiscal_year->id)) {
                return $this->error('These dates overlap another fiscal year for your organization.', 422);
            }
        }

        $fiscal_year->update($validated);
        $this->clearFiscalYearCache($fiscal_year->organization_id);

        return $this->success($fiscal_year, 'Fiscal year updated successfully');
    }

    /**
     * Remove a fiscal year (only if draft and no posted entries).
     */
    public function destroy(Request $request, FiscalYear $fiscal_year)
    {
        if ($fiscal_year->organization_id !== $request->user()->organization_id) {
            return $this->error('Fiscal year not found', 404);
        }
        if ($fiscal_year->is_current) {
            return $this->error('Cannot delete the current fiscal year.', 422);
        }

        $periodIds = $fiscal_year->periods()->pluck('id');
        if ($periodIds->isNotEmpty() && JournalEntry::whereIn('fiscal_period_id', $periodIds)->exists()) {
            return $this->error(
                'Cannot delete this fiscal year: journal entries are posted to one or more of its periods. Reassign or reverse those entries first.',
                422
            );
        }

        if (Budget::where('fiscal_year_id', $fiscal_year->id)->exists()) {
            return $this->error(
                'Cannot delete this fiscal year: budgets are linked to it. Delete or reassign those budgets first.',
                422
            );
        }

        $orgId = $fiscal_year->organization_id;
        $fiscal_year->delete();
        $this->clearFiscalYearCache($orgId);
        return $this->success(null, 'Fiscal year deleted successfully');
    }

    /**
     * List periods for a fiscal year.
     */
    public function periods(Request $request, FiscalYear $fiscal_year)
    {
        if ($fiscal_year->organization_id !== $request->user()->organization_id) {
            return $this->error('Fiscal year not found', 404);
        }

        $periods = $fiscal_year->periods()->orderBy('period_number')->get();

        return $this->success($periods);
    }

    /**
     * Close a fiscal period.
     */
    public function closePeriod(Request $request, FiscalPeriod $fiscal_period)
    {
        $period = $fiscal_period->load('fiscalYear');
        if ($period->fiscalYear->organization_id !== $request->user()->organization_id) {
            return $this->error('Period not found', 404);
        }

        if ($period->isClosed()) {
            return $this->error('Period is already closed', 422);
        }

        $period->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
        ]);
        $this->clearFiscalYearCache($period->fiscalYear->organization_id);

        return $this->success($period->fresh(), 'Period closed successfully');
    }

    /**
     * Reopen a fiscal period (only if not locked).
     */
    public function reopenPeriod(Request $request, FiscalPeriod $fiscal_period)
    {
        $period = $fiscal_period->load('fiscalYear');
        if ($period->fiscalYear->organization_id !== $request->user()->organization_id) {
            return $this->error('Period not found', 404);
        }

        if ($period->status === 'locked') {
            return $this->error('Locked period cannot be reopened', 422);
        }

        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ]);
        $this->clearFiscalYearCache($period->fiscalYear->organization_id);

        return $this->success($period->fresh(), 'Period reopened successfully');
    }

    /**
     * Permanently lock a fiscal period (only from closed). Locked periods cannot be reopened.
     */
    public function lockPeriod(Request $request, FiscalPeriod $fiscal_period)
    {
        $period = $fiscal_period->load('fiscalYear');
        if ($period->fiscalYear->organization_id !== $request->user()->organization_id) {
            return $this->error('Period not found', 404);
        }

        if ($period->status === 'locked') {
            return $this->error('Period is already locked', 422);
        }

        if ($period->status !== 'closed') {
            return $this->error('Only a closed period can be locked. Close the period first.', 422);
        }

        $period->update([
            'status' => 'locked',
            'closed_at' => $period->closed_at ?? now(),
            'closed_by' => $period->closed_by ?? $request->user()->id,
        ]);
        $this->clearFiscalYearCache($period->fiscalYear->organization_id);

        return $this->success($period->fresh(), 'Period locked successfully');
    }

    /**
     * Two ranges [aStart,aEnd] and [bStart,bEnd] overlap iff aStart <= bEnd && bStart <= aEnd.
     */
    private function fiscalYearRangeOverlaps(int $organizationId, string $startDate, string $endDate, ?int $exceptFiscalYearId = null): bool
    {
        $q = FiscalYear::where('organization_id', $organizationId)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);
        if ($exceptFiscalYearId !== null) {
            $q->where('id', '!=', $exceptFiscalYearId);
        }

        return $q->exists();
    }

    private function createMonthlyPeriods(FiscalYear $year): void
    {
        $start = Carbon::parse($year->start_date);
        $end = Carbon::parse($year->end_date);
        $periodNumber = 1;
        $current = $start->copy();

        while ($current->lte($end)) {
            $periodEnd = $current->copy()->endOfMonth();
            if ($periodEnd->gt($end)) {
                $periodEnd = $end->copy();
            }
            FiscalPeriod::create([
                'fiscal_year_id' => $year->id,
                'name' => $current->format('F Y'),
                'period_number' => $periodNumber,
                'start_date' => $current->format('Y-m-d'),
                'end_date' => $periodEnd->format('Y-m-d'),
                'status' => 'open',
                'is_adjustment_period' => false,
            ]);
            $current = $periodEnd->copy()->addDay();
            $periodNumber++;
        }
    }

    private function clearFiscalYearCache(int $orgId): void
    {
        Cache::forget("fiscal_years_{$orgId}_all");
        Cache::forget("fiscal_years_{$orgId}_current");
    }
}
