<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Exports\ChartOfAccountsExport;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\AuditLogService;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\OfficeContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Organization;
use App\Services\AccountCodeScheme;
use App\Services\ChartOfAccountsImportService;
use App\Support\ChartOfAccountsCache;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ChartOfAccountController extends Controller
{
    /**
     * Sort column for chart rows — prefer account_code_sort (natural dotted order) when the column exists.
     */
    private function coaOrderColumn(): string
    {
        static $column = null;
        if ($column === null) {
            $column = Schema::hasColumn('chart_of_accounts', 'account_code_sort')
                ? 'account_code_sort'
                : 'account_code';
        }

        return $column;
    }

    /**
     * Display a listing of accounts.
     */
    public function index(Request $request)
    {
        $query = ChartOfAccount::where('organization_id', $request->user()->organization_id)
            ->with('parent:id,account_code,account_name');

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // Filter by type
        if ($request->has('account_type')) {
            $query->where('account_type', $request->account_type);
        }

        // Filter by level
        if ($request->has('level')) {
            $query->where('level', $request->level);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter posting accounts only
        if ($request->boolean('posting_only')) {
            $query->where('is_posting', true);
        }

        // Filter by currency (multi-currency support)
        if ($request->filled('currency_code')) {
            $code = strtoupper($request->currency_code);
            $query->where('currency_code', $code);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('account_code', 'like', "%{$search}%")
                  ->orWhere('account_name', 'like', "%{$search}%");
            });
        }

        $query->orderBy($this->coaOrderColumn());

        $perPage = min((int) $request->input('per_page', 50), 500);
        $accounts = $query->paginate($perPage);

        return $this->paginated($accounts);
    }

    /**
     * Build tree payload (arrays) for JSON — used by cache and by explicit refresh (bypass_cache).
     */
    private function buildChartOfAccountsTreePayload(int $orgId, bool $withTrashed): array
    {
        $orderColumn = $this->coaOrderColumn();
        $tree = $this->loadChartOfAccountsTree($orgId, $withTrashed, $orderColumn);

        try {
            $this->addRolledUpBalancesToTree($tree);
        } catch (\Throwable $e) {
            Log::warning('Chart of accounts tree: rolled-up balances failed; continuing without journal totals.', [
                'organization_id' => $orgId,
                'exception' => $e->getMessage(),
            ]);
        }

        // Plain arrays only — never serialized Eloquent models in cache.
        return $tree->toArray();
    }

    /**
     * Get accounts as a tree structure (all levels including Layer 4 / Account).
     * L4 posting accounts: balance = opening_balance + net of posted journal lines.
     * L3–L1: rolled_up_balance = sum of direct children (L4 → L3 → L2 → L1).
     *
     * Query: bypass_cache=1 skips server-side cache (use for manual Refresh in the UI).
     */
    public function tree(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $officeId = OfficeContext::getOfficeId() ?? 0;
        $withTrashed = $request->boolean('with_trashed');
        $cacheKey = "coa_tree_{$orgId}_o{$officeId}_t" . ($withTrashed ? '1' : '0');
        // Mutations (create/update/delete/restore) clear this key; longer TTL reduces DB load on repeat views.
        $cacheTtl = 300;

        if ($request->boolean('bypass_cache')) {
            $accounts = $this->buildChartOfAccountsTreePayload($orgId, $withTrashed);
        } else {
            $cacheDriver = config('cache.default');
            $cache = $cacheDriver === 'database'
                ? Cache::store('file')
                : Cache::store($cacheDriver);
            try {
                $cached = $cache->get($cacheKey);
            } catch (\Throwable $e) {
                Log::warning('Chart of accounts tree: cache read failed; bypassing cache.', [
                    'driver' => $cacheDriver,
                    'exception' => $e->getMessage(),
                ]);
                $cached = null;
            }
            if ($cached !== null) {
                return $this->success($cached);
            }
            $accounts = $this->buildChartOfAccountsTreePayload($orgId, $withTrashed);
            try {
                $cache->put($cacheKey, $accounts, $cacheTtl);
            } catch (\Throwable $e) {
                Log::warning('Chart of accounts tree: cache write failed; continuing without cache.', [
                    'driver' => $cacheDriver,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $this->success($accounts);
    }

    /**
     * Flat list of all accounts for the org — for client-side PDF (no tree wiring, no journal rollup, no cache).
     * Avoids heavy work in {@see tree} that can fail or time out on large datasets.
     */
    public function flatForExport(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $withTrashed = $request->boolean('with_trashed');

        $query = ChartOfAccount::where('organization_id', $orgId)
            ->with('parent:id,account_code,account_name');
        if ($withTrashed) {
            $query->withTrashed();
        }
        $accounts = $query->orderBy($this->coaOrderColumn())->get();

        return $this->success($accounts);
    }

    /**
     * POST /chart-of-accounts/import — multipart file (CSV or Excel "Sample format").
     * Requires edit-chart-of-accounts and edit-chart-of-accounts-code.
     */
    public function import(Request $request, ChartOfAccountsImportService $importService)
    {
        if (! $request->user()->can('edit-chart-of-accounts')) {
            return $this->error('You do not have permission to import accounts.', 403);
        }
        if (! $request->user()->can('edit-chart-of-accounts-code')) {
            return $this->error('Import requires permission to set account codes.', 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls,xlsm'],
        ], [
            'file.max' => 'The file may not be greater than 10 MB.',
            'file.mimes' => 'The file must be a CSV or Excel file (.csv, .txt, .xlsx, .xls, .xlsm).',
        ]);

        $result = $importService->run($request->file('file'), (int) $request->user()->organization_id);

        $message = ($result['diagnostics'] ?? null) !== null
            ? 'Import could not be completed — see details in the response.'
            : 'Import finished.';

        return $this->success($result, $message);
    }

    /**
     * Load all accounts for an org in one query, then wire parent/child relations in memory (avoids N+1 recursive eager loads).
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function loadChartOfAccountsTree(int $orgId, bool $withTrashed, string $orderColumn): Collection
    {
        $query = ChartOfAccount::where('organization_id', $orgId);
        if ($withTrashed) {
            $query->withTrashed();
        }
        $all = $query->orderBy($orderColumn)->get();

        $childrenByParentId = [];
        foreach ($all as $account) {
            $pid = $account->parent_id;
            if ($pid === null) {
                continue;
            }
            if (! isset($childrenByParentId[$pid])) {
                $childrenByParentId[$pid] = collect();
            }
            $childrenByParentId[$pid]->push($account);
        }
        foreach ($childrenByParentId as $pid => $col) {
            $childrenByParentId[$pid] = $col->sortBy($orderColumn)->values();
        }
        foreach ($all as $account) {
            $account->setRelation('children', $childrenByParentId[$account->id] ?? collect());
        }

        return $all->whereNull('parent_id')->sortBy($orderColumn)->values();
    }

    /**
     * Flatten tree to list (depth-first) without O(n²) merge chains.
     *
     * @param  Collection<int, ChartOfAccount>|iterable<ChartOfAccount>  $nodes
     * @return Collection<int, ChartOfAccount>
     */
    private function flattenTree($nodes): Collection
    {
        $list = collect();
        $walk = function ($current) use (&$list, &$walk): void {
            foreach ($current as $node) {
                $list->push($node);
                if ($node->relationLoaded('children') && $node->children->isNotEmpty()) {
                    $walk($node->children);
                }
            }
        };
        $walk($nodes);

        return $list;
    }

    /**
     * Compute L4 balances (opening + posted journal net) then roll up to L3→L2→L1 and set rolled_up_balance on each node.
     *
     * @param  \Illuminate\Support\Collection<int, ChartOfAccount>  $roots
     */
    private function addRolledUpBalancesToTree($roots): void
    {
        $all = $this->flattenTree($roots);
        $postingIds = $all->filter(fn ($a) => $a->is_posting)->pluck('id')->unique()->values()->all();

        $postingBalanceMap = [];
        if (! empty($postingIds)) {
            try {
                $jeTable = (new JournalEntry)->getTable();
                $totals = JournalEntryLine::query()
                    ->join($jeTable, 'journal_entry_lines.journal_entry_id', '=', $jeTable.'.id')
                    ->whereNull($jeTable.'.deleted_at')
                    ->where($jeTable.'.status', 'posted')
                    ->whereIn('journal_entry_lines.account_id', $postingIds)
                    ->groupBy('journal_entry_lines.account_id')
                    ->selectRaw('journal_entry_lines.account_id, COALESCE(SUM(journal_entry_lines.base_currency_debit), 0) as total_debit, COALESCE(SUM(journal_entry_lines.base_currency_credit), 0) as total_credit')
                    ->get()
                    ->keyBy('account_id');

                foreach ($all as $acc) {
                    if (! $acc->is_posting) {
                        continue;
                    }
                    $opening = (float) ($acc->opening_balance ?? 0);
                    $row = $totals->get($acc->id) ?? $totals->get((string) $acc->id);
                    $debit = $row ? (float) $row->total_debit : 0;
                    $credit = $row ? (float) $row->total_credit : 0;
                    if (strtolower($acc->normal_balance ?? '') === 'debit') {
                        $postingBalanceMap[$acc->id] = $opening + $debit - $credit;
                    } else {
                        $postingBalanceMap[$acc->id] = $opening + $credit - $debit;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Chart of accounts: journal totals query failed; using opening balances only for rolled-up display.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $compute = function ($node) use (&$compute, $postingBalanceMap) {
            if ($node->is_posting) {
                $balance = $postingBalanceMap[$node->id] ?? (float) ($node->opening_balance ?? 0);
                $node->setAttribute('rolled_up_balance', round($balance, 2));
                return $balance;
            }
            $children = $node->relationLoaded('children') ? $node->children : collect();
            $sum = 0;
            foreach ($children as $child) {
                $sum += $compute($child);
            }
            $node->setAttribute('rolled_up_balance', round($sum, 2));
            return $sum;
        };

        foreach ($roots as $root) {
            $compute($root);
        }
    }

    /**
     * Get children of an account.
     */
    public function children(Request $request, ChartOfAccount $account)
    {
        if ($account->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }

        $children = $account->children()->orderBy($this->coaOrderColumn())->get();

        return $this->success($children);
    }

    /**
     * Suggest the next account code for a new account under the given parent (or top-level).
     * GET ?parent_id=123 or no parent_id for Layer 1.
     * Dotted scheme: L1 1–5, L2 11/21…, L3 11.1, L4 11.1.1.
     */
    public function suggestCode(Request $request)
    {
        $orgId = $request->user()->organization_id;
        $parentId = $request->has('parent_id') ? $request->input('parent_id') : null;

        $suggested = $this->generateNextAccountCode($orgId, $parentId);

        return $this->success([
            'suggested_code' => $suggested,
            'level' => $parentId
                ? (ChartOfAccount::where('organization_id', $orgId)->find($parentId)?->level ?? 0) + 1
                : 1,
        ]);
    }

    /**
     * Generate next account code for the given org and parent (null = Layer 1).
     * Dotted scheme: L1 1–5, L2 11/21…, L3 11.1, L4 11.1.1.
     */
    private function generateNextAccountCode(int $orgId, ?int $parentId): ?string
    {
        $usedCodes = ChartOfAccount::withTrashed()
            ->where('organization_id', $orgId)
            ->pluck('account_code')
            ->mapWithKeys(fn ($c) => [trim((string) $c) => true])
            ->all();

        if ($parentId === null) {
            $siblings = [];

            return AccountCodeScheme::nextCode(null, 0, $siblings, $usedCodes);
        }

        $parent = ChartOfAccount::where('organization_id', $orgId)->find($parentId);
        if (! $parent) {
            return null;
        }

        $level = (int) $parent->level + 1;
        if ($level > 4) {
            return null;
        }

        $siblingCodes = ChartOfAccount::withTrashed()
            ->where('organization_id', $orgId)
            ->where('parent_id', $parentId)
            ->pluck('account_code')
            ->map(fn ($c) => trim((string) $c))
            ->values()
            ->all();

        $parentCode = trim((string) $parent->account_code);

        return AccountCodeScheme::nextCode($parentCode, (int) $parent->level, $siblingCodes, $usedCodes);
    }

    /**
     * Store a newly created account.
     * account_code is optional; when omitted, the next code for the chosen layer (parent) is generated.
     */
    public function store(Request $request)
    {
        if (! $request->user()->can('edit-chart-of-accounts')) {
            return $this->error('You do not have permission to create or edit accounts.', 403);
        }

        $orgId = $request->user()->organization_id;
        $validated = $request->validate([
            'parent_id' => ['nullable', Rule::exists(ChartOfAccount::class, 'id')->where('organization_id', $orgId)],
            'account_code' => [
                'nullable',
                'string',
                'max:20',
                function (string $attr, $value, \Closure $fail) use ($request) {
                    $code = trim((string) ($value ?? ''));
                    if ($code === '') return;
                    $existing = ChartOfAccount::withTrashed()
                        ->where('organization_id', $request->user()->organization_id)
                        ->where('account_code', $code)
                        ->with('parent')
                        ->first();
                    if ($existing) {
                        $hint = $existing->trashed()
                            ? ' (previously deleted – code cannot be reused).'
                            : ' (used by "' . $existing->account_name . '"' . ($existing->parent ? ' under ' . $existing->parent->account_name : '') . ').';
                        $fail('Account code "' . $code . '" is already in use' . $hint . ' Choose a unique code.');
                    }
                },
            ],
            'account_name' => 'required|string|max:255',
            'account_type' => 'required|in:asset,liability,equity,revenue,expense',
            'normal_balance' => 'required|in:debit,credit',
            'is_header' => 'boolean',
            'is_posting' => 'boolean',
            'is_bank_account' => 'boolean',
            'is_cash_account' => 'boolean',
            'fund_type' => 'nullable|in:unrestricted,restricted,temporarily_restricted',
            'currency_code' => [
                'nullable',
                'string',
                'size:3',
                Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id)),
            ],
            'description' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'is_active' => 'boolean',
        ], [
            'currency_code.in' => 'Currency must be one of your organization\'s active currencies. Add it in Settings → Currencies first.',
        ]);

        $validated['organization_id'] = $orgId;

        $isHeader = (bool) ($validated['is_header'] ?? false);
        $isPosting = (bool) ($validated['is_posting'] ?? true);
        $validated['currency_code'] = $this->resolveCurrencyCodeForCoa(
            $validated['currency_code'] ?? null,
            $isHeader,
            $isPosting,
            (int) $validated['organization_id']
        );

        // Determine level based on parent
        if (!empty($validated['parent_id'])) {
            $parent = ChartOfAccount::where('id', $validated['parent_id'])
                ->where('organization_id', $validated['organization_id'])
                ->first();
            if (!$parent) {
                return $this->error('Parent account not found or does not belong to your organization.', 400);
            }
            $validated['level'] = $parent->level + 1;

            if ($validated['level'] > 4) {
                return $this->error('Maximum account level (4) exceeded', 400);
            }
        } else {
            $validated['level'] = 1;
        }

        $wasAutoGenerated = false;
        // Users without edit-chart-of-accounts-code must use auto-generated codes only
        $canEditCode = $request->user()->can('edit-chart-of-accounts-code');
        $code = isset($validated['account_code']) ? trim((string) $validated['account_code']) : '';
        if ($code === '' || !$canEditCode) {
            $generated = $this->generateNextAccountCode(
                $validated['organization_id'],
                $validated['parent_id'] ?? null
            );
            if ($generated === null) {
                return $this->error('Could not auto-generate account code. Please provide an account code.', 400);
            }
            $validated['account_code'] = $generated;
            $wasAutoGenerated = true;
        }

        if ($canEditCode && ! $wasAutoGenerated) {
            $finalCode = trim((string) ($validated['account_code'] ?? ''));
            if (! AccountCodeScheme::isWellFormed($finalCode)) {
                return $this->error('Account code must use the dotted format (e.g. 1, 11, 11.1, 11.1.1). Five-digit numeric codes are not valid.', 422);
            }
            if (! empty($validated['parent_id'])) {
                $p = ChartOfAccount::where('id', $validated['parent_id'])
                    ->where('organization_id', $validated['organization_id'])
                    ->first();
                if ($p && ! AccountCodeScheme::isValidChildCode($finalCode, trim((string) $p->account_code))) {
                    return $this->error('Account code must extend the parent code (e.g. under 11 use 11.1, 11.2, …).', 422);
                }
            } elseif (AccountCodeScheme::levelFromCode($finalCode) !== 1) {
                return $this->error('Top-level account code must be a single digit 1–5.', 422);
            }
        }

        // When adding a child, ensure parent is a header (not posting). Posting accounts cannot have children.
        if (!empty($validated['parent_id'])) {
            $parent = ChartOfAccount::where('id', $validated['parent_id'])
                ->where('organization_id', $validated['organization_id'])
                ->first();
            if ($parent && $parent->is_posting) {
                $parent->update(['is_header' => true, 'is_posting' => false]);
            }
        }

        $maxRetries = $wasAutoGenerated ? 2 : 1;
        $account = null;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $account = ChartOfAccount::create($validated);
                break;
            } catch (UniqueConstraintViolationException|QueryException $e) {
                $isDuplicate = $e instanceof UniqueConstraintViolationException
                    || str_contains($e->getMessage(), 'Duplicate entry')
                    || str_contains($e->getMessage(), '1062');
                if ($isDuplicate && $attempt < $maxRetries - 1 && $wasAutoGenerated) {
                    // Regenerate and retry once for auto-generated codes (handles race conditions)
                    $validated['account_code'] = $this->generateNextAccountCode(
                        $validated['organization_id'],
                        $validated['parent_id'] ?? null
                    ) ?? $validated['account_code'];
                    continue;
                }
                if ($isDuplicate) {
                    $code = $validated['account_code'] ?? '';
                    $existing = ChartOfAccount::withTrashed()
                        ->where('organization_id', $validated['organization_id'])
                        ->where('account_code', $code)
                        ->with('parent')
                        ->first();
                    $hint = $existing
                        ? ($existing->trashed()
                            ? ' (previously deleted – code cannot be reused)'
                            : ' (used by "' . $existing->account_name . '"' . ($existing->parent ? ' under ' . $existing->parent->account_name : '') . ')')
                        : '';
                    return $this->error('Account code "' . $code . '" is already in use.' . $hint . ' Please choose a unique code.', 422);
                }
                throw $e;
            }
        }
        if ($account === null) {
            return $this->error('Account could not be created.', 500);
        }
        ChartOfAccountsCache::forgetForOrganization((int) $validated['organization_id']);

        return $this->success($account, 'Account created successfully', 201);
    }

    /**
     * Display the specified account.
     */
    public function show(Request $request, ChartOfAccount $chartOfAccount)
    {
        if ($chartOfAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }

        $chartOfAccount->load(['parent', 'children']);

        // Get balance
        $balance = $chartOfAccount->getBalance();

        return $this->success([
            'account' => $chartOfAccount,
            'balance' => $balance,
            'full_path' => $chartOfAccount->full_path,
        ]);
    }

    /**
     * Update the specified account.
     */
    public function update(Request $request, ChartOfAccount $chartOfAccount)
    {
        if ($chartOfAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }

        $user = $request->user();
        $bodyKeys = collect($request->except(['_token', '_method']))->keys()->values()->all();
        $openingKeys = ['opening_balance', 'opening_balance_date'];
        $onlyOpeningFields = count($bodyKeys) > 0
            && empty(array_diff($bodyKeys, $openingKeys))
            && count(array_intersect($bodyKeys, $openingKeys)) > 0;

        if ($onlyOpeningFields) {
            if (
                ! $user->can('edit-opening-balances')
                && ! $user->can('edit-chart-of-accounts')
                && ! $user->can('manage-chart-of-accounts')
            ) {
                return $this->error('You do not have permission to edit opening balances.', 403);
            }
            $validated = $request->validate([
                'opening_balance' => 'nullable|numeric',
                'opening_balance_date' => 'nullable|date',
            ]);
            $chartOfAccount->update($validated);
            ChartOfAccountsCache::forgetForOrganization((int) $chartOfAccount->organization_id);

            return $this->success($chartOfAccount->fresh(), 'Account updated successfully');
        }

        if (! $user->can('edit-chart-of-accounts')) {
            return $this->error('You do not have permission to edit accounts.', 403);
        }

        $canEditCode = $request->user()->can('edit-chart-of-accounts-code');
        $rules = [
            'account_code' => [
                'required',
                'string',
                'min:1',
                'max:20',
                Rule::unique(ChartOfAccount::class)->where(function ($query) use ($request) {
                    return $query->where('organization_id', $request->user()->organization_id);
                })->ignore($chartOfAccount->id),
            ],
            'account_name' => 'required|string|min:1|max:255',
            'account_type' => 'sometimes|in:asset,liability,equity,revenue,expense',
            'normal_balance' => 'sometimes|in:debit,credit',
            'is_header' => 'sometimes|boolean',
            'is_posting' => 'sometimes|boolean',
            'is_bank_account' => 'sometimes|boolean',
            'is_cash_account' => 'sometimes|boolean',
            'fund_type' => 'nullable|in:unrestricted,restricted,temporarily_restricted',
            'currency_code' => [
                'nullable',
                'string',
                'size:3',
                Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id)),
            ],
            'description' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
        ];
        $messages = [
            'account_code.unique' => 'This account code is already in use. Choose a unique code.',
            'currency_code.in' => 'Currency must be one of your organization\'s active currencies. Add it in Settings → Currencies first.',
        ];
        $validated = $request->validate($rules, $messages);

        // Users without edit-chart-of-accounts-code cannot change account code
        if (!$canEditCode) {
            $validated['account_code'] = $chartOfAccount->account_code;
        }

        if ($canEditCode && array_key_exists('account_code', $validated)) {
            $finalCode = trim((string) $validated['account_code']);
            if (! AccountCodeScheme::isWellFormed($finalCode)) {
                return $this->error('Account code must use the dotted format (e.g. 1, 11, 11.1, 11.1.1).', 422);
            }
            $parent = $chartOfAccount->parent;
            if ($parent) {
                if (! AccountCodeScheme::isValidChildCode($finalCode, trim((string) $parent->account_code))) {
                    return $this->error('Account code must extend the parent code.', 422);
                }
            } elseif (AccountCodeScheme::levelFromCode($finalCode) !== 1) {
                return $this->error('Top-level account code must be a single digit 1–5.', 422);
            }
        }

        $isHeader = array_key_exists('is_header', $validated) ? (bool) $validated['is_header'] : $chartOfAccount->is_header;
        $isPosting = array_key_exists('is_posting', $validated) ? (bool) $validated['is_posting'] : $chartOfAccount->is_posting;

        if (array_key_exists('currency_code', $validated)) {
            $validated['currency_code'] = $this->resolveCurrencyCodeForCoa(
                $validated['currency_code'],
                $isHeader,
                $isPosting,
                (int) $chartOfAccount->organization_id
            );
        } elseif (array_key_exists('is_header', $validated) || array_key_exists('is_posting', $validated)) {
            $validated['currency_code'] = $this->resolveCurrencyCodeForCoa(
                $chartOfAccount->currency_code,
                $isHeader,
                $isPosting,
                (int) $chartOfAccount->organization_id
            );
        }

        $chartOfAccount->update($validated);
        ChartOfAccountsCache::forgetForOrganization((int) $chartOfAccount->organization_id);

        return $this->success($chartOfAccount->fresh(), 'Account updated successfully');
    }

    /**
     * Remove the specified account (temporarily / soft delete). Requires manage-chart-of-accounts.
     */
    public function destroy(Request $request, ChartOfAccount $chartOfAccount)
    {
        if (! $request->user()->can('delete-chart-of-accounts')) {
            return $this->error('You do not have permission to delete accounts.', 403);
        }
        if ($chartOfAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }

        // Check if account has transactions
        if ($chartOfAccount->journalEntryLines()->exists()) {
            return $this->error('Cannot delete account with transactions', 400);
        }

        // Check if account has children
        if ($chartOfAccount->children()->exists()) {
            return $this->error('Cannot delete account with child accounts', 400);
        }

        $orgId = $chartOfAccount->organization_id;
        $chartOfAccount->delete();
        ChartOfAccountsCache::forgetForOrganization((int) $orgId);

        return $this->success(null, 'Account temporarily deleted. You can restore it from deleted accounts.');
    }

    /**
     * Restore a soft-deleted (temporarily deleted) account. Requires manage-chart-of-accounts.
     */
    public function restore(Request $request, int $id)
    {
        if (! $request->user()->can('delete-chart-of-accounts')) {
            return $this->error('You do not have permission to restore accounts.', 403);
        }
        $account = ChartOfAccount::withTrashed()->find($id);
        if (!$account) {
            return $this->error('Account not found', 404);
        }
        if ($account->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }
        if (!$account->trashed()) {
            return $this->error('Account is not deleted.', 400);
        }

        $account->restore();
        ChartOfAccountsCache::forgetForOrganization((int) $account->organization_id);

        return $this->success($account->fresh(), 'Account restored successfully.');
    }

    /**
     * Permanently delete a soft-deleted account. Frees the account code for reuse.
     * Requires delete-chart-of-accounts-permanently permission.
     * Only allowed when account has no transactions and no children.
     * Logs the action for audit trail.
     */
    public function forceDelete(Request $request, int $id)
    {
        if (!$request->user()->can('delete-chart-of-accounts-permanently')) {
            return $this->error('You do not have permission to permanently delete accounts.', 403);
        }
        $account = ChartOfAccount::withTrashed()->find($id);
        if (!$account) {
            return $this->error('Account not found', 404);
        }
        if ($account->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }
        if (!$account->trashed()) {
            return $this->error('Account is not deleted. Use Temporarily delete first.', 400);
        }

        if ($account->journalEntryLines()->exists()) {
            return $this->error('Cannot permanently delete account with transaction history. The code cannot be reused.', 400);
        }
        if ($account->children()->withTrashed()->exists()) {
            return $this->error('Cannot permanently delete account with child accounts.', 400);
        }

        $validated = $request->validate(['reason' => 'nullable|string|max:500']);
        $reason = $validated['reason'] ?? null;
        $snapshot = $account->only(['id', 'account_code', 'account_name', 'account_type', 'level', 'parent_id']);

        AuditLogService::log(
            'force_delete',
            $account,
            sprintf(
                'Chart of account %s - %s permanently deleted. Code %s freed for reuse.%s',
                $account->account_code,
                $account->account_name,
                $account->account_code,
                $reason ? ' Reason: ' . $reason : ''
            ),
            $snapshot,
            ['reason' => $reason]
        );

        $orgId = $account->organization_id;
        $account->forceDelete();
        ChartOfAccountsCache::forgetForOrganization((int) $orgId);

        return $this->success(null, 'Account permanently deleted. The code can now be reused.');
    }

    /**
     * Activate an account.
     */
    public function activate(Request $request, ChartOfAccount $chartOfAccount)
    {
        if (! $request->user()->can('edit-chart-of-accounts')) {
            return $this->error('You do not have permission to edit accounts.', 403);
        }

        if ($chartOfAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }

        $chartOfAccount->update(['is_active' => true]);
        ChartOfAccountsCache::forgetForOrganization((int) $chartOfAccount->organization_id);

        return $this->success($chartOfAccount->fresh(), 'Account activated successfully');
    }

    /**
     * Deactivate an account.
     */
    public function deactivate(Request $request, ChartOfAccount $chartOfAccount)
    {
        if (! $request->user()->can('edit-chart-of-accounts')) {
            return $this->error('You do not have permission to edit accounts.', 403);
        }

        if ($chartOfAccount->organization_id !== $request->user()->organization_id) {
            return $this->error('Account not found', 404);
        }

        // Check if account has pending transactions
        $hasPending = $chartOfAccount->journalEntryLines()
            ->whereHas('journalEntry', fn($q) => $q->where('status', 'draft'))
            ->exists();

        if ($hasPending) {
            return $this->error('Cannot deactivate account with pending transactions', 400);
        }

        $chartOfAccount->update(['is_active' => false]);
        ChartOfAccountsCache::forgetForOrganization((int) $chartOfAccount->organization_id);

        return $this->success($chartOfAccount->fresh(), 'Account deactivated successfully');
    }

    /**
     * Export chart of accounts to Excel (.xlsx) or CSV.
     * PDF is generated in the browser (jsPDF), same pattern as the project list — see frontend exportChartOfAccounts.
     * GET /chart-of-accounts/export?format=xlsx|csv
     */
    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $validated = $request->validate([
            'format' => 'required|in:xlsx,csv',
            'with_trashed' => 'sometimes|boolean',
            'columns' => ['sometimes', 'array', 'min:1'],
            'columns.*' => ['string', Rule::in(ChartOfAccountsExport::ALLOWED_COLUMNS)],
        ]);
        $format = $validated['format'];
        $orgId = $user->organization_id;
        $withTrashed = $request->boolean('with_trashed');

        $columnKeys = isset($validated['columns']) && $validated['columns'] !== []
            ? ChartOfAccountsExport::normalizeColumnKeys($validated['columns'])
            : ChartOfAccountsExport::DEFAULT_COLUMNS;

        $query = ChartOfAccount::where('organization_id', $orgId);
        if ($withTrashed) {
            $query->withTrashed();
        }
        $accounts = ChartOfAccountsExport::sortDepthFirst($query->get());

        $date = now()->format('Y-m-d');
        $baseName = 'chart-of-accounts-' . $date;

        $defaultCurrency = (string) (Organization::where('id', $orgId)->value('default_currency')
            ?? config('erp.currencies.default', 'AFN'));

        $export = new ChartOfAccountsExport($accounts, $columnKeys, $defaultCurrency, $format === 'xlsx');

        $ext = $format === 'csv' ? 'csv' : 'xlsx';
        $writerType = $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX;

        return Excel::download($export, $baseName . '.' . $ext, $writerType);
    }

    /**
     * Category / subcategory / GL headers do not use account currency; only posting (leaf) accounts do.
     */
    private function resolveCurrencyCodeForCoa(?string $currencyCode, bool $isHeader, bool $isPosting, int $organizationId): ?string
    {
        $trimmed = $currencyCode !== null && $currencyCode !== '' ? strtoupper(trim($currencyCode)) : null;
        if ($trimmed !== null) {
            return $trimmed;
        }
        if ($isHeader || !$isPosting) {
            return null;
        }

        return Organization::find($organizationId)?->default_currency
            ?? config('erp.currencies.default', 'AFN');
    }

}
