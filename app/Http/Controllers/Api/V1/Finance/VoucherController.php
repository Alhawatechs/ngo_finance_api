<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\DonorExpenditureCode;
use App\Models\Fund;
use App\Models\Journal;
use App\Models\Organization;
use App\Models\Office;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Services\AccountingService;
use App\Services\Finance\ProjectFiscalPeriodPostingService;
use App\Services\CodingBlockVoucherNumberService;
use App\Services\NotificationService;
use App\Services\OfficeContext;
use App\Services\OfficeScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class VoucherController extends Controller
{
    protected AccountingService $accountingService;

    protected NotificationService $notificationService;

    public function __construct(AccountingService $accountingService, NotificationService $notificationService)
    {
        $this->accountingService = $accountingService;
        $this->notificationService = $notificationService;
    }

    /**
     * Exists rules for tables on the office financial connection (projects, funds, chart_of_accounts).
     * Plain exists:… rules use the default DB and break when the office uses a provisioned database.
     *
     * @return array{project: \Illuminate\Validation\Rules\Exists, fund: \Illuminate\Validation\Rules\Exists, chart_account: \Illuminate\Validation\Rules\Exists}
     */
    private function voucherOfficeExistsRules(Request $request): array
    {
        $orgId = (int) $request->user()->organization_id;

        // Laravel 11: Rule::exists has no usingConnection(). Pass Eloquent models so the rule
        // resolves the correct table + connection (office financial DB for Project/Fund/COA).
        // DonorExpenditureCode stays on the default (central) connection.
        return [
            'project' => Rule::exists(Project::class, 'id')->where('organization_id', $orgId),
            'fund' => Rule::exists(Fund::class, 'id')->where('organization_id', $orgId),
            'chart_account' => Rule::exists(ChartOfAccount::class, 'id')->where('organization_id', $orgId),
            'donor_expenditure' => Rule::exists(DonorExpenditureCode::class, 'id')->where('organization_id', $orgId),
        ];
    }

    /**
     * Display a listing of vouchers.
     */
    public function index(Request $request)
    {
        $query = Voucher::where('organization_id', $request->user()->organization_id)
            ->with(['office:id,name,code', 'project:id,project_name', 'creator:id,name']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('voucher_type')) {
            $query->where('voucher_type', $request->voucher_type);
        }

        // Filter by office
        if ($request->has('office_id')) {
            $query->where('office_id', $request->office_id);
        }

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by journal book (GL journal_id on voucher header)
        if ($request->filled('journal_id')) {
            $query->where('journal_id', (int) $request->journal_id);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('voucher_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('voucher_date', '<=', $request->to_date);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('voucher_number', 'like', "%{$search}%")
                  ->orWhere('payee_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sort (whitelist — invalid column/order caused SQL errors / 500)
        $allowedSort = [
            'id',
            'voucher_date',
            'voucher_number',
            'voucher_type',
            'payee_name',
            'total_amount',
            'base_currency_amount',
            'status',
            'currency',
            'created_at',
            'updated_at',
        ];
        $sortBy = $request->input('sort_by', 'voucher_date');
        if (! is_string($sortBy) || ! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'voucher_date';
        }
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir);

        $perPage = min((int) $request->input('per_page', 15), 100);
        $vouchers = $query->paginate($perPage);

        return $this->paginated($vouchers);
    }

    /**
     * Return full Coding Block options and format spec for voucher number.
     * Optional query: location_code (1, 2, 3) to get options for that location (main vs sub office).
     */
    public function codingBlockOptions(Request $request)
    {
        $org = $request->user()->organization;
        $locationCode = $request->query('location_code');
        return $this->success([
            'format' => CodingBlockVoucherNumberService::getFormatSpec($org, $locationCode),
            'provinces' => CodingBlockVoucherNumberService::getProvinces($org, $locationCode),
            'locations' => CodingBlockVoucherNumberService::getLocations($org, $locationCode),
        ]);
    }

    /**
     * Get coding block config for settings: suggested, current (legacy or by_location), location_options, format_spec.
     */
    public function codingBlockConfig(Request $request)
    {
        $org = $request->user()->organization;
        $suggested = CodingBlockVoucherNumberService::getSuggestedConfig();
        $raw = $org && is_array($org->coding_block_config ?? null) ? $org->coding_block_config : null;
        $current = null;
        if ($raw) {
            if (! empty($raw['by_location']) && is_array($raw['by_location'])) {
                $current = ['by_location' => $raw['by_location']];
            } elseif (! empty($raw['provinces']) || ! empty($raw['locations']) || ! empty($raw['month_codes'])) {
                $current = [
                    'provinces' => $raw['provinces'] ?? [],
                    'locations' => $raw['locations'] ?? [],
                    'month_codes' => $raw['month_codes'] ?? [],
                ];
            }
        }
        return $this->success([
            'suggested' => $suggested,
            'current' => $current,
            'location_options' => CodingBlockVoucherNumberService::getLocationOptions(),
            'format_spec' => CodingBlockVoucherNumberService::getFormatSpec($org),
            'sample_voucher_numbers' => CodingBlockVoucherNumberService::getSampleVoucherNumbersByLocation($org),
        ]);
    }

    /**
     * Update organization's coding block config.
     * - use_suggested: true → clear config (all locations use suggested).
     * - by_location: { "1": {...}, "2": {...}, "3": {...} } → per-location config. Optional apply_main_to_sub_offices: true copies main (1) to sub offices.
     * - Legacy: provinces, locations, month_codes (no by_location) → single config for all locations.
     */
    public function updateCodingBlockConfig(Request $request)
    {
        $org = $request->user()->organization;
        if (! $org) {
            return $this->error('Organization not found', 404);
        }
        if ($request->boolean('use_suggested')) {
            $org->coding_block_config = null;
            $org->save();
            return $this->success([
                'coding_block_config' => null,
                'message' => 'Using suggested coding block for all locations.',
            ]);
        }

        if ($request->has('by_location') && is_array($request->input('by_location'))) {
            $validated = $request->validate([
                'by_location' => 'required|array',
                'by_location.*' => 'array',
                'by_location.*.provinces' => 'required|array|min:1',
                'by_location.*.provinces.*.name' => 'required|string|max:255',
                'by_location.*.provinces.*.code' => 'required|string|max:10',
                'by_location.*.locations' => 'required|array|min:1',
                'by_location.*.locations.*.name' => 'required|string|max:255',
                'by_location.*.locations.*.code' => 'required|string|max:10',
                'by_location.*.month_codes' => 'required|array',
                'by_location.*.month_codes.1' => 'required|string|max:5',
                'by_location.*.month_codes.2' => 'required|string|max:5',
                'by_location.*.month_codes.3' => 'required|string|max:5',
                'by_location.*.month_codes.4' => 'required|string|max:5',
                'by_location.*.month_codes.5' => 'required|string|max:5',
                'by_location.*.month_codes.6' => 'required|string|max:5',
                'by_location.*.month_codes.7' => 'required|string|max:5',
                'by_location.*.month_codes.8' => 'required|string|max:5',
                'by_location.*.month_codes.9' => 'required|string|max:5',
                'by_location.*.month_codes.10' => 'required|string|max:5',
                'by_location.*.month_codes.11' => 'required|string|max:5',
                'by_location.*.month_codes.12' => 'required|string|max:5',
                'apply_main_to_sub_offices' => 'boolean',
            ]);
            $byLocation = $validated['by_location'];
            $applyMainToSub = $request->boolean('apply_main_to_sub_offices');
            $locationOptions = CodingBlockVoucherNumberService::getLocationOptions();
            $subOfficeCodes = array_values(array_filter(array_column($locationOptions, 'code'), fn ($c) => $c !== '1'));
            if ($applyMainToSub && isset($byLocation['1'])) {
                $mainConfig = $byLocation['1'];
                foreach ($subOfficeCodes as $code) {
                    $byLocation[$code] = $mainConfig;
                }
            }
            $org->coding_block_config = ['by_location' => $byLocation];
            $org->save();
            return $this->success([
                'coding_block_config' => $org->coding_block_config,
                'message' => $applyMainToSub ? 'Coding block saved. Main office config applied to all sub offices.' : 'Coding block config saved.',
            ]);
        }

        $validated = $request->validate([
            'provinces' => 'required|array|min:1',
            'provinces.*.name' => 'required|string|max:255',
            'provinces.*.code' => 'required|string|max:10',
            'locations' => 'required|array|min:1',
            'locations.*.name' => 'required|string|max:255',
            'locations.*.code' => 'required|string|max:10',
            'month_codes' => 'required|array',
            'month_codes.1' => 'required|string|max:5',
            'month_codes.2' => 'required|string|max:5',
            'month_codes.3' => 'required|string|max:5',
            'month_codes.4' => 'required|string|max:5',
            'month_codes.5' => 'required|string|max:5',
            'month_codes.6' => 'required|string|max:5',
            'month_codes.7' => 'required|string|max:5',
            'month_codes.8' => 'required|string|max:5',
            'month_codes.9' => 'required|string|max:5',
            'month_codes.10' => 'required|string|max:5',
            'month_codes.11' => 'required|string|max:5',
            'month_codes.12' => 'required|string|max:5',
        ]);
        $org->coding_block_config = [
            'provinces' => $validated['provinces'],
            'locations' => $validated['locations'],
            'month_codes' => $validated['month_codes'],
        ];
        $org->save();
        return $this->success([
            'coding_block_config' => $org->coding_block_config,
            'message' => 'Coding block config saved.',
        ]);
    }

    /**
     * Preview next voucher number. When project_id is set, uses Coding Block (province/location from office).
     * Query: project_id (optional), voucher_date, office_id (optional), voucher_type (optional).
     */
    public function nextNumberPreview(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'nullable|integer',
            'voucher_date' => 'nullable|date',
            'office_id' => 'nullable|exists:offices,id',
            'voucher_type' => 'nullable|string|in:payment,receipt,journal,contra',
        ]);

        $organization = $request->user()->organization;
        if (! $organization) {
            return $this->error('Organization not found', 404);
        }

        $voucherType = $validated['voucher_type'] ?? 'payment';

        if (empty($validated['project_id'])) {
            $next = $organization->getNextVoucherNumber($voucherType);
            return $this->success(['next_voucher_number' => $next]);
        }

        $officeId = $validated['office_id'] ?? $request->user()->office_id;
        if (! $officeId) {
            $office = Office::where('organization_id', $organization->id)->first();
            $officeId = $office?->id;
        }
        if (! $officeId || empty($validated['voucher_date'])) {
            return $this->success(['next_voucher_number' => null]);
        }

        $office = Office::where('id', $officeId)->where('organization_id', $organization->id)->first();
        if (! $office) {
            return $this->success(['next_voucher_number' => null]);
        }

        [$provinceCode, $locationCode] = $this->getProvinceAndLocationFromOffice($office, $organization);
        if (! $provinceCode || ! $locationCode) {
            return $this->success(['next_voucher_number' => null]);
        }

        $next = OfficeContext::runWithOffice($office, function () use ($organization, $validated, $provinceCode, $locationCode) {
            $codingBlock = app(CodingBlockVoucherNumberService::class);
            return $codingBlock->getNextNumber(
                (int) $organization->id,
                (int) $validated['project_id'],
                $provinceCode,
                (string) $validated['voucher_date'],
                $locationCode,
                $organization
            );
        });

        return $this->success(['next_voucher_number' => $next]);
    }

    /**
     * Check if a voucher number is available (unique). Used for inline validation before submit.
     * Query: voucher_number (required), office_id (required), exclude_id (optional, for edit mode).
     */
    public function checkVoucherNumber(Request $request)
    {
        $validated = $request->validate([
            'voucher_number' => 'nullable|string|max:100',
            'office_id' => 'required|exists:offices,id',
            'exclude_id' => 'nullable|integer|min:1',
        ]);

        $organization = $request->user()->organization;
        if (! $organization) {
            return $this->error('Organization not found', 404);
        }

        $office = Office::where('id', $validated['office_id'])
            ->where('organization_id', $organization->id)
            ->first();
        if (! $office) {
            return $this->error('Office not found', 404);
        }

        $number = trim((string) $validated['voucher_number']);
        if ($number === '') {
            return $this->success(['available' => true]);
        }

        $exists = OfficeContext::runWithOffice($office, function () use ($number, $validated) {
            $query = Voucher::where('voucher_number', $number);
            if (! empty($validated['exclude_id'])) {
                $query->where('id', '!=', (int) $validated['exclude_id']);
            }
            return $query->exists();
        });

        return $this->success(['available' => ! $exists]);
    }

    /**
     * Store a newly created voucher.
     * Uses organization configuration: voucher numbering, default currency, mandatory fields.
     * When project_id + province_code + location_code are provided, uses Coding Block voucher number.
     */
    public function store(Request $request)
    {
        $organization = $request->user()->organization;
        if (! $organization) {
            return $this->error('Organization not found', 404);
        }

        $officeId = $request->input('office_id');
        if (! $officeId) {
            return $this->error('Office is required.', 400);
        }
        $office = Office::where('id', $officeId)
            ->where('organization_id', $request->user()->organization_id)
            ->first();
        if (! $office) {
            return $this->error('Selected office not found.', 404);
        }

        return OfficeContext::runWithOffice($office, function () use ($request, $organization, $office) {
            // Must run inside office context: Fund / defaults live on the office financial DB.
            $this->mergeVoucherDefaultsFromUser($request, $organization, $office);

            if ($request->filled('currency')) {
                $request->merge(['currency' => strtoupper(trim((string) $request->input('currency')))]);
            }

            $ex = $this->voucherOfficeExistsRules($request);
            $rules = [
                'office_id' => 'required|exists:offices,id',
                'project_id' => [
                    ($organization->project_mandatory ?? true) ? 'required' : 'nullable',
                    $ex['project'],
                ],
                'province_code' => [Rule::requiredIf(fn () => $request->filled('project_id')), 'nullable', 'string', 'size:2'],
                'location_code' => [Rule::requiredIf(fn () => $request->filled('project_id')), 'nullable', 'string', Rule::in(['1', '2', '3'])],
                'fund_id' => [
                    ($organization->fund_mandatory ?? true) ? 'required' : 'nullable',
                    $ex['fund'],
                ],
                'voucher_type' => 'required|in:payment,receipt,journal,contra',
                'voucher_date' => 'required|date',
                'payee_name' => 'nullable|string|max:255',
                'description' => 'required|string',
                'currency' => ['nullable', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
                'exchange_rate' => 'nullable|numeric|min:0',
                'voucher_number' => 'nullable|string|max:100',
                'payment_method' => 'nullable|in:cash,check,bank_transfer,mobile_money,msp',
                'check_number' => 'nullable|string|max:50',
                'bank_reference' => 'nullable|string|max:255',
                'lines' => 'required|array|min:2',
                'lines.*.account_id' => ['required', $ex['chart_account']],
                'lines.*.fund_id' => ['nullable', $ex['fund']],
                'lines.*.project_id' => ['nullable', $ex['project']],
                'lines.*.donor_expenditure_code_id' => ['nullable', $ex['donor_expenditure']],
                'lines.*.description' => 'nullable|string',
                'lines.*.debit_amount' => 'nullable|numeric',
                'lines.*.credit_amount' => 'nullable|numeric',
                'lines.*.cost_center' => ($organization->cost_center_mandatory ?? false) ? 'required|string|max:255' : 'nullable|string|max:255',
                'lines.*.project_account_code' => 'nullable|string|max:50',
                'journal_id' => 'nullable|integer|min:1',
            ];

            $validated = $request->validate($rules);

            if (! empty($validated['journal_id'])) {
                $this->assertJournalMatchesVoucherOrFail(
                    $request,
                    (int) $validated['journal_id'],
                    (int) $validated['office_id'],
                    isset($validated['project_id']) ? (int) $validated['project_id'] : null
                );
            } else {
                unset($validated['journal_id']);
            }

            if (empty($validated['currency'])) {
                $validated['currency'] = $organization->default_currency ?? 'USD';
            }

            $validated['exchange_rate'] = (float) ($validated['exchange_rate'] ?? 1);
            if ($validated['exchange_rate'] < 0.000001) {
                $validated['exchange_rate'] = 1.0;
            }

            $totalDebit = (float) collect($validated['lines'])->sum('debit_amount');
            $totalCredit = (float) collect($validated['lines'])->sum('credit_amount');
            $td = number_format($totalDebit, 2, '.', '');
            $tc = number_format($totalCredit, 2, '.', '');
            if (bccomp($td, $tc, 2) !== 0) {
                return $this->error('Voucher is not balanced. Total debits must equal total credits.', 400);
            }

            $validated['organization_id'] = $request->user()->organization_id;
            $validated['total_amount'] = $totalDebit;
            $validated['base_currency_amount'] = $totalDebit * (float) ($validated['exchange_rate'] ?? 1);
            $validated['created_by'] = $request->user()->id;
            $validated['status'] = 'draft';

            $customVoucherNumber = isset($validated['voucher_number']) ? trim((string) $validated['voucher_number']) : '';
            $useCustomNumber = $customVoucherNumber !== '';
            $useCodingBlock = ! empty($validated['project_id'])
                && ! empty($validated['province_code'])
                && ! empty($validated['location_code']);

            if ($useCustomNumber) {
                if (Voucher::where('voucher_number', $customVoucherNumber)->exists()) {
                    return $this->error('This voucher number is already used. Please choose a unique number.', 422);
                }
                $validated['voucher_number'] = $customVoucherNumber;
            } else {
                if ($useCodingBlock) {
                    try {
                        $codingBlock = app(CodingBlockVoucherNumberService::class);
                        $validated['voucher_number'] = $codingBlock->getNextNumber(
                            (int) $validated['organization_id'],
                            (int) $validated['project_id'],
                            (string) $validated['province_code'],
                            (string) $validated['voucher_date'],
                            (string) $validated['location_code'],
                            $organization
                        );
                    } catch (\InvalidArgumentException $e) {
                        return $this->error($e->getMessage(), 422);
                    }
                } else {
                    try {
                        $validated['voucher_number'] = $organization->getNextVoucherNumber($validated['voucher_type']);
                    } catch (\Throwable $e) {
                        Log::error('Voucher number generation failed', [
                            'message' => $e->getMessage(),
                            'type' => $validated['voucher_type'] ?? null,
                        ]);

                        return $this->error(
                            'Could not generate the next voucher number. Check organization voucher settings and database updates.',
                            422
                        );
                    }
                }
            }

            // Financial voucher + lines live on the office DB connection. The default DB::transaction()
            // only wraps the default connection — use the same connection as Voucher / VoucherLine.
            $conn = OfficeContext::connection();
            $voucherFillable = array_flip((new Voucher)->getFillable());
            $voucherAttrs = array_intersect_key(collect($validated)->except('lines')->all(), $voucherFillable);

            try {
                $voucher = DB::connection($conn)->transaction(function () use ($validated, $conn, $voucherAttrs) {
                    $voucher = Voucher::create($voucherAttrs);

                    $now = now();
                    $rows = [];
                    $lineNumber = 1;
                    foreach ($validated['lines'] as $line) {
                        $rows[] = [
                            'voucher_id' => $voucher->id,
                            'line_number' => $lineNumber++,
                            'account_id' => $line['account_id'],
                            'fund_id' => $line['fund_id'] ?? $validated['fund_id'],
                            'project_id' => $line['project_id'] ?? $validated['project_id'],
                            'donor_expenditure_code_id' => $line['donor_expenditure_code_id'] ?? null,
                            'description' => $line['description'] ?? $validated['description'],
                            'debit_amount' => number_format((float) ($line['debit_amount'] ?? 0), 2, '.', ''),
                            'credit_amount' => number_format((float) ($line['credit_amount'] ?? 0), 2, '.', ''),
                            'cost_center' => $line['cost_center'] ?? null,
                            'project_account_code' => $line['project_account_code'] ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($rows !== []) {
                        VoucherLine::on($conn)->insert($rows);
                    }

                    return $voucher;
                });

                // Org sequence lives on the central DB — only bump after voucher + lines committed.
                if (! $useCustomNumber && ! $useCodingBlock) {
                    $organization->refresh();
                    $organization->incrementVoucherNumber($validated['voucher_type']);
                }

                try {
                    $voucher->load(['lines.account', 'lines.donorExpenditureCode', 'office', 'project.costCenter', 'fund']);
                } catch (\Throwable $e) {
                    Log::warning('Voucher created; partial eager load failed', [
                        'voucher_id' => $voucher->id,
                        'message' => $e->getMessage(),
                    ]);
                    $voucher->load(['lines']);
                }

                return $this->success($voucher, 'Voucher created successfully', 201);
            } catch (QueryException $e) {
                $sqlMsg = $e->getMessage();
                Log::error('Voucher store failed (database)', [
                    'message' => $sqlMsg,
                    'code' => $e->getCode(),
                    'connection' => $conn,
                ]);

                if (str_contains($sqlMsg, 'Duplicate') && str_contains($sqlMsg, 'voucher_number')) {
                    return $this->error('This voucher number is already in use. Save again to get the next number.', 422);
                }
                if (str_contains($sqlMsg, 'Unknown column') || str_contains($sqlMsg, "doesn't exist")) {
                    return $this->error(
                        'The financial database is missing a required update. Ask your administrator to run migrations (including office financial migrations if your office uses a separate database).',
                        503
                    );
                }
                if (str_contains($sqlMsg, 'Data truncated') || (str_contains($sqlMsg, 'Incorrect') && str_contains($sqlMsg, 'value'))) {
                    return $this->error(
                        'A field value does not match the database (for example payment method or voucher number length). Check data or run the latest migrations.',
                        422
                    );
                }

                return $this->error(
                    'Could not save the voucher to the database. If this continues, contact support with the time of the error.',
                    500
                );
            } catch (\Throwable $e) {
                Log::error('Voucher store failed', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return $this->error('Failed to create voucher: ' . $e->getMessage(), 500);
            }
        });
    }

    /**
     * Display the specified voucher.
     */
    public function show(Request $request, Voucher $voucher)
    {
        if ($voucher->organization_id !== $request->user()->organization_id) {
            return $this->error('Voucher not found', 404);
        }

        $voucher->load([
            'lines.account',
            'lines.fund',
            'lines.project',
            'lines.donorExpenditureCode',
            'office',
            'project.costCenter',
            'fund',
            'creator',
            'submitter',
            'approvals.approver',
            'journalEntry',
            'journal',
            'documents',
        ]);

        return $this->success($voucher);
    }

    /**
     * Update the specified voucher.
     */
    public function update(Request $request, Voucher $voucher)
    {
        if ($voucher->organization_id !== $request->user()->organization_id) {
            return $this->error('Voucher not found', 404);
        }

        if (!$voucher->canBeEdited()) {
            return $this->error('Voucher cannot be edited in current status', 400);
        }

        $organization = $request->user()->organization;
        $office = $voucher->office ?? Office::where('id', $voucher->office_id)->where('organization_id', $organization->id)->first();
        if (! $office) {
            return $this->error('Office not found for this voucher.', 400);
        }

        return OfficeContext::runWithOffice($office, function () use ($request, $organization, $office, $voucher) {
            $this->mergeVoucherDefaultsFromUser($request, $organization, $office);

            $voucher = Voucher::query()
                ->where('organization_id', $organization->id)
                ->whereKey($voucher->getKey())
                ->firstOrFail();

            $ex = $this->voucherOfficeExistsRules($request);
            $conn = OfficeContext::connection();

            $validated = $request->validate([
                'project_id' => ['nullable', $ex['project']],
                'province_code' => [Rule::requiredIf(fn () => $request->filled('project_id')), 'nullable', 'string', 'size:2'],
                'location_code' => [Rule::requiredIf(fn () => $request->filled('project_id')), 'nullable', 'string', Rule::in(['1', '2', '3'])],
                'fund_id' => ['nullable', $ex['fund']],
                'voucher_number' => 'nullable|string|max:100',
                'voucher_date' => 'sometimes|date',
                'payee_name' => 'nullable|string|max:255',
                'description' => 'sometimes|string',
                'currency' => ['sometimes', 'string', 'size:3', Rule::in(Organization::getActiveCurrencyCodesForOrg($request->user()->organization_id))],
                'exchange_rate' => 'nullable|numeric|min:0',
                'payment_method' => 'nullable|in:cash,check,bank_transfer,mobile_money,msp',
                'check_number' => 'nullable|string|max:50',
                'bank_reference' => 'nullable|string|max:255',
                'lines' => 'sometimes|array|min:2',
                'lines.*.id' => [
                    'nullable',
                    Rule::exists('voucher_lines', 'id')->usingConnection($conn)->where('voucher_id', $voucher->id),
                ],
                'lines.*.account_id' => ['required', $ex['chart_account']],
                'lines.*.fund_id' => ['nullable', $ex['fund']],
                'lines.*.project_id' => ['nullable', $ex['project']],
                'lines.*.donor_expenditure_code_id' => ['nullable', $ex['donor_expenditure']],
                'lines.*.description' => 'nullable|string',
                'lines.*.debit_amount' => 'nullable|numeric',
                'lines.*.credit_amount' => 'nullable|numeric',
                'lines.*.cost_center' => 'nullable|string|max:255',
                'lines.*.project_account_code' => 'nullable|string|max:50',
                'journal_id' => 'sometimes|nullable|integer|min:1',
            ]);

            if (array_key_exists('journal_id', $validated) && $validated['journal_id'] !== null) {
                $this->assertJournalMatchesVoucherOrFail(
                    $request,
                    (int) $validated['journal_id'],
                    (int) ($validated['office_id'] ?? $voucher->office_id),
                    isset($validated['project_id']) ? (int) $validated['project_id'] : ($voucher->project_id ? (int) $voucher->project_id : null)
                );
            }

            $customVoucherNumber = isset($validated['voucher_number']) ? trim((string) $validated['voucher_number']) : null;
            if ($customVoucherNumber !== null && $customVoucherNumber !== '') {
                $existing = Voucher::where('voucher_number', $customVoucherNumber)->where('id', '!=', $voucher->id)->exists();
                if ($existing) {
                    return $this->error('This voucher number is already used. Please choose a unique number.', 422);
                }
            }

            DB::beginTransaction();

            try {
                // Update voucher (only set voucher_number if provided and non-empty)
                if ($customVoucherNumber !== null && $customVoucherNumber !== '') {
                    $voucher->voucher_number = $customVoucherNumber;
                }
                $voucher->fill(collect($validated)->except('voucher_number')->all());

                // If lines are provided, update them
                if (isset($validated['lines'])) {
                    $totalDebit = collect($validated['lines'])->sum('debit_amount');
                    $totalCredit = collect($validated['lines'])->sum('credit_amount');

                    if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
                        return $this->error('Voucher is not balanced', 400);
                    }

                    $voucher->total_amount = $totalDebit;
                    $voucher->base_currency_amount = $totalDebit * ($voucher->exchange_rate ?? 1);

                    // Delete existing lines and recreate
                    $voucher->lines()->delete();

                    $lineNumber = 1;
                    foreach ($validated['lines'] as $line) {
                        $voucher->lines()->create([
                            'line_number' => $lineNumber++,
                            'account_id' => $line['account_id'],
                            'fund_id' => $line['fund_id'] ?? $validated['fund_id'] ?? $voucher->fund_id,
                            'project_id' => $line['project_id'] ?? $validated['project_id'] ?? $voucher->project_id,
                            'donor_expenditure_code_id' => $line['donor_expenditure_code_id'] ?? null,
                            'description' => $line['description'] ?? $voucher->description,
                            'debit_amount' => $line['debit_amount'] ?? 0,
                            'credit_amount' => $line['credit_amount'] ?? 0,
                            'cost_center' => $line['cost_center'] ?? null,
                            'project_account_code' => $line['project_account_code'] ?? null,
                        ]);
                    }
                }

                $voucher->save();

                DB::commit();

                $voucher->load(['lines.account', 'lines.donorExpenditureCode', 'office', 'project.costCenter', 'fund']);

                return $this->success($voucher, 'Voucher updated successfully');
            } catch (\Exception $e) {
                DB::rollBack();

                return $this->error('Failed to update voucher: ' . $e->getMessage(), 500);
            }
        });
    }

    /**
     * Remove the specified voucher.
     */
    public function destroy(Request $request, Voucher $voucher)
    {
        if ($voucher->organization_id !== $request->user()->organization_id) {
            return $this->error('Voucher not found', 404);
        }

        if ($voucher->status !== 'draft') {
            return $this->error('Only draft vouchers can be deleted', 400);
        }

        $voucher->delete();

        return $this->success(null, 'Voucher deleted successfully');
    }

    /**
     * Submit voucher for approval.
     */
    public function submit(Request $request, Voucher $voucher)
    {
        if ($voucher->organization_id !== $request->user()->organization_id) {
            return $this->error('Voucher not found', 404);
        }

        if (!$voucher->canBeSubmitted()) {
            return $this->error('Voucher cannot be submitted', 400);
        }

        $voucher->submit($request->user());
        $voucher->refresh();
        $this->notifyApproversAfterVoucherSubmit($voucher, $request->user());

        return $this->success($voucher, 'Voucher submitted for approval');
    }

    /**
     * Approve voucher.
     */
    public function approve(Request $request, Voucher $voucher)
    {
        if ($voucher->organization_id !== $request->user()->organization_id) {
            return $this->error('Voucher not found', 404);
        }

        if (!$voucher->canBeApproved()) {
            return $this->error('Voucher cannot be approved', 400);
        }

        $user = $request->user();

        $signError = $this->assertUserCanSignPendingVoucher($user, $voucher);
        if ($signError !== null) {
            return $this->error($signError, 403);
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();

        try {
            $voucher->approve($user, $validated['comments'] ?? null);

            // If fully approved, create journal entry
            if ($voucher->isApproved()) {
                $this->createJournalEntry($voucher);
            }

            DB::commit();

            $voucher->load(['approvals.approver']);
            $voucher->refresh();
            $this->notifyAfterVoucherApprove($voucher, $user);

            return $this->success($voucher, 'Voucher approved successfully');

        } catch (\InvalidArgumentException $e) {
            DB::rollBack();

            return $this->error($e->getMessage(), 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to approve voucher: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Reject voucher.
     */
    public function reject(Request $request, Voucher $voucher)
    {
        if ($voucher->organization_id !== $request->user()->organization_id) {
            return $this->error('Voucher not found', 404);
        }

        if (!$voucher->canBeApproved()) {
            return $this->error('Voucher cannot be rejected', 400);
        }

        $user = $request->user();
        $signError = $this->assertUserCanSignPendingVoucher($user, $voucher);
        if ($signError !== null) {
            return $this->error($signError, 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $voucher->reject($user, $validated['reason']);
        $voucher->refresh();
        $this->notifyAfterVoucherReject($voucher, $user);

        return $this->success($voucher, 'Voucher rejected');
    }

    /**
     * Get approval history.
     */
    public function approvalHistory(Request $request, Voucher $voucher)
    {
        if ($voucher->organization_id !== $request->user()->organization_id) {
            return $this->error('Voucher not found', 404);
        }

        $approvals = $voucher->approvals()
            ->with('approver:id,name,position')
            ->orderBy('approval_level')
            ->get()
            ->map(function ($approval) {
                return [
                    'level' => $approval->approval_level,
                    'level_name' => $approval->getLevelName(),
                    'action' => $approval->action,
                    'approver' => $approval->approver,
                    'comments' => $approval->comments,
                    'action_at' => $approval->action_at?->toIso8601String(),
                ];
            });

        return $this->success($approvals);
    }

    /**
     * Whether the user may approve or reject at the current pending step (same rules for both actions).
     */
    private function assertUserCanSignPendingVoucher(User $user, Voucher $voucher): ?string
    {
        if (! $voucher->canBeApproved()) {
            return 'This voucher is not awaiting approval.';
        }

        if ($this->userIsSuperAdministrator($user)) {
            return null;
        }

        if (($user->approval_level ?? 0) <= $voucher->current_approval_level) {
            $next = $voucher->getNextApprovalLevel();
            $roleName = config('erp.approval.roles')[$next] ?? 'Level '.$next;

            return 'You cannot sign this step. The next required approver is '.$roleName.' (step '.$next.'). '
                .'Your approval level must be higher than the last completed level (currently '.$voucher->current_approval_level.').';
        }

        if (! $user->canApproveAmount((float) $voucher->base_currency_amount)) {
            return 'This voucher amount (base currency) exceeds your personal approval limit.';
        }

        return null;
    }

    /**
     * Super Administrator may approve/reject at any layer (same as Gate::before for super-admin).
     * Uses direct role lookup so it still works if hasRole() cache/guard behaves differently on API tokens.
     */
    private function userIsSuperAdministrator(User $user): bool
    {
        if ($user->hasRole('super-admin')) {
            return true;
        }

        $user->loadMissing('roles');

        return $user->roles->contains('name', 'super-admin');
    }

    /**
     * Generate voucher number.
     */
    private function generateVoucherNumber(int $organizationId, string $type): string
    {
        $prefix = strtoupper(substr($type, 0, 2));
        $year = now()->format('Y');

        $lastVoucher = Voucher::where('organization_id', $organizationId)
            ->where('voucher_number', 'like', "{$prefix}-{$year}-%")
            ->orderBy('voucher_number', 'desc')
            ->first();

        if ($lastVoucher) {
            $lastNumber = (int) substr($lastVoucher->voucher_number, -6);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('%s-%s-%06d', $prefix, $year, $newNumber);
    }

    /**
     * Find or create a postable fiscal period for the voucher date on the current office connection.
     * Provisioned office databases often had empty fiscal_* tables; GL UI may target another connection.
     */
    private function resolveFiscalPeriodForVoucherPosting(int $organizationId, string $voucherDateYmd): FiscalPeriod
    {
        $blockingYear = FiscalYear::query()
            ->where('organization_id', $organizationId)
            ->whereDate('start_date', '<=', $voucherDateYmd)
            ->whereDate('end_date', '>=', $voucherDateYmd)
            ->whereIn('status', ['closed', 'locked'])
            ->first();
        if ($blockingYear) {
            throw new \InvalidArgumentException(
                'The fiscal year that contains this voucher date is closed or locked. Adjust the voucher date or reopen the fiscal year under General Ledger → Fiscal years.'
            );
        }

        $fiscalPeriod = FiscalPeriod::query()
            ->whereHas('fiscalYear', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId)
                    ->whereIn('status', ['open', 'draft']);
            })
            ->whereIn('status', ['open', 'draft'])
            ->whereDate('start_date', '<=', $voucherDateYmd)
            ->whereDate('end_date', '>=', $voucherDateYmd)
            ->orderByRaw("CASE WHEN fiscal_periods.status = 'open' THEN 0 WHEN fiscal_periods.status = 'draft' THEN 1 ELSE 2 END")
            ->orderBy('fiscal_periods.id')
            ->first();

        if ($fiscalPeriod) {
            return $fiscalPeriod;
        }

        return $this->ensureFiscalPeriodExistsForPosting($organizationId, $voucherDateYmd);
    }

    /**
     * When no period row matches: attach to an open FY or create calendar FY + month period (same connection as voucher).
     */
    private function ensureFiscalPeriodExistsForPosting(int $organizationId, string $voucherDateYmd): FiscalPeriod
    {
        $d = Carbon::parse($voucherDateYmd)->startOfDay();

        $fy = FiscalYear::query()
            ->where('organization_id', $organizationId)
            ->whereDate('start_date', '<=', $voucherDateYmd)
            ->whereDate('end_date', '>=', $voucherDateYmd)
            ->whereIn('status', ['open', 'draft'])
            ->orderBy('id')
            ->first();

        if ($fy) {
            $existing = FiscalPeriod::query()
                ->where('fiscal_year_id', $fy->id)
                ->whereDate('start_date', '<=', $voucherDateYmd)
                ->whereDate('end_date', '>=', $voucherDateYmd)
                ->orderBy('id')
                ->first();

            if ($existing) {
                if (in_array($existing->status, ['closed', 'locked'], true)) {
                    throw new \InvalidArgumentException(
                        'The fiscal period for this voucher date is closed. Reopen it under General Ledger → Fiscal years, or change the voucher date.'
                    );
                }
                if (in_array($existing->status, ['open', 'draft'], true)) {
                    return $existing;
                }
            }

            return $this->createOpenCalendarMonthPeriodInsideYear($fy, $d);
        }

        $year = (int) $d->format('Y');
        $startStr = sprintf('%d-01-01', $year);
        $endStr = sprintf('%d-12-31', $year);

        if (FiscalYear::query()
            ->where('organization_id', $organizationId)
            ->whereDate('start_date', '<=', $endStr)
            ->whereDate('end_date', '>=', $startStr)
            ->exists()) {
            throw new \InvalidArgumentException(
                'No postable fiscal period for this date. A fiscal year overlaps this calendar year but does not cover this date, or periods are missing. Complete setup under General Ledger → Fiscal years.'
            );
        }

        $name = 'FY '.$year;
        if (FiscalYear::query()->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            $name = 'FY '.$year.' auto';
        }

        $fy = FiscalYear::query()->create([
            'organization_id' => $organizationId,
            'name' => substr($name, 0, 50),
            'start_date' => $startStr,
            'end_date' => $endStr,
            'status' => 'open',
            'is_current' => true,
        ]);
        FiscalYear::query()
            ->where('organization_id', $organizationId)
            ->where('id', '!=', $fy->id)
            ->update(['is_current' => false]);

        Cache::forget("fiscal_years_{$organizationId}_all");
        Cache::forget("fiscal_years_{$organizationId}_current");

        return $this->createOpenCalendarMonthPeriodInsideYear($fy, $d);
    }

    private function createOpenCalendarMonthPeriodInsideYear(FiscalYear $fy, Carbon $d): FiscalPeriod
    {
        $monthStart = $d->copy()->startOfMonth();
        $monthEnd = $d->copy()->endOfMonth();
        $fyStart = Carbon::parse($fy->start_date)->startOfDay();
        $fyEnd = Carbon::parse($fy->end_date)->endOfDay();
        $start = $monthStart->lt($fyStart) ? $fyStart->copy() : $monthStart->copy();
        $end = $monthEnd->gt($fyEnd) ? $fyEnd->copy() : $monthEnd->copy();

        $existing = FiscalPeriod::query()
            ->where('fiscal_year_id', $fy->id)
            ->whereDate('start_date', '<=', $d->format('Y-m-d'))
            ->whereDate('end_date', '>=', $d->format('Y-m-d'))
            ->first();
        if ($existing) {
            if (in_array($existing->status, ['open', 'draft'], true)) {
                return $existing;
            }
            throw new \InvalidArgumentException(
                'A fiscal period exists for this date but is not open. Reopen it under General Ledger → Fiscal years.'
            );
        }

        $nextNumber = (int) FiscalPeriod::query()->where('fiscal_year_id', $fy->id)->max('period_number');

        return FiscalPeriod::query()->create([
            'fiscal_year_id' => $fy->id,
            'name' => substr($start->format('F Y'), 0, 50),
            'period_number' => $nextNumber + 1,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'status' => 'open',
            'is_adjustment_period' => false,
        ]);
    }

    /**
     * Create journal entry from approved voucher.
     *
     * Fiscal years/periods, vouchers, and journal entries all live on the voucher office’s financial
     * connection (see UsesOfficeConnection). Middleware may set a different X-Office-Id, so we must
     * scope posting to the voucher’s office — otherwise the fiscal period query hits the wrong DB and fails.
     */
    private function createJournalEntry(Voucher $voucher): void
    {
        $office = Office::query()
            ->where('organization_id', $voucher->organization_id)
            ->whereKey((int) $voucher->office_id)
            ->first();
        if (! $office) {
            throw new \RuntimeException('Office not found for this voucher.');
        }

        OfficeContext::runWithOffice($office, function () use ($voucher) {
            $v = Voucher::query()
                ->where('organization_id', $voucher->organization_id)
                ->whereKey($voucher->getKey())
                ->with('lines')
                ->firstOrFail();

            if ($v->lines->isEmpty()) {
                throw new \RuntimeException('Cannot post voucher with no lines.');
            }

            $voucherDate = $v->voucher_date instanceof \Carbon\Carbon
                ? $v->voucher_date->format('Y-m-d')
                : (string) $v->voucher_date;

            $fiscalPeriod = $this->resolveFiscalPeriodForVoucherPosting((int) $v->organization_id, $voucherDate);

            $projectIds = $v->lines->pluck('project_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
            if ($v->project_id) {
                $projectIds[] = (int) $v->project_id;
            }
            app(ProjectFiscalPeriodPostingService::class)->assertProjectsOpenForPosting($fiscalPeriod, $projectIds);

            $jeNumber = $this->generateJournalEntryNumber($v->organization_id);

            $journalEntry = JournalEntry::create([
                'organization_id' => $v->organization_id,
                'journal_id' => $v->journal_id,
                'office_id' => $v->office_id,
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => $jeNumber,
                'entry_date' => $v->voucher_date,
                'posting_date' => now(),
                'entry_type' => 'standard',
                'reference' => $v->voucher_number,
                'description' => $v->description,
                'currency' => $v->currency,
                'exchange_rate' => $v->exchange_rate,
                'total_debit' => $v->total_amount,
                'total_credit' => $v->total_amount,
                'status' => 'posted',
                'created_by' => $v->created_by,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
                'source_type' => Voucher::class,
                'source_id' => $v->id,
            ]);

            $lineNumber = 1;
            foreach ($v->lines as $voucherLine) {
                $dimensions = ! empty($voucherLine->project_account_code)
                    ? ['project_account_code' => $voucherLine->project_account_code]
                    : [];
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $voucherLine->account_id,
                    'fund_id' => $voucherLine->fund_id,
                    'project_id' => $voucherLine->project_id,
                    'donor_expenditure_code_id' => $voucherLine->donor_expenditure_code_id,
                    'office_id' => $v->office_id,
                    'line_number' => $lineNumber++,
                    'description' => $voucherLine->description ?? $v->description,
                    'debit_amount' => $voucherLine->debit_amount,
                    'credit_amount' => $voucherLine->credit_amount,
                    'currency' => $v->currency,
                    'exchange_rate' => $v->exchange_rate,
                    'base_currency_debit' => $voucherLine->debit_amount * $v->exchange_rate,
                    'base_currency_credit' => $voucherLine->credit_amount * $v->exchange_rate,
                    'cost_center' => $voucherLine->cost_center,
                    'dimensions' => $dimensions,
                ]);
            }

            $v->update([
                'journal_entry_id' => $journalEntry->id,
                'status' => 'posted',
            ]);
        });
    }

    /**
     * Generate journal entry number.
     */
    private function generateJournalEntryNumber(int $organizationId): string
    {
        $year = now()->format('Y');
        $prefix = 'JE';

        $lastEntry = JournalEntry::where('organization_id', $organizationId)
            ->where('entry_number', 'like', "{$prefix}-{$year}-%")
            ->orderBy('entry_number', 'desc')
            ->first();

        if ($lastEntry) {
            $lastNumber = (int) substr($lastEntry->entry_number, -6);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('%s-%s-%06d', $prefix, $year, $newNumber);
    }

    /**
     * When creating/updating a voucher from a journal book, ensure the book belongs to the org,
     * the user may access it, and project/office match the voucher header.
     */
    private function assertJournalMatchesVoucherOrFail(Request $request, int $journalId, int $officeId, ?int $projectId): void
    {
        $journal = Journal::where('id', $journalId)
            ->where('organization_id', $request->user()->organization_id)
            ->whereNull('deleted_at')
            ->first();
        if (! $journal) {
            throw new HttpResponseException($this->error('Invalid journal book.', 422));
        }
        if (! app(OfficeScopeService::class)->userCanAccessJournalBook($request->user(), $journal)) {
            throw new HttpResponseException($this->error('You cannot use this journal book.', 403));
        }
        if ($journal->project_id) {
            if (! $projectId || (int) $projectId !== (int) $journal->project_id) {
                throw new HttpResponseException($this->error('Voucher project must match the selected journal book.', 422));
            }
        }
        if ($journal->office_id) {
            if ((int) $officeId !== (int) $journal->office_id) {
                throw new HttpResponseException($this->error('Voucher office must match the selected journal book.', 422));
            }
        }
    }

    /**
     * Get province_code and location_code from an office (for coding block voucher number).
     *
     * @return array{0: string|null, 1: string}
     */
    private function getProvinceAndLocationFromOffice(Office $office, Organization $organization): array
    {
        $locationCode = $office->is_head_office ? '1' : '2';
        $provinceCode = null;
        if (! empty($office->province)) {
            $province = trim($office->province);
            if (strlen($province) === 2 && ctype_digit($province)) {
                $provinceCode = $province;
            } else {
                $provinces = CodingBlockVoucherNumberService::getProvinces($organization);
                foreach ($provinces as $p) {
                    if (isset($p['name']) && strcasecmp(trim($p['name']), $province) === 0 && isset($p['code'])) {
                        $provinceCode = $p['code'];
                        break;
                    }
                }
                if ($provinceCode === null && ! empty($provinces[0]['code'] ?? null)) {
                    $provinceCode = $provinces[0]['code'];
                }
            }
        }
        return [$provinceCode, $locationCode];
    }

    /**
     * Merge province_code, location_code, and fund_id from user/office when not provided.
     * Province and location come from the office; fund defaults to first active fund when org requires it.
     */
    private function mergeVoucherDefaultsFromUser(Request $request, Organization $organization, Office $office): void
    {
        $merge = [];

        if (! $request->filled('province_code') && ! empty($office->province)) {
            [$provinceCode] = $this->getProvinceAndLocationFromOffice($office, $organization);
            if ($provinceCode !== null) {
                $merge['province_code'] = $provinceCode;
            }
        }

        if (! $request->filled('location_code')) {
            [, $locationCode] = $this->getProvinceAndLocationFromOffice($office, $organization);
            $merge['location_code'] = $locationCode;
        }

        // Match validation: fund_id is required when fund_mandatory is true or unset (default true).
        if (! $request->filled('fund_id') && ($organization->fund_mandatory ?? true)) {
            $firstFund = Fund::where('organization_id', $organization->id)
                ->where('is_active', true)
                ->orderBy('code')
                ->first();
            if ($firstFund) {
                $merge['fund_id'] = $firstFund->id;
            }
        }

        if (! empty($merge)) {
            $request->merge($merge);
        }
    }

    private function notifyApproversAfterVoucherSubmit(Voucher $voucher, User $submitter): void
    {
        $orgId = (int) $voucher->organization_id;
        $ids = $this->notificationService->approverUserIdsForLevel($orgId, 1);
        $ids = array_values(array_diff($ids, [$submitter->id]));
        if ($ids === []) {
            return;
        }
        $num = $voucher->voucher_number ?? ('#'.$voucher->id);
        $this->notificationService->notifyUsers(
            $ids,
            'approval',
            'Voucher pending approval',
            "{$num} was submitted and awaits your approval.",
            '/approvals/vouchers',
            ['voucher_id' => $voucher->id]
        );
    }

    private function notifyAfterVoucherApprove(Voucher $voucher, User $approver): void
    {
        if ($voucher->status === 'approved' && $voucher->created_by && (int) $voucher->created_by !== (int) $approver->id) {
            $num = $voucher->voucher_number ?? ('#'.$voucher->id);
            $this->notificationService->notifyUser(
                (int) $voucher->created_by,
                'success',
                'Voucher approved',
                "{$num} has been fully approved.",
                '/vouchers',
                ['voucher_id' => $voucher->id]
            );

            return;
        }
        if (! $voucher->needsMoreApproval()) {
            return;
        }
        $nextLevel = $voucher->getNextApprovalLevel();
        $ids = $this->notificationService->approverUserIdsForLevel((int) $voucher->organization_id, $nextLevel);
        $ids = array_values(array_diff($ids, [$approver->id]));
        if ($ids === []) {
            return;
        }
        $num = $voucher->voucher_number ?? ('#'.$voucher->id);
        $this->notificationService->notifyUsers(
            $ids,
            'approval',
            'Voucher pending your approval',
            "{$num} is waiting for approval at level {$nextLevel}.",
            '/approvals/vouchers',
            ['voucher_id' => $voucher->id]
        );
    }

    private function notifyAfterVoucherReject(Voucher $voucher, User $approver): void
    {
        if (! $voucher->created_by || (int) $voucher->created_by === (int) $approver->id) {
            return;
        }
        $num = $voucher->voucher_number ?? ('#'.$voucher->id);
        $this->notificationService->notifyUser(
            (int) $voucher->created_by,
            'warning',
            'Voucher rejected',
            "{$num} was rejected. Check the voucher for details.",
            '/vouchers',
            ['voucher_id' => $voucher->id]
        );
    }
}
