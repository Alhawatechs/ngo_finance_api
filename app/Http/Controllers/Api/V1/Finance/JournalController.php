<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\CodingBlockVoucherNumberService;
use App\Services\OfficeScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JournalController extends Controller
{
    /**
     * Display a listing of journals (optionally filtered by project).
     * Appends office (location), total_debit, total_credit, balance (from posted entries).
     */
    public function index(Request $request, OfficeScopeService $officeScope)
    {
        $this->authorize('viewAny', Journal::class);

        $query = Journal::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with(['project:id,project_code,project_name', 'office:id,name,code,is_head_office']);

        if ($request->boolean('only_trashed')) {
            if (! $request->user()->can('delete-journal-books')) {
                return $this->error('You do not have permission to view deleted journal books.', 403);
            }
            $query->onlyTrashed();
        }

        $officeScope->applyJournalBookScope($query, $request->user());

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->project_id);
        }

        if ($request->filled('office_id')) {
            $query->where('office_id', (int) $request->office_id);
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

        $query->orderBy('name');
        $perPage = min((int) $request->input('per_page', 100), 500);
        $journals = $query->paginate($perPage);

        $ids = $journals->pluck('id')->filter()->values()->all();
        $totals = [];
        if (! empty($ids)) {
            $rows = JournalEntry::whereIn('journal_id', $ids)
                ->where('status', 'posted')
                ->selectRaw('journal_id, COALESCE(SUM(total_debit), 0) as total_debit, COALESCE(SUM(total_credit), 0) as total_credit')
                ->groupBy('journal_id')
                ->get();
            foreach ($rows as $row) {
                $totals[$row->journal_id] = [
                    'total_debit' => (float) $row->total_debit,
                    'total_credit' => (float) $row->total_credit,
                    'balance' => (float) $row->total_debit - (float) $row->total_credit,
                ];
            }
        }

        foreach ($journals as $journal) {
            $journal->total_debit = $totals[$journal->id]['total_debit'] ?? 0;
            $journal->total_credit = $totals[$journal->id]['total_credit'] ?? 0;
            $journal->balance = $totals[$journal->id]['balance'] ?? 0;
        }

        return $this->paginated($journals);
    }

    /**
     * Store a newly created journal (e.g. a new journal book for a project).
     */
    public function store(Request $request, OfficeScopeService $officeScope)
    {
        $this->authorize('create', Journal::class);

        $orgId = $request->user()->organization_id;
        $provinceCodes = array_column(CodingBlockVoucherNumberService::getProvinces(), 'code');

        $currencyCodes = Organization::getActiveCurrencyCodesForOrg($orgId);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-_]+$/',
                Rule::unique('journals')->where('organization_id', $orgId),
            ],
            'project_id' => 'nullable|exists:projects,id',
            'office_id' => 'nullable|exists:offices,id',
            'province_code' => ['nullable', 'string', 'size:2', Rule::in($provinceCodes)],
            'location_code' => ['nullable', 'string', Rule::in(['1', '2', '3'])],
            'fund_id' => 'nullable|integer|min:1',
            'currency' => [
                Rule::requiredIf(fn () => $request->filled('project_id')),
                'nullable',
                'string',
                'size:3',
                Rule::in($currencyCodes),
            ],
            'exchange_rate' => 'nullable|numeric|min:0',
            'voucher_type' => 'nullable|in:payment,receipt,journal,contra',
            'payment_method' => 'nullable|in:cash,check,bank_transfer,mobile_money,msp',
            'default_payee_name' => 'nullable|string|max:255',
            'voucher_description_template' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
        ]);

        if ($deny = $this->enforceOfficeAssignmentForJournal($request->user(), $validated, $officeScope)) {
            return $deny;
        }

        $validated['organization_id'] = $orgId;
        $validated['is_active'] = $validated['is_active'] ?? true;

        if (array_key_exists('currency', $validated) && $validated['currency'] !== null && $validated['currency'] !== '') {
            $validated['currency'] = strtoupper(trim((string) $validated['currency']));
        }

        $journal = Journal::create($validated);
        $journal->load(['project:id,project_code,project_name', 'office:id,name,code,is_head_office']);

        return $this->success($journal, 'Journal created successfully', 201);
    }

    /**
     * Display the specified journal.
     */
    public function show(Journal $journal)
    {
        $this->authorize('view', $journal);

        $journal->load(['project:id,project_code,project_name', 'office:id,name,code,is_head_office']);

        return $this->success($journal);
    }

    /**
     * Update the specified journal.
     */
    public function update(Request $request, Journal $journal, OfficeScopeService $officeScope)
    {
        $this->authorize('update', $journal);

        $orgId = $request->user()->organization_id;
        $provinceCodes = array_column(CodingBlockVoucherNumberService::getProvinces(), 'code');

        $currencyCodes = Organization::getActiveCurrencyCodesForOrg($orgId);
        if ($journal->currency && trim((string) $journal->currency) !== '') {
            $currencyCodes = array_values(array_unique(array_merge(
                $currencyCodes,
                [strtoupper(trim((string) $journal->currency))]
            )));
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9\-_]+$/',
                Rule::unique('journals')->where('organization_id', $orgId)->ignore($journal->id),
            ],
            'project_id' => 'nullable|exists:projects,id',
            'office_id' => 'nullable|exists:offices,id',
            'province_code' => ['nullable', 'string', 'size:2', Rule::in($provinceCodes)],
            'location_code' => ['nullable', 'string', Rule::in(['1', '2', '3'])],
            'fund_id' => 'nullable|integer|min:1',
            'currency' => ['nullable', 'string', 'size:3', Rule::in($currencyCodes)],
            'exchange_rate' => 'nullable|numeric|min:0',
            'voucher_type' => 'nullable|in:payment,receipt,journal,contra',
            'payment_method' => 'nullable|in:cash,check,bank_transfer,mobile_money,msp',
            'default_payee_name' => 'nullable|string|max:255',
            'voucher_description_template' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
        ]);

        if ($deny = $this->enforceOfficeAssignmentForJournal($request->user(), $validated, $officeScope, $journal)) {
            return $deny;
        }

        if (array_key_exists('currency', $validated) && $validated['currency'] !== null && $validated['currency'] !== '') {
            $validated['currency'] = strtoupper(trim((string) $validated['currency']));
        }

        $resolvedProjectId = array_key_exists('project_id', $validated)
            ? $validated['project_id']
            : $journal->project_id;
        $effectiveCurrency = array_key_exists('currency', $validated)
            ? ($validated['currency'] !== null && $validated['currency'] !== '' ? $validated['currency'] : null)
            : $journal->currency;

        if (! empty($resolvedProjectId) && (empty($effectiveCurrency) || trim((string) $effectiveCurrency) === '')) {
            return $this->error(
                'Set a currency for project-linked journal books. It drives period close totals and voucher defaults. Configure active currencies under General Ledger → Currency.',
                422
            );
        }

        $journal->update($validated);
        $journal->load(['project:id,project_code,project_name', 'office:id,name,code,is_head_office']);

        return $this->success($journal, 'Journal updated successfully');
    }

    /**
     * Soft-delete a journal book. Entries keep journal_id until permanent delete.
     */
    public function destroy(Journal $journal)
    {
        $this->authorize('delete', $journal);

        $journal->delete();

        return $this->success(null, 'Journal deleted successfully');
    }

    /**
     * Restore a soft-deleted journal book.
     */
    public function restore(Request $request, int $id)
    {
        $journal = Journal::onlyTrashed()
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $id)
            ->first();

        if (! $journal) {
            return $this->error('Journal not found', 404);
        }

        $this->authorize('restore', $journal);

        $journal->restore();
        $journal->load(['project:id,project_code,project_name', 'office:id,name,code,is_head_office']);

        return $this->success($journal, 'Journal restored successfully');
    }

    /**
     * Permanently delete a soft-deleted journal book (unlinks entries).
     */
    public function forceDelete(Request $request, int $id)
    {
        $journal = Journal::onlyTrashed()
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $id)
            ->first();

        if (! $journal) {
            return $this->error('Journal not found', 404);
        }

        $this->authorize('forceDelete', $journal);

        $journal->journalEntries()->update(['journal_id' => null]);
        $journal->forceDelete();

        return $this->success(null, 'Journal permanently deleted');
    }

    /**
     * Return province list (code + name) for journal location / voucher number (e.g. Add Journal Book).
     */
    public function provinces(Request $request)
    {
        $u = $request->user();
        if (! $u->can('view-journal-books') && ! $u->can('create-journal-books') && ! $u->can('edit-journal-books')) {
            return $this->error('You do not have permission to view journal book options.', 403);
        }

        return $this->success([
            'provinces' => CodingBlockVoucherNumberService::getProvinces(),
            'locations' => CodingBlockVoucherNumberService::getLocations(),
        ]);
    }

    /**
     * Provincial users must set office_id to their own office; cannot assign another office.
     *
     * @param  array<string, mixed>  $validated
     */
    private function enforceOfficeAssignmentForJournal(User $user, array &$validated, OfficeScopeService $officeScope, ?Journal $existing = null): ?JsonResponse
    {
        if ($officeScope->canViewAllJournalBooks($user)) {
            return null;
        }
        if (! $user->office_id) {
            return null;
        }

        $own = (int) $user->office_id;

        if (array_key_exists('office_id', $validated) && $validated['office_id'] !== null && (int) $validated['office_id'] !== $own) {
            return $this->error('You can only assign journal books to your own office.', 422);
        }

        if (! array_key_exists('office_id', $validated) && $existing === null) {
            $validated['office_id'] = $own;
        }

        return null;
    }
}

