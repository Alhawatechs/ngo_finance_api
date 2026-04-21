<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Core\UserController;
use App\Http\Controllers\Api\V1\Core\RoleController;
use App\Http\Controllers\Api\V1\Core\PermissionController;
use App\Http\Controllers\Api\V1\Core\DepartmentController;
use App\Http\Controllers\Api\V1\Core\OfficeController;
use App\Http\Controllers\Api\V1\Finance\ChartOfAccountController;
use App\Http\Controllers\Api\V1\Finance\JournalController;
use App\Http\Controllers\Api\V1\Finance\JournalEntryController;
use App\Http\Controllers\Api\V1\Finance\VoucherController;
use App\Http\Controllers\Api\V1\Finance\CurrencyController;
use App\Http\Controllers\Api\V1\Finance\ExchangeRateController;
use App\Http\Controllers\Api\V1\Finance\FiscalYearController;
use App\Http\Controllers\Api\V1\Finance\ProjectFiscalPeriodStatusController;
use App\Http\Controllers\Api\V1\Finance\BudgetController;
use App\Http\Controllers\Api\V1\Finance\ApprovalCenterController;
use App\Http\Controllers\Api\V1\Finance\BudgetFormatTemplateController;
use App\Http\Controllers\Api\V1\Finance\FundController;
use App\Http\Controllers\Api\V1\Finance\FundRequestController;
use App\Http\Controllers\Api\V1\Treasury\CashAccountController;
use App\Http\Controllers\Api\V1\Treasury\InterprojectCashLoanController;
use App\Http\Controllers\Api\V1\Treasury\BankAccountController;
use App\Http\Controllers\Api\V1\Payables\VendorController;
use App\Http\Controllers\Api\V1\Receivables\DonorController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Projects\GrantController;
use App\Http\Controllers\Api\V1\Projects\CostCenterController;
use App\Http\Controllers\Api\V1\DonorExpenditureCodeController;
use App\Http\Controllers\Api\V1\Assets\AssetController;
use App\Http\Controllers\Api\V1\Reports\FinancialReportController;
use App\Http\Controllers\Api\V1\Reports\DonorReportController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Audit\AuditLogController;
use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\Core\OrganizationController;
use App\Http\Controllers\Api\V1\Core\ApprovalWorkflowController;
use App\Http\Controllers\Api\V1\Archive\ArchiveController;
use App\Http\Controllers\Api\V1\NotificationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// API documentation (avoids 404 when opening /api/documentation from root JSON)
Route::get('/documentation', function () {
    return response()->json([
        'message' => 'AADA ERP Finance API',
        'version' => '1.0.0',
        'base_url' => '/api/v1',
        'health' => '/api/v1/health',
        'auth' => 'POST /api/v1/auth/login',
        'note' => 'Full API routes are defined in backend/routes/api.php. Use the frontend or inspect the codebase for endpoint details.',
    ]);
});

// API Version 1
Route::prefix('v1')->group(function () {

    // Health check (no auth) — used by frontend to detect if backend is reachable
    Route::get('/health', fn () => response()->json(['ok' => true, 'service' => 'api']));

    // Public routes
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/organization/branding', [OrganizationController::class, 'branding']);

    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {
        
        // Authentication
        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
            Route::put('/profile', [AuthController::class, 'updateProfile']);
            Route::put('/password', [AuthController::class, 'changePassword']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
        });

        // Finance Assistant (AI chat; rate-limited)
        Route::post('assistant/chat', [AssistantController::class, 'chat'])->middleware('throttle:assistant');

        // Approval Center (unified pending queue)
        Route::get('/approval-center/items', [ApprovalCenterController::class, 'items']);
        Route::get('/approval-center/counts', [ApprovalCenterController::class, 'counts']);

        // In-app notifications (user inbox; table `notifications`)
        Route::prefix('notifications')->group(function () {
            Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
            Route::get('/recent', [NotificationController::class, 'recent']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllRead']);
            Route::get('/', [NotificationController::class, 'index']);
            Route::patch('/{id}/read', [NotificationController::class, 'markRead']);
            Route::delete('/{id}', [NotificationController::class, 'destroy']);
        });

        // Dashboard
        Route::prefix('dashboard')->group(function () {
            Route::get('/', [DashboardController::class, 'index']);
            Route::get('/summary', [DashboardController::class, 'summary']);
            Route::get('/cash-position', [DashboardController::class, 'cashPosition']);
            Route::get('/trends', [DashboardController::class, 'trends']);
            Route::get('/project-status', [DashboardController::class, 'projectStatus']);
            Route::get('/fund-allocation', [DashboardController::class, 'fundAllocation']);
            Route::get('/alerts', [DashboardController::class, 'alerts']);
            Route::get('/activity', [DashboardController::class, 'activityFeed']);
        });

        // User Management
        Route::apiResource('users', UserController::class);
        Route::post('/users/{user}/activate', [UserController::class, 'activate']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);

        // Role & Permission Management
        Route::apiResource('roles', RoleController::class);
        Route::get('/permissions', [PermissionController::class, 'index']);
        Route::post('/roles/{role}/permissions', [RoleController::class, 'assignPermissions']);
        Route::get('/access-matrix', [RoleController::class, 'accessMatrix']);

        // Departments
        Route::apiResource('departments', DepartmentController::class);
        Route::get('/departments-tree', [DepartmentController::class, 'tree']);
        Route::get('/departments/{department}/users', [DepartmentController::class, 'users']);

        // Offices
        Route::post('offices/{office}/provision', [OfficeController::class, 'provision']);
        Route::apiResource('offices', OfficeController::class);
        Route::get('offices-databases', [\App\Http\Controllers\Api\V1\Core\OfficeDatabaseController::class, 'index']);
        Route::get('offices-databases/backup-info', [\App\Http\Controllers\Api\V1\Core\OfficeDatabaseController::class, 'backupInfo']);

        // Organization Settings
        Route::prefix('organization')->group(function () {
            Route::get('/', [OrganizationController::class, 'show']);
            Route::put('/', [OrganizationController::class, 'update']);
            Route::post('/logo', [OrganizationController::class, 'uploadLogo']);
            Route::delete('/logo', [OrganizationController::class, 'removeLogo']);
            Route::post('/license', [OrganizationController::class, 'uploadLicense']);
            Route::delete('/license', [OrganizationController::class, 'removeLicense']);
            Route::get('/statistics', [OrganizationController::class, 'statistics']);
        });

        // Approval Workflow (Administration — per organization)
        Route::prefix('approval-workflow')->group(function () {
            Route::get('/', [ApprovalWorkflowController::class, 'show']);
            Route::put('/', [ApprovalWorkflowController::class, 'update']);
        });

        // Organogram / Organizational Structure
        Route::prefix('organogram')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'getOrganogram']);
            
            // Organizational Units
            Route::get('/units', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'getUnits']);
            Route::post('/units', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'createUnit']);
            Route::put('/units/{id}', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'updateUnit']);
            Route::delete('/units/{id}', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'deleteUnit']);
            
            // Positions
            Route::get('/positions', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'getPositions']);
            Route::post('/positions', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'createPosition']);
            Route::put('/positions/{id}', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'updatePosition']);
            Route::delete('/positions/{id}', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'deletePosition']);
            
            // Position Assignments
            Route::post('/assignments', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'assignUser']);
            Route::delete('/assignments/{id}', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'unassignUser']);
            
            // Segregation of Duties
            Route::get('/sod-rules', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'getSodRules']);
            Route::post('/sod-rules', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'createSodRule']);
            Route::delete('/sod-rules/{id}', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'deleteSodRule']);
            
            // Reporting Lines
            Route::get('/reporting-lines', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'getReportingLines']);
            Route::post('/reporting-lines', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'createReportingLine']);
            Route::delete('/reporting-lines/{id}', [\App\Http\Controllers\Api\V1\Core\OrganogramController::class, 'deleteReportingLine']);
        });

        // Chart of Accounts
        Route::get('/chart-of-accounts/suggest-code', [ChartOfAccountController::class, 'suggestCode']);
        Route::get('/chart-of-accounts/flat-for-export', [ChartOfAccountController::class, 'flatForExport']);
        Route::get('/chart-of-accounts/export', [ChartOfAccountController::class, 'export']);
        Route::post('/chart-of-accounts/import', [ChartOfAccountController::class, 'import']);
        Route::post('/chart-of-accounts/{id}/restore', [ChartOfAccountController::class, 'restore']);
        Route::post('/chart-of-accounts/{id}/force-delete', [ChartOfAccountController::class, 'forceDelete']);
        Route::apiResource('chart-of-accounts', ChartOfAccountController::class);
        Route::get('/chart-of-accounts-tree', [ChartOfAccountController::class, 'tree']);
        Route::get('/chart-of-accounts/{account}/children', [ChartOfAccountController::class, 'children']);
        Route::post('/chart-of-accounts/{account}/activate', [ChartOfAccountController::class, 'activate']);
        Route::post('/chart-of-accounts/{account}/deactivate', [ChartOfAccountController::class, 'deactivate']);

        // Journals (journal books per project)
        Route::get('/journals/provinces', [JournalController::class, 'provinces']);
        Route::post('/journals/{id}/restore', [JournalController::class, 'restore']);
        Route::post('/journals/{id}/force-delete', [JournalController::class, 'forceDelete']);
        Route::apiResource('journals', JournalController::class);

        // Journal Entries (and general-ledger alias for frontend)
        Route::get('/journal-entries/export', [JournalEntryController::class, 'export']);
        Route::get('/general-ledger/journal-entries/export', [JournalEntryController::class, 'export']);
        Route::get('/journal-entries/summary', [JournalEntryController::class, 'summary']);
        Route::get('/journal-entries/project-ledger', [JournalEntryController::class, 'projectLedger']);
        Route::get('/general-ledger/journal-entries/project-ledger', [JournalEntryController::class, 'projectLedger']);
        Route::apiResource('journal-entries', JournalEntryController::class);
        Route::post('/journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post']);
        Route::post('/journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse']);

        // Coding Block (voucher number format) – single source for spec + options
        Route::get('/coding-block', [VoucherController::class, 'codingBlockOptions']);
        Route::get('/coding-block/config', [VoucherController::class, 'codingBlockConfig']);
        Route::put('/coding-block/config', [VoucherController::class, 'updateCodingBlockConfig']);
        // Vouchers
        Route::get('/vouchers/coding-block-options', [VoucherController::class, 'codingBlockOptions']);
        Route::get('/vouchers/next-number-preview', [VoucherController::class, 'nextNumberPreview']);
        Route::get('/vouchers/check-voucher-number', [VoucherController::class, 'checkVoucherNumber']);
        Route::apiResource('vouchers', VoucherController::class);
        Route::post('/vouchers/{voucher}/submit', [VoucherController::class, 'submit']);
        Route::post('/vouchers/{voucher}/approve', [VoucherController::class, 'approve']);
        Route::post('/vouchers/{voucher}/reject', [VoucherController::class, 'reject']);
        Route::get('/vouchers/{voucher}/approval-history', [VoucherController::class, 'approvalHistory']);

        // Currencies & Exchange Rates
        Route::apiResource('currencies', CurrencyController::class);
        Route::post('/currencies/{currency}/rates', [CurrencyController::class, 'addRate']);
        Route::get('/currencies/{currency}/rates', [CurrencyController::class, 'getRates']);
        Route::get('/exchange-rate', [CurrencyController::class, 'getExchangeRate']);

        // Exchange Rates (standalone resource for frontend)
        Route::get('/exchange-rates/current', [ExchangeRateController::class, 'current']);
        Route::post('/exchange-rates/convert', [ExchangeRateController::class, 'convert']);
        Route::post('/exchange-rates/bulk-import', [ExchangeRateController::class, 'bulkImport']);
        Route::get('/exchange-rates/history', [ExchangeRateController::class, 'history']);
        Route::apiResource('exchange-rates', ExchangeRateController::class);

        // Fiscal Years & Periods
        Route::apiResource('fiscal-years', FiscalYearController::class);
        Route::get('/fiscal-years/{fiscal_year}/periods', [FiscalYearController::class, 'periods']);
        Route::post('/fiscal-periods/{fiscal_period}/close', [FiscalYearController::class, 'closePeriod']);
        Route::post('/fiscal-periods/{fiscal_period}/reopen', [FiscalYearController::class, 'reopenPeriod']);
        Route::post('/fiscal-periods/{fiscal_period}/lock', [FiscalYearController::class, 'lockPeriod']);

        // Treasury - Cash Management (prefix matches frontend: /treasury/cash-accounts)
        Route::prefix('treasury')->group(function () {
            Route::get('cash-accounts/summary', [CashAccountController::class, 'summary']);
            Route::post('cash-accounts/transfer', [CashAccountController::class, 'transfer']);
            Route::post('cash-accounts/exchange', [CashAccountController::class, 'exchange']);
            Route::get('cash-accounts', [CashAccountController::class, 'index']);
            Route::post('cash-accounts', [CashAccountController::class, 'store']);
            Route::get('cash-accounts/{cashAccount}', [CashAccountController::class, 'show']);
            Route::put('cash-accounts/{cashAccount}', [CashAccountController::class, 'update']);
            Route::delete('cash-accounts/{cashAccount}', [CashAccountController::class, 'destroy']);
            Route::get('cash-accounts/{cashAccount}/transactions', [CashAccountController::class, 'transactions']);
            Route::post('cash-accounts/{cashAccount}/transactions', [CashAccountController::class, 'recordTransaction']);
            Route::get('cash-accounts/{cashAccount}/cash-counts', [CashAccountController::class, 'cashCountHistory']);
            Route::post('cash-accounts/{cashAccount}/cash-counts', [CashAccountController::class, 'recordCashCount']);
            Route::apiResource('interproject-cash-loans', InterprojectCashLoanController::class);

            // Bank Management (frontend: /treasury/bank-accounts)
            Route::get('bank-accounts/summary', [BankAccountController::class, 'summary']);
            Route::apiResource('bank-accounts', BankAccountController::class);
            Route::get('bank-accounts/{bankAccount}/balance', [BankAccountController::class, 'balance']);
            Route::get('bank-accounts/{bankAccount}/transactions', [BankAccountController::class, 'transactions']);
            Route::post('bank-accounts/{bankAccount}/reconciliation', [BankAccountController::class, 'startReconciliation']);
            Route::post('reconciliations/{reconciliation}/reconcile', [BankAccountController::class, 'reconcileTransactions']);
            Route::post('reconciliations/{reconciliation}/complete', [BankAccountController::class, 'completeReconciliation']);
        });
        Route::post('treasury/cash-counts/{cashCount}/verify', [CashAccountController::class, 'verifyCashCount']);

        // Accounts Payable
        Route::apiResource('vendors', VendorController::class);

        // Accounts Receivable (frontend uses /receivables/donors)
        Route::prefix('receivables')->group(function () {
            Route::get('donors/summary', [DonorController::class, 'summary']);
            Route::get('donors/{donor}/grants', [DonorController::class, 'grants']);
            Route::get('donors/{donor}/donations', [DonorController::class, 'donations']);
            Route::get('donors/{donor}/pledges', [DonorController::class, 'pledges']);
            Route::apiResource('donors', DonorController::class);
        });

        // Projects & Grants
        Route::get('/projects/summary', [ProjectController::class, 'summary']);
        Route::get('/projects/{project}/documents', [ProjectController::class, 'documents']);
        Route::post('/projects/{project}/documents', [ProjectController::class, 'uploadDocument']);
        Route::get('/projects/{project}/documents/{document}/download', [ProjectController::class, 'downloadDocument']);
        Route::put('/projects/{project}/documents/{document}', [ProjectController::class, 'updateDocument']);
        Route::delete('/projects/{project}/documents/{document}', [ProjectController::class, 'deleteDocument']);
        Route::get('/projects/{project}/fiscal-years/{fiscal_year}/period-close-statuses', [ProjectFiscalPeriodStatusController::class, 'index']);
        Route::post('/projects/{project}/fiscal-periods/{fiscal_period}/close-project-posting', [ProjectFiscalPeriodStatusController::class, 'closeProjectPeriod']);
        Route::post('/projects/{project}/fiscal-periods/{fiscal_period}/reopen-project-posting', [ProjectFiscalPeriodStatusController::class, 'reopenProjectPeriod']);
        Route::post('/projects/{project}/fiscal-periods/{fiscal_period}/lock-project-posting', [ProjectFiscalPeriodStatusController::class, 'lockProjectPeriod']);
        Route::post('/projects/{project}/fiscal-periods/{fiscal_period}/unlock-permanent-project-posting', [ProjectFiscalPeriodStatusController::class, 'unlockPermanentProjectPosting']);
        Route::apiResource('projects', ProjectController::class);
        Route::get('/projects/{project}/budget-lines', [ProjectController::class, 'budgetLines']);
        Route::get('/cost-centers', [CostCenterController::class, 'index']);
        Route::post('/cost-centers', [CostCenterController::class, 'store']);
        Route::get('/cost-centers/{cost_center}', [CostCenterController::class, 'show']);
        Route::put('/cost-centers/{cost_center}', [CostCenterController::class, 'update']);
        Route::delete('/cost-centers/{cost_center}', [CostCenterController::class, 'destroy']);
        Route::post('/projects/{project}/budget-lines', [ProjectController::class, 'addBudgetLine']);
        Route::get('/donor-expenditure-codes', [DonorExpenditureCodeController::class, 'index']);
        Route::post('/donor-expenditure-codes', [DonorExpenditureCodeController::class, 'store']);
        Route::put('/donor-expenditure-codes/{donorExpenditureCode}', [DonorExpenditureCodeController::class, 'update']);
        Route::delete('/donor-expenditure-codes/{donorExpenditureCode}', [DonorExpenditureCodeController::class, 'destroy']);
        Route::get('/grants/summary', [GrantController::class, 'summary']);
        Route::get('/grants/{grant}/projects', [GrantController::class, 'projects']);
        Route::post('/grants/{grant}/disbursement', [GrantController::class, 'recordDisbursement']);
        Route::get('/grants/{grant}/documents', [GrantController::class, 'documents']);
        Route::post('/grants/{grant}/documents', [GrantController::class, 'uploadDocument']);
        Route::get('/grants/{grant}/documents/{document}/download', [GrantController::class, 'downloadDocument']);
        Route::put('/grants/{grant}/documents/{document}', [GrantController::class, 'updateDocument']);
        Route::delete('/grants/{grant}/documents/{document}', [GrantController::class, 'deleteDocument']);
        Route::apiResource('grants', GrantController::class);

        // Budgets
        Route::get('/budget-format-templates', [BudgetFormatTemplateController::class, 'index']);
        Route::get('/budget-format-templates/suggested', [BudgetFormatTemplateController::class, 'suggested']);
        Route::post('/budget-format-templates/import-from-google-sheet', [BudgetFormatTemplateController::class, 'importFromGoogleSheet']);
        Route::post('/budget-format-templates', [BudgetFormatTemplateController::class, 'store']);
        Route::get('/budget-format-templates/{id}', [BudgetFormatTemplateController::class, 'show']);
        Route::put('/budget-format-templates/{id}', [BudgetFormatTemplateController::class, 'update']);
        Route::delete('/budget-format-templates/{id}', [BudgetFormatTemplateController::class, 'destroy']);
        Route::get('/budgets/summary', [BudgetController::class, 'summary']);
        Route::get('/budgets/comparison', [BudgetController::class, 'comparison']);
        Route::post('/budgets/{budget}/revise', [BudgetController::class, 'revise']);
        Route::post('/budgets/{budget}/submit', [BudgetController::class, 'submit']);
        Route::post('/budgets/{budget}/approve', [BudgetController::class, 'approve']);
        Route::get('/budgets/{budget}/export', [BudgetController::class, 'export']);
        Route::apiResource('budgets', BudgetController::class);

        // Funds
        Route::get('/funds/balances', [FundController::class, 'balances']);
        Route::get('/funds/summary', [FundController::class, 'summary']);
        Route::get('/funds/{fund}/statement', [FundController::class, 'statement']);
        Route::apiResource('funds', FundController::class);
        Route::get('/fund-requests', [FundRequestController::class, 'index']);
        Route::post('/fund-requests', [FundRequestController::class, 'store']);
        Route::get('/fund-requests/{fundRequest}', [FundRequestController::class, 'show']);
        Route::put('/fund-requests/{fundRequest}', [FundRequestController::class, 'update']);
        Route::post('/fund-requests/{fundRequest}/submit', [FundRequestController::class, 'submit']);

        // Fixed Assets
        Route::apiResource('assets', AssetController::class);
        Route::post('/assets/{asset}/depreciate', [AssetController::class, 'depreciate']);
        Route::post('/assets/{asset}/dispose', [AssetController::class, 'dispose']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/trial-balance', [FinancialReportController::class, 'trialBalance']);
            Route::get('/income-statement', [FinancialReportController::class, 'incomeStatement']);
            Route::get('/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
            Route::get('/cash-flow', [FinancialReportController::class, 'cashFlow']);
            Route::get('/budget-vs-actual', [FinancialReportController::class, 'budgetVsActual']);
            Route::get('/general-ledger', [FinancialReportController::class, 'generalLedger']);
            Route::get('/account-statement', [FinancialReportController::class, 'accountStatement']);
            
            // Donor Reports
            Route::get('/donor/{donor}', [DonorReportController::class, 'donorReport']);
            Route::get('/donor/{donor}/grant/{grant}', [DonorReportController::class, 'grantReport']);
            Route::get('/donor-summary', [DonorReportController::class, 'summary']);
        });

        // Export endpoints
        Route::prefix('export')->group(function () {
            Route::get('/report/{type}', [FinancialReportController::class, 'export']);
        });

        // Audit & Compliance
        Route::prefix('audit-compliance')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/{id}', [AuditLogController::class, 'show']);
        });

        // Archive Management
        Route::get('/archive', [ArchiveController::class, 'index']);
        Route::post('/archive', [ArchiveController::class, 'store']);
        Route::post('/archive/bulk-download', [ArchiveController::class, 'bulkDownload']);
        Route::get('/archive/{document}/download', [ArchiveController::class, 'download']);
        Route::get('/archive/{document}', [ArchiveController::class, 'show']);
        Route::delete('/archive/{document}', [ArchiveController::class, 'destroy']);
    });
});
