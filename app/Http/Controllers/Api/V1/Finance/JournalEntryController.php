<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\FiscalPeriod;
use App\Models\ChartOfAccount;
use App\Models\Organization;
use App\Models\Project;
use App\Services\CodingBlockVoucherNumberService;
use App\Services\Finance\ProjectFiscalPeriodPostingService;
use App\Services\OfficeScopeService;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JournalEntryController extends Controller
{
    /**
     * Display a listing of journal entries.
     */
    public function index(Request $request, OfficeScopeService $officeScope)
    {
        $query = JournalEntry::where('organization_id', $request->user()->organization_id)
            ->with([
                'office:id,name,code',
                'fiscalPeriod:id,name',
                'creator:id,name',
                'poster:id,name',
                'journal:id,name,code,project_id',
                'journal.project:id,project_code,project_name',
            ])
            ->withCount('lines');

        $this->applyJournalEntryOfficeScope($query, $request->user(), $officeScope);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by entry type
        if ($request->has('entry_type')) {
            $query->where('entry_type', $request->entry_type);
        }

        // Filter by office
        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        // Filter by fiscal period
        if ($request->has('fiscal_period_id')) {
            $query->where('fiscal_period_id', $request->fiscal_period_id);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('entry_date', [$request->start_date, $request->end_date]);
        }

        // Filter by journal (journal book)
        if ($request->filled('journal_id')) {
            $query->where('journal_id', (int) $request->journal_id);
        }

        // Filter by project (entries that have at least one line for this project)
        if ($request->filled('project_id')) {
            $query->whereHas('lines', function ($q) use ($request) {
                $q->where('project_id', (int) $request->project_id);
            });
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', "%{$search}%")
                  ->orWhere('voucher_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        // Sort (whitelist — invalid column/order caused SQL errors / 500)
        $allowedSort = ['entry_date', 'entry_number', 'voucher_number', 'created_at', 'updated_at', 'status', 'total_debit', 'total_credit', 'id'];
        $sortField = $request->input('sort', 'entry_date');
        if (! is_string($sortField) || ! in_array($sortField, $allowedSort, true)) {
            $sortField = 'entry_date';
        }
        $sortOrder = strtolower((string) $request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortOrder);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $entries = $query->paginate($perPage);

        return $this->paginated($entries);
    }

    /**
     * Posted GL lines for a project: account summary by class (account_type) + paginated lines with journal entry context.
     */
    public function projectLedger(Request $request, OfficeScopeService $officeScope)
    {
        $validated = $request->validate([
            'project_id' => 'required|integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'journal_id' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $orgId = (int) $request->user()->organization_id;
        $project = Project::where('organization_id', $orgId)->find($validated['project_id']);
        if (! $project) {
            return $this->error('Project not found', 404);
        }

        if (isset($validated['journal_id'])) {
            $journalBook = Journal::where('organization_id', $orgId)->find((int) $validated['journal_id']);
            if (! $journalBook) {
                return $this->error('Journal book not found', 404);
            }
        }

        $filters = [
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'journal_id' => $validated['journal_id'] ?? null,
        ];

        $perPage = min((int) ($validated['per_page'] ?? 50), 100);

        $accountSummary = JournalEntryLine::query()
            ->where('journal_entry_lines.project_id', $project->id)
            ->whereHas('journalEntry', function (Builder $q) use ($request, $officeScope, $filters) {
                $this->applyProjectLedgerJournalScope($q, $request, $officeScope, $filters);
            })
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('chart_of_accounts.organization_id', $orgId)
            ->selectRaw('chart_of_accounts.account_type')
            ->selectRaw('chart_of_accounts.id as account_id')
            ->selectRaw('chart_of_accounts.account_code')
            ->selectRaw('chart_of_accounts.account_name')
            ->selectRaw('SUM(journal_entry_lines.debit_amount) as total_debit')
            ->selectRaw('SUM(journal_entry_lines.credit_amount) as total_credit')
            ->groupBy(
                'chart_of_accounts.account_type',
                'chart_of_accounts.id',
                'chart_of_accounts.account_code',
                'chart_of_accounts.account_name'
            )
            ->orderBy('chart_of_accounts.account_type')
            ->orderBy('chart_of_accounts.account_code')
            ->get();

        $lines = JournalEntryLine::query()
            ->where('journal_entry_lines.project_id', $project->id)
            ->whereHas('journalEntry', function (Builder $q) use ($request, $officeScope, $filters) {
                $this->applyProjectLedgerJournalScope($q, $request, $officeScope, $filters);
            })
            ->with([
                'account:id,account_code,account_name,account_type',
                'journalEntry:id,entry_number,entry_date,voucher_number,reference,description,source_type,source_id,journal_id,currency',
                'journalEntry.journal:id,name,code',
            ])
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderByDesc('je.entry_date')
            ->orderByDesc('je.id')
            ->orderBy('journal_entry_lines.line_number')
            ->select('journal_entry_lines.*')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Success',
            'data' => [
                'project' => [
                    'id' => $project->id,
                    'project_code' => $project->project_code,
                    'project_name' => $project->project_name,
                ],
                'account_summary' => $accountSummary,
                'lines' => $lines->items(),
            ],
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
                'from' => $lines->firstItem(),
                'to' => $lines->lastItem(),
            ],
        ]);
    }

    /**
     * @param  array{start_date?: ?string, end_date?: ?string, journal_id?: ?int}  $filters
     */
    private function applyProjectLedgerJournalScope(Builder $q, Request $request, OfficeScopeService $officeScope, array $filters): void
    {
        $q->where('organization_id', $request->user()->organization_id)
            ->where('status', 'posted');
        if (! empty($filters['start_date'])) {
            $q->whereDate('entry_date', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $q->whereDate('entry_date', '<=', $filters['end_date']);
        }
        if (! empty($filters['journal_id'])) {
            $q->where('journal_id', (int) $filters['journal_id']);
        }
        $this->applyJournalEntryOfficeScope($q, $request->user(), $officeScope);
    }

    /**
     * Export journal entries as CSV (journal book format).
     */
    public function export(Request $request, OfficeScopeService $officeScope)
    {
        $query = JournalEntry::where('organization_id', $request->user()->organization_id)
            ->with([
                'lines.account',
                'lines.project',
                'creator:id,name',
                'poster:id,name',
            ]);

        $this->applyJournalEntryOfficeScope($query, $request->user(), $officeScope);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        if ($request->has('entry_type')) {
            $query->where('entry_type', $request->entry_type);
        }
        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }
        if ($request->has('fiscal_period_id')) {
            $query->where('fiscal_period_id', $request->fiscal_period_id);
        }
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('entry_date', [$request->start_date, $request->end_date]);
        }
        if ($request->filled('journal_id')) {
            $query->where('journal_id', (int) $request->journal_id);
        }
        if ($request->filled('project_id')) {
            $query->whereHas('lines', function ($q) use ($request) {
                $q->where('project_id', (int) $request->project_id);
            });
        }
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('entry_number', 'like', "%{$search}%")
                  ->orWhere('voucher_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $query->orderBy('entry_date')->orderBy('id');
        $entries = $query->limit(20000)->get();

        $filename = 'journal-book-' . now()->format('Y-m-d-His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($entries) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'Trans #',
                'Last modified by',
                'Date',
                'Num',
                'Name',
                'Description',
                'Account',
                'Class',
                'Debit',
                'Credit',
                'Account Type',
                'Currency',
            ]);

            foreach ($entries as $entry) {
                $lastModifiedBy = $entry->poster?->name ?? $entry->creator?->name ?? '';
                $entryDate = \Carbon\Carbon::parse($entry->entry_date)->format('m/d/Y');

                foreach ($entry->lines ?? [] as $line) {
                    $account = $line->account;
                    $accountLabel = $account
                        ? ($account->account_code . ' ' . ($account->account_name ?? ''))
                        : '';
                    $accountType = $account ? ($account->account_type ?? '') : '';
                    $project = $line->project;
                    $class = $project
                        ? ($project->project_code ?? '') . ' ' . ($project->project_name ?? '')
                        : '';
                    $name = $project ? ($project->project_name ?? $project->project_code ?? '') : '';

                    fputcsv($stream, [
                        $entry->id,
                        $lastModifiedBy,
                        $entryDate,
                        $entry->entry_number,
                        $name,
                        $line->description ?? $entry->description,
                        $accountLabel,
                        trim($class),
                        $line->debit_amount > 0 ? number_format($line->debit_amount, 2) : '',
                        $line->credit_amount > 0 ? number_format($line->credit_amount, 2) : '',
                        $accountType,
                        $line->currency ?? $entry->currency,
                    ]);
                }
            }

            fclose($stream);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Store a newly created journal entry.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'journal_id' => 'nullable|exists:journals,id',
            'office_id' => 'required|exists:offices,id',
            'entry_date' => 'required|date',
            'entry_type' => 'required|in:standard,adjusting,closing,reversing,recurring',
            'reference' => 'nullable|string|max:255',
            'description' => 'required|string',
            'currency' => ['required', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'exchange_rate' => 'required|numeric|min:0.000001',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.fund_id' => 'nullable|exists:funds,id',
            'lines.*.project_id' => 'nullable|exists:projects,id',
            'lines.*.donor_expenditure_code_id' => 'nullable|exists:donor_expenditure_codes,id',
            'lines.*.description' => 'nullable|string',
            'lines.*.debit_amount' => 'required_without:lines.*.credit_amount|numeric|min:0',
            'lines.*.credit_amount' => 'required_without:lines.*.debit_amount|numeric|min:0',
            'lines.*.cost_center' => 'nullable|string|max:255',
        ]);

        // Get open fiscal period for entry date
        $fiscalPeriod = FiscalPeriod::whereHas('fiscalYear', fn($q) => 
            $q->where('organization_id', $request->user()->organization_id)
        )
        ->where('status', 'open')
        ->where('start_date', '<=', $validated['entry_date'])
        ->where('end_date', '>=', $validated['entry_date'])
        ->first();

        if (!$fiscalPeriod) {
            return $this->error('No open fiscal period found for the entry date', 400);
        }

        try {
            app(ProjectFiscalPeriodPostingService::class)->assertProjectsOpenForPosting(
                $fiscalPeriod,
                array_column($validated['lines'], 'project_id')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        // Calculate totals and validate balance
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($validated['lines'] as $line) {
            $debit = $line['debit_amount'] ?? 0;
            $credit = $line['credit_amount'] ?? 0;
            $totalDebit += $debit * $validated['exchange_rate'];
            $totalCredit += $credit * $validated['exchange_rate'];
        }

        // Validate double-entry balance
        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            return $this->error('Journal entry must be balanced. Debits must equal credits.', 400);
        }

        if ($totalDebit == 0) {
            return $this->error('Journal entry must have at least one debit and credit entry.', 400);
        }

        // Validate accounts are posting accounts
        $accountIds = array_column($validated['lines'], 'account_id');
        $invalidAccounts = ChartOfAccount::whereIn('id', $accountIds)
            ->where(function($q) {
                $q->where('is_posting', false)
                  ->orWhere('is_active', false);
            })
            ->get();

        if ($invalidAccounts->count() > 0) {
            return $this->error('All accounts must be active posting accounts', 400);
        }

        if (! empty($validated['journal_id'])) {
            $journal = Journal::where('id', $validated['journal_id'])
                ->where('organization_id', $request->user()->organization_id)
                ->first();
            if (! $journal || ! app(OfficeScopeService::class)->userCanAccessJournalBook($request->user(), $journal)) {
                return $this->error('Invalid journal', 400);
            }
        }

        DB::beginTransaction();
        try {
            // Generate entry number
            $entryNumber = $this->generateEntryNumber($request->user()->organization_id, $validated['entry_type']);

            $voucherNumber = null;
            if (! empty($validated['journal_id'])) {
                $journal = Journal::with(['project', 'office'])->find($validated['journal_id']);
                if ($journal && $journal->project_id && $journal->province_code && $journal->office_id && $journal->project && $journal->office) {
                    try {
                        $voucherNumber = app(CodingBlockVoucherNumberService::class)->getNextNumberForJournalEntry(
                            $request->user()->organization_id,
                            $journal,
                            $validated['entry_date'],
                            $request->user()->organization
                        );
                    } catch (\Throwable $e) {
                        // Leave voucher_number null if generation fails (e.g. missing data)
                    }
                }
            }

            // Create journal entry
            $entry = JournalEntry::create([
                'organization_id' => $request->user()->organization_id,
                'journal_id' => $validated['journal_id'] ?? null,
                'office_id' => $validated['office_id'],
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => $entryNumber,
                'voucher_number' => $voucherNumber,
                'entry_date' => $validated['entry_date'],
                'entry_type' => $validated['entry_type'],
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'],
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'],
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);

            // Create journal entry lines
            foreach ($validated['lines'] as $index => $line) {
                $debit = $line['debit_amount'] ?? 0;
                $credit = $line['credit_amount'] ?? 0;

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'fund_id' => $line['fund_id'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                    'donor_expenditure_code_id' => $line['donor_expenditure_code_id'] ?? null,
                    'office_id' => $validated['office_id'],
                    'line_number' => $index + 1,
                    'description' => $line['description'] ?? null,
                    'debit_amount' => $debit,
                    'credit_amount' => $credit,
                    'currency' => $validated['currency'],
                    'exchange_rate' => $validated['exchange_rate'],
                    'base_currency_debit' => $debit * $validated['exchange_rate'],
                    'base_currency_credit' => $credit * $validated['exchange_rate'],
                    'cost_center' => $line['cost_center'] ?? null,
                ]);
            }

            DB::commit();

            $entry->load(['lines.account', 'lines.donorExpenditureCode', 'office', 'fiscalPeriod', 'creator']);

            return $this->success($entry, 'Journal entry created successfully', 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create journal entry: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified journal entry.
     */
    public function show(Request $request, JournalEntry $journalEntry, OfficeScopeService $officeScope)
    {
        if ($deny = $this->denyIfJournalEntryNotAccessible($request, $journalEntry, $officeScope)) {
            return $deny;
        }

        $journalEntry->load([
            'lines.account:id,account_code,account_name,account_type',
            'lines.fund:id,code,name',
            'lines.project:id,project_code,project_name',
            'lines.donorExpenditureCode:id,code,name',
            'office:id,name,code',
            'fiscalPeriod:id,name',
            'creator:id,name',
            'poster:id,name',
            'reverser:id,name',
            'reversalEntry:id,entry_number',
            'reversedEntry:id,entry_number',
        ]);

        return $this->success($journalEntry);
    }

    /**
     * Update the specified journal entry.
     */
    public function update(Request $request, JournalEntry $journalEntry, OfficeScopeService $officeScope)
    {
        if ($deny = $this->denyIfJournalEntryNotAccessible($request, $journalEntry, $officeScope)) {
            return $deny;
        }

        if ($journalEntry->status !== 'draft') {
            return $this->error('Only draft journal entries can be updated', 400);
        }

        $validated = $request->validate([
            'entry_date' => 'sometimes|date',
            'entry_type' => 'sometimes|in:standard,adjusting,closing,reversing,recurring',
            'reference' => 'nullable|string|max:255',
            'description' => 'sometimes|string',
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
            'exchange_rate' => 'sometimes|numeric|min:0.000001',
            'lines' => 'sometimes|array|min:2',
            'lines.*.id' => 'nullable|exists:journal_entry_lines,id',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.fund_id' => 'nullable|exists:funds,id',
            'lines.*.project_id' => 'nullable|exists:projects,id',
            'lines.*.donor_expenditure_code_id' => 'nullable|exists:donor_expenditure_codes,id',
            'lines.*.description' => 'nullable|string',
            'lines.*.debit_amount' => 'required_without:lines.*.credit_amount|numeric|min:0',
            'lines.*.credit_amount' => 'required_without:lines.*.debit_amount|numeric|min:0',
            'lines.*.cost_center' => 'nullable|string|max:255',
        ]);

        $journalEntry->loadMissing('lines', 'fiscalPeriod');

        if (isset($validated['entry_date'])) {
            $resolvedFiscalPeriod = FiscalPeriod::whereHas('fiscalYear', fn($q) =>
                $q->where('organization_id', $request->user()->organization_id)
            )
                ->where('status', 'open')
                ->where('start_date', '<=', $validated['entry_date'])
                ->where('end_date', '>=', $validated['entry_date'])
                ->first();

            if (! $resolvedFiscalPeriod) {
                return $this->error('No open fiscal period found for the entry date', 400);
            }
        } else {
            $resolvedFiscalPeriod = $journalEntry->fiscalPeriod;
        }

        $projectIds = isset($validated['lines'])
            ? array_column($validated['lines'], 'project_id')
            : $journalEntry->lines->pluck('project_id')->all();

        try {
            app(ProjectFiscalPeriodPostingService::class)->assertProjectsOpenForPosting($resolvedFiscalPeriod, $projectIds);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        DB::beginTransaction();
        try {
            // Update main entry fields
            $updateData = collect($validated)->except('lines')->toArray();

            if (isset($validated['entry_date'])) {
                $updateData['fiscal_period_id'] = $resolvedFiscalPeriod->id;
            }

            $journalEntry->update($updateData);

            // Update lines if provided
            if (isset($validated['lines'])) {
                $currency = $validated['currency'] ?? $journalEntry->currency;
                $exchangeRate = $validated['exchange_rate'] ?? $journalEntry->exchange_rate;
                
                $totalDebit = 0;
                $totalCredit = 0;

                foreach ($validated['lines'] as $line) {
                    $debit = $line['debit_amount'] ?? 0;
                    $credit = $line['credit_amount'] ?? 0;
                    $totalDebit += $debit * $exchangeRate;
                    $totalCredit += $credit * $exchangeRate;
                }

                if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
                    return $this->error('Journal entry must be balanced', 400);
                }

                // Delete existing lines and recreate
                $journalEntry->lines()->delete();

                foreach ($validated['lines'] as $index => $line) {
                    $debit = $line['debit_amount'] ?? 0;
                    $credit = $line['credit_amount'] ?? 0;

                    JournalEntryLine::create([
                        'journal_entry_id' => $journalEntry->id,
                        'account_id' => $line['account_id'],
                        'fund_id' => $line['fund_id'] ?? null,
                        'project_id' => $line['project_id'] ?? null,
                        'donor_expenditure_code_id' => $line['donor_expenditure_code_id'] ?? null,
                        'office_id' => $journalEntry->office_id,
                        'line_number' => $index + 1,
                        'description' => $line['description'] ?? null,
                        'debit_amount' => $debit,
                        'credit_amount' => $credit,
                        'currency' => $currency,
                        'exchange_rate' => $exchangeRate,
                        'base_currency_debit' => $debit * $exchangeRate,
                        'base_currency_credit' => $credit * $exchangeRate,
                        'cost_center' => $line['cost_center'] ?? null,
                    ]);
                }

                $journalEntry->total_debit = $totalDebit;
                $journalEntry->total_credit = $totalCredit;
                $journalEntry->save();
            }

            DB::commit();

            $journalEntry->load(['lines.account', 'lines.donorExpenditureCode', 'office', 'fiscalPeriod', 'creator']);

            return $this->success($journalEntry, 'Journal entry updated successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to update journal entry: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified journal entry.
     */
    public function destroy(Request $request, JournalEntry $journalEntry, OfficeScopeService $officeScope)
    {
        if ($deny = $this->denyIfJournalEntryNotAccessible($request, $journalEntry, $officeScope)) {
            return $deny;
        }

        if ($journalEntry->status !== 'draft') {
            return $this->error('Only draft journal entries can be deleted', 400);
        }

        $journalEntry->lines()->delete();
        $journalEntry->delete();

        return $this->success(null, 'Journal entry deleted successfully');
    }

    /**
     * Post a journal entry.
     */
    public function post(Request $request, JournalEntry $journalEntry, OfficeScopeService $officeScope)
    {
        if ($deny = $this->denyIfJournalEntryNotAccessible($request, $journalEntry, $officeScope)) {
            return $deny;
        }

        if (!$journalEntry->canBePosted()) {
            return $this->error('Journal entry cannot be posted. Ensure it is a draft and balanced.', 400);
        }

        // Check fiscal period is still open
        if ($journalEntry->fiscalPeriod->status !== 'open') {
            return $this->error('The fiscal period is not open for posting', 400);
        }

        $journalEntry->loadMissing('lines');
        try {
            app(ProjectFiscalPeriodPostingService::class)->assertProjectsOpenForPosting(
                $journalEntry->fiscalPeriod,
                $journalEntry->lines->pluck('project_id')->all()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $journalEntry->post($request->user());

        return $this->success($journalEntry, 'Journal entry posted successfully');
    }

    /**
     * Reverse a journal entry.
     */
    public function reverse(Request $request, JournalEntry $journalEntry, OfficeScopeService $officeScope)
    {
        if ($deny = $this->denyIfJournalEntryNotAccessible($request, $journalEntry, $officeScope)) {
            return $deny;
        }

        if (!$journalEntry->canBeReversed()) {
            return $this->error('This journal entry cannot be reversed', 400);
        }

        $validated = $request->validate([
            'reversal_date' => 'required|date',
            'description' => 'nullable|string',
        ]);

        // Get fiscal period for reversal date
        $fiscalPeriod = FiscalPeriod::whereHas('fiscalYear', fn($q) => 
            $q->where('organization_id', $request->user()->organization_id)
        )
        ->where('status', 'open')
        ->where('start_date', '<=', $validated['reversal_date'])
        ->where('end_date', '>=', $validated['reversal_date'])
        ->first();

        if (!$fiscalPeriod) {
            return $this->error('No open fiscal period found for the reversal date', 400);
        }

        $journalEntry->loadMissing('lines');
        try {
            app(ProjectFiscalPeriodPostingService::class)->assertProjectsOpenForPosting(
                $fiscalPeriod,
                $journalEntry->lines->pluck('project_id')->all()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        DB::beginTransaction();
        try {
            // Generate entry number
            $entryNumber = $this->generateEntryNumber($request->user()->organization_id, 'reversing');

            // Create reversing entry
            $reversalEntry = JournalEntry::create([
                'organization_id' => $request->user()->organization_id,
                'journal_id' => $journalEntry->journal_id,
                'office_id' => $journalEntry->office_id,
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => $entryNumber,
                'entry_date' => $validated['reversal_date'],
                'entry_type' => 'reversing',
                'reference' => 'Reversal of ' . $journalEntry->entry_number,
                'description' => $validated['description'] ?? 'Reversal of entry: ' . $journalEntry->description,
                'currency' => $journalEntry->currency,
                'exchange_rate' => $journalEntry->exchange_rate,
                'total_debit' => $journalEntry->total_credit, // Swap
                'total_credit' => $journalEntry->total_debit, // Swap
                'status' => 'posted',
                'created_by' => $request->user()->id,
                'posted_by' => $request->user()->id,
                'posted_at' => now(),
                'posting_date' => $validated['reversal_date'],
            ]);

            // Create reversed lines (swap debits and credits)
            foreach ($journalEntry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reversalEntry->id,
                    'account_id' => $line->account_id,
                    'fund_id' => $line->fund_id,
                    'project_id' => $line->project_id,
                    'donor_expenditure_code_id' => $line->donor_expenditure_code_id,
                    'office_id' => $line->office_id,
                    'line_number' => $line->line_number,
                    'description' => 'Reversal: ' . ($line->description ?? ''),
                    'debit_amount' => $line->credit_amount, // Swap
                    'credit_amount' => $line->debit_amount, // Swap
                    'currency' => $line->currency,
                    'exchange_rate' => $line->exchange_rate,
                    'base_currency_debit' => $line->base_currency_credit, // Swap
                    'base_currency_credit' => $line->base_currency_debit, // Swap
                    'cost_center' => $line->cost_center,
                ]);
            }

            // Mark original entry as reversed
            $journalEntry->update([
                'status' => 'reversed',
                'reversed_by' => $request->user()->id,
                'reversed_at' => now(),
                'reversal_entry_id' => $reversalEntry->id,
            ]);

            DB::commit();

            return $this->success([
                'original_entry' => $journalEntry,
                'reversal_entry' => $reversalEntry,
            ], 'Journal entry reversed successfully');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to reverse journal entry: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate entry number.
     */
    private function generateEntryNumber(int $organizationId, string $entryType): string
    {
        $prefix = match($entryType) {
            'standard' => 'JE',
            'adjusting' => 'AJE',
            'closing' => 'CJE',
            'reversing' => 'RJE',
            'recurring' => 'RCE',
            default => 'JE',
        };

        $year = date('Y');
        $month = date('m');

        $lastEntry = JournalEntry::where('organization_id', $organizationId)
            ->where('entry_number', 'like', "{$prefix}-{$year}{$month}-%")
            ->orderBy('entry_number', 'desc')
            ->first();

        if ($lastEntry) {
            $lastNumber = (int) substr($lastEntry->entry_number, -5);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('%s-%s%s-%05d', $prefix, $year, $month, $newNumber);
    }

    /**
     * Get journal entry summary statistics.
     */
    public function summary(Request $request, OfficeScopeService $officeScope)
    {
        $orgId = $request->user()->organization_id;
        $base = JournalEntry::where('organization_id', $orgId);
        $this->applyJournalEntryOfficeScope($base, $request->user(), $officeScope);

        $stats = [
            'total_entries' => (clone $base)->count(),
            'draft_entries' => (clone $base)->where('status', 'draft')->count(),
            'posted_entries' => (clone $base)->where('status', 'posted')->count(),
            'reversed_entries' => (clone $base)->where('status', 'reversed')->count(),
            'total_debit' => (clone $base)->where('status', 'posted')->sum('total_debit'),
            'total_credit' => (clone $base)->where('status', 'posted')->sum('total_credit'),
        ];

        $recentEntries = (clone $base)
            ->with(['office:id,name', 'creator:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return $this->success([
            'stats' => $stats,
            'recent_entries' => $recentEntries,
        ]);
    }

    /**
     * Provincial users only see entries with no book or a book in their office; head-office books are excluded unless they can view all journal books.
     */
    private function applyJournalEntryOfficeScope(Builder $query, User $user, OfficeScopeService $officeScope): void
    {
        if ($officeScope->canViewAllJournalBooks($user)) {
            return;
        }
        if (! $user->office_id) {
            $query->whereRaw('1 = 0');

            return;
        }
        $oid = (int) $user->office_id;
        $query->where(function (Builder $q) use ($oid) {
            $q->whereNull('journal_id')
                ->orWhereHas('journal', function (Builder $jq) use ($oid) {
                    $jq->where('office_id', $oid);
                });
        });
    }

    private function denyIfJournalEntryNotAccessible(Request $request, JournalEntry $journalEntry, OfficeScopeService $officeScope): ?JsonResponse
    {
        if ($journalEntry->organization_id !== $request->user()->organization_id) {
            return $this->error('Journal entry not found', 404);
        }
        if ($journalEntry->journal_id) {
            $j = Journal::withTrashed()->find($journalEntry->journal_id);
            if (! $j || (int) $j->organization_id !== (int) $request->user()->organization_id) {
                return $this->error('Journal entry not found', 404);
            }
            if ($j->trashed() && ! $officeScope->canViewAllJournalBooks($request->user())) {
                return $this->error('Journal entry not found', 404);
            }
            if (! $j->trashed() && ! $officeScope->userCanAccessJournalBook($request->user(), $j)) {
                return $this->error('Journal entry not found', 404);
            }
        }

        return null;
    }
}
