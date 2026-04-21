<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Organization;
use App\Support\ChartOfAccountsCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Seeds a professional NGO Chart of Accounts with 4 hierarchy levels.
 *
 * Layer 1: Main categories — Income, Expenses, Assets, Liabilities, Fund Balance (codes 1–5)
 * Layer 2: Sub-categories without dot (e.g. 11, 21, 31)
 * Layer 3: One dot (e.g. 11.1, 31.1)
 * Layer 4: Two dots (e.g. 11.1.1, 31.1.1) — posting leaf accounts
 *
 * Example: Assets → Current Assets → Cash and Bank → 31.1.1 Operating Cash - USD. Income: 1 → 11 → 11.1 → 11.1.1+.
 * Run after an organization exists: php artisan db:seed --class=NGOChartOfAccountsSeeder
 */
class NGOChartOfAccountsSeeder extends Seeder
{
    /** @return array<int, array{code: string, name: string, type: string, balance: string, description: string}> */
    public static function mainCategoryDefinitions(): array
    {
        return [
            ['code' => '1', 'name' => 'Income', 'type' => 'revenue', 'balance' => 'credit', 'description' => 'Income from grants, donations, and other operating revenue.'],
            ['code' => '2', 'name' => 'Expenses', 'type' => 'expense', 'balance' => 'debit', 'description' => 'Costs incurred in program delivery, management, and fundraising.'],
            ['code' => '3', 'name' => 'Assets', 'type' => 'asset', 'balance' => 'debit', 'description' => 'Resources controlled by the organization as a result of past events.'],
            ['code' => '4', 'name' => 'Liabilities', 'type' => 'liability', 'balance' => 'credit', 'description' => 'Present obligations arising from past events.'],
            ['code' => '5', 'name' => 'Fund Balance', 'type' => 'equity', 'balance' => 'credit', 'description' => 'Residual interest in assets after deducting liabilities (NGO/fund accounting).'],
        ];
    }

    /** @return array<int, array{code: string, name: string, type: string, balance: string, parent: string}> */
    public static function subHeaderDefinitions(): array
    {
        return [
            ['code' => '31', 'name' => 'Current Assets', 'type' => 'asset', 'balance' => 'debit', 'parent' => '3'],
            ['code' => '32', 'name' => 'Non-Current Assets', 'type' => 'asset', 'balance' => 'debit', 'parent' => '3'],
            ['code' => '41', 'name' => 'Current Liabilities', 'type' => 'liability', 'balance' => 'credit', 'parent' => '4'],
            ['code' => '42', 'name' => 'Non-Current Liabilities', 'type' => 'liability', 'balance' => 'credit', 'parent' => '4'],
            // PDF export chart-of-accounts-2026-03-23: donor-style L2 names
            ['code' => '11', 'name' => 'Donor Funds', 'type' => 'revenue', 'balance' => 'credit', 'parent' => '1'],
            ['code' => '21', 'name' => 'Wages/ Salaries', 'type' => 'expense', 'balance' => 'debit', 'parent' => '2'],
            ['code' => '22', 'name' => 'Goods and Services', 'type' => 'expense', 'balance' => 'debit', 'parent' => '2'],
            ['code' => '23', 'name' => 'Training and Survey', 'type' => 'expense', 'balance' => 'debit', 'parent' => '2'],
            ['code' => '24', 'name' => 'Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent' => '2'],
        ];
    }

    /** @return array<int, array{code: string, name: string, type: string, balance: string, parent: string}> */
    public static function level3HeaderDefinitions(): array
    {
        return [
            ['code' => '31.1', 'name' => 'Cash and Bank', 'type' => 'asset', 'balance' => 'debit', 'parent' => '31'],
            ['code' => '31.2', 'name' => 'Receivables and Advances', 'type' => 'asset', 'balance' => 'debit', 'parent' => '31'],
            ['code' => '32.1', 'name' => 'Property, Plant & Equipment', 'type' => 'asset', 'balance' => 'debit', 'parent' => '32'],
            ['code' => '32.2', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'balance' => 'credit', 'parent' => '32'],
            ['code' => '41.1', 'name' => 'Accounts Payable and Accruals', 'type' => 'liability', 'balance' => 'credit', 'parent' => '41'],
            ['code' => '41.2', 'name' => 'Deferred Revenue and Advances', 'type' => 'liability', 'balance' => 'credit', 'parent' => '41'],
            ['code' => '41.3', 'name' => 'Current Portion of Debt', 'type' => 'liability', 'balance' => 'credit', 'parent' => '41'],
            ['code' => '42.1', 'name' => 'Long-term Debt', 'type' => 'liability', 'balance' => 'credit', 'parent' => '42'],
            ['code' => '51', 'name' => 'Unrestricted Fund Balance', 'type' => 'equity', 'balance' => 'credit', 'parent' => '5'],
            ['code' => '52', 'name' => 'Temporarily Restricted Fund Balance', 'type' => 'equity', 'balance' => 'credit', 'parent' => '5'],
            ['code' => '53', 'name' => 'Permanently Restricted Fund Balance', 'type' => 'equity', 'balance' => 'credit', 'parent' => '5'],
            // PDF: UNICEF / UNFPA / UNDP project groupings under Donor Funds
            ['code' => '11.1', 'name' => 'UNICEF Projects', 'type' => 'revenue', 'balance' => 'credit', 'parent' => '11'],
            ['code' => '11.2', 'name' => 'UNFPA Projects', 'type' => 'revenue', 'balance' => 'credit', 'parent' => '11'],
            ['code' => '11.3', 'name' => 'UNDP Projects', 'type' => 'revenue', 'balance' => 'credit', 'parent' => '11'],
            ['code' => '21.1', 'name' => 'Management Salaries', 'type' => 'expense', 'balance' => 'debit', 'parent' => '21'],
            ['code' => '21.2', 'name' => 'Salaries HFs', 'type' => 'expense', 'balance' => 'debit', 'parent' => '21'],
            ['code' => '22.1', 'name' => 'Travel and Transport', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.2', 'name' => 'Supervision and Monitoring', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.3', 'name' => 'Communication', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.4', 'name' => 'Repairs and Maintenance', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.5', 'name' => 'Utilities (Power and Fuel)', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.6', 'name' => 'Materials and Supplies/Office', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.7', 'name' => 'Pharmaceutical Supplies', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.8', 'name' => 'Rent or Lease Payment', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '22.9', 'name' => 'Other Cost/Operational Expenses', 'type' => 'expense', 'balance' => 'debit', 'parent' => '22'],
            ['code' => '21.3', 'name' => 'Hardship', 'type' => 'expense', 'balance' => 'debit', 'parent' => '21'],
            // PDF: GL title "Night Duty"; description "Other Personnel"
            ['code' => '21.4', 'name' => 'Night Duty', 'type' => 'expense', 'balance' => 'debit', 'parent' => '21'],
            ['code' => '21.5', 'name' => 'Wages/Incentive', 'type' => 'expense', 'balance' => 'debit', 'parent' => '21'],
            ['code' => '21.6', 'name' => 'Benefits', 'type' => 'expense', 'balance' => 'debit', 'parent' => '21'],
            ['code' => '23.1', 'name' => 'Trainings Cost', 'type' => 'expense', 'balance' => 'debit', 'parent' => '23'],
            ['code' => '24.1', 'name' => 'Medical Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent' => '24'],
            ['code' => '24.2', 'name' => 'Office Equipments and Furniture', 'type' => 'expense', 'balance' => 'debit', 'parent' => '24'],
        ];
    }

    public function run(): void
    {
        $organization = Organization::first();
        if (!$organization) {
            $this->command->warn('No organization found. Create an organization first (e.g. run DatabaseSeeder).');
            return;
        }

        $orgId = $organization->id;
        $baseCurrency = strtoupper((string) ($organization->default_currency ?? config('erp.currencies.default', 'AFN')));

        $referencePath = database_path('seeders/data/reference-coa-hierarchy.json');
        if (File::exists($referencePath)) {
            self::applyReferenceHierarchy($orgId, $referencePath);
            $this->command?->info('NGO Chart of Accounts seeded from reference workbook (reference-coa-hierarchy.json).');

            return;
        }

        $this->command?->warn('reference-coa-hierarchy.json not found — using built-in NGO chart. Generate from Excel: node backend/scripts/parse-reference-coa.cjs');

        $created = [];

        // ─── Level 1: Main category headers (five top-level categories) ──────
        $mainCategories = self::mainCategoryDefinitions();

        foreach ($mainCategories as $h) {
            $record = ChartOfAccount::firstOrNew(['organization_id' => $orgId, 'account_code' => $h['code']]);
            $record->parent_id = null;
            $record->account_type = $h['type'];
            $record->normal_balance = $h['balance'];
            $record->level = 1;
            $record->is_header = true;
            $record->is_posting = false;
            $record->description = $h['description'] ?? ('NGO Chart of Accounts – ' . $h['name']);
            $record->opening_balance = 0;
            if (!$record->exists) {
                $record->account_name = $h['name'];
            }
            $record->save();
            $created[$h['code']] = $record;
        }

        // ─── Level 2: Sub-category headers ──────────────────────────────────
        $subHeaders = self::subHeaderDefinitions();

        foreach ($subHeaders as $h) {
            $parentId = $created[$h['parent']]->id ?? null;
            $record = ChartOfAccount::firstOrNew(['organization_id' => $orgId, 'account_code' => $h['code']]);
            $record->parent_id = $parentId;
            $record->account_type = $h['type'];
            $record->normal_balance = $h['balance'];
            $record->level = 2;
            $record->is_header = true;
            $record->is_posting = false;
            $record->description = $h['name'];
            $record->opening_balance = 0;
            if (!$record->exists) {
                $record->account_name = $h['name'];
            }
            $record->save();
            $created[$h['code']] = $record;
        }

        // ─── Level 3: Grouping headers (third layer) ─────────────────────────
        $level3Headers = self::level3HeaderDefinitions();

        foreach ($level3Headers as $h) {
            $parentId = $created[$h['parent']]->id ?? null;
            $record = ChartOfAccount::firstOrNew(['organization_id' => $orgId, 'account_code' => $h['code']]);
            $record->parent_id = $parentId;
            $record->account_type = $h['type'];
            $record->normal_balance = $h['balance'];
            $record->level = 3;
            $record->is_header = true;
            $record->is_posting = false;
            $record->description = $h['name'];
            $record->opening_balance = 0;
            if (!$record->exists) {
                $record->account_name = $h['name'];
            }
            $record->save();
            $created[$h['code']] = $record;
        }

        // ─── Level 4: Posting accounts (directly under L3 General Ledger, no subsidiary layer) ──────
        $accounts = self::getAccounts();
        foreach ($accounts as $row) {
            $code = $row['code'];
            $parentCode = $row['parent_code'] ?? $this->deriveParentCode($code);
            $parentId = $created[$parentCode]->id ?? null;

            $record = ChartOfAccount::withTrashed()->firstOrNew(['organization_id' => $orgId, 'account_code' => $code]);
            $record->parent_id = $parentId;
            $record->account_type = $row['type'];
            $record->normal_balance = $row['balance'];
            $record->level = 4;
            $record->is_header = false;
            $record->is_posting = true;
            $record->is_bank_account = $row['is_bank'] ?? false;
            $record->is_cash_account = $row['is_cash'] ?? false;
            $record->fund_type = $row['fund_type'] ?? null;
            $record->description = $row['description'] ?? null;
            $record->deleted_at = null;
            $record->opening_balance = 0;
            $record->currency_code = $baseCurrency;
            if (!$record->exists) {
                $record->account_name = $row['name'];
            }
            $record->save();
        }

        ChartOfAccountsCache::forgetForOrganization($orgId);

        $this->command->info('NGO Chart of Accounts seeded successfully for organization: ' . $organization->name);
    }

    /**
     * Seeds the full NGO chart from {@see database/seeders/data/reference-coa-hierarchy.json},
     * produced by {@see parse-reference-coa.cjs} from the standard "AADA Final Chart of accounts.xlsx" workbook.
     * Clears tree cache so General Ledger → Account list shows the new data immediately.
     *
     * @param  string  $path  Absolute path to reference-coa-hierarchy.json
     */
    public static function applyReferenceHierarchy(int $orgId, string $path): void
    {
        $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $hierarchy = $payload['hierarchy'] ?? [];
        $byCode = [];
        $baseCurrency = strtoupper((string) (Organization::where('id', $orgId)->value('default_currency')
            ?? config('erp.currencies.default', 'AFN')));

        foreach ($hierarchy as $row) {
            $code = $row['code'];
            $parentId = null;
            if (! empty($row['parent_code'])) {
                $pc = $row['parent_code'];
                $parentId = $byCode[$pc] ?? null;
            }
            $isPosting = (bool) ($row['is_posting'] ?? false);
            $record = ChartOfAccount::withTrashed()->firstOrNew(['organization_id' => $orgId, 'account_code' => $code]);
            $record->parent_id = $parentId;
            $record->account_type = $row['account_type'];
            $record->normal_balance = $row['normal_balance'];
            $record->level = (int) $row['level'];
            $record->is_header = ! $isPosting;
            $record->is_posting = $isPosting;
            $record->account_name = $row['name'];
            $record->is_active = true;
            $record->deleted_at = null;
            if ($isPosting) {
                $cc = $row['currency_code'] ?? null;
                $record->currency_code = ($cc !== null && $cc !== '')
                    ? strtoupper((string) $cc)
                    : $baseCurrency;
            } else {
                $record->currency_code = null;
            }
            $record->opening_balance = 0;
            $record->is_bank_account = false;
            $record->is_cash_account = false;
            $record->save();
            $byCode[$code] = $record->id;
        }

        ChartOfAccountsCache::forgetForOrganization($orgId);
    }

    public static function getAccounts(): array
    {
        return [
            // ─── L4 under 30110 Cash and Bank (direct GL linkage) ───
            ['code' => '31.1.1', 'name' => 'Operating Cash - USD', 'type' => 'asset', 'balance' => 'debit', 'is_cash' => true, 'parent_code' => '31.1', 'description' => 'Primary operating cash in USD for day-to-day operations.'],
            ['code' => '31.1.2', 'name' => 'Operating Cash - AFN', 'type' => 'asset', 'balance' => 'debit', 'is_cash' => true, 'parent_code' => '31.1', 'description' => 'Primary operating cash in AFN for local transactions.'],
            ['code' => '31.1.3', 'name' => 'Bank - Operating Account', 'type' => 'asset', 'balance' => 'debit', 'is_bank' => true, 'parent_code' => '31.1', 'description' => 'Main bank account for operational transactions and payments.'],
            ['code' => '31.1.4', 'name' => 'Bank - Donor Restricted Account', 'type' => 'asset', 'balance' => 'debit', 'is_bank' => true, 'parent_code' => '31.1', 'description' => 'Segregated bank account for donor-restricted funds.'],
            ['code' => '31.2.1', 'name' => 'Grants/Donor Receivables', 'type' => 'asset', 'balance' => 'debit', 'parent_code' => '31.2', 'description' => 'Amounts due from donors and grant-making institutions.'],
            ['code' => '31.2.2', 'name' => 'Employee Advances', 'type' => 'asset', 'balance' => 'debit', 'parent_code' => '31.2', 'description' => 'Funds advanced to employees for travel or operational purposes.'],
            ['code' => '32.1.1', 'name' => 'Land', 'type' => 'asset', 'balance' => 'debit', 'parent_code' => '32.1', 'description' => 'Cost of land owned by the organization.'],
            ['code' => '32.1.2', 'name' => 'Buildings', 'type' => 'asset', 'balance' => 'debit', 'parent_code' => '32.1', 'description' => 'Cost of buildings and structures owned by the organization.'],
            ['code' => '32.1.3', 'name' => 'Vehicles', 'type' => 'asset', 'balance' => 'debit', 'parent_code' => '32.1', 'description' => 'Cost of vehicles owned by the organization.'],
            ['code' => '32.1.4', 'name' => 'Furniture and Fixtures', 'type' => 'asset', 'balance' => 'debit', 'parent_code' => '32.1', 'description' => 'Cost of office furniture, fixtures, and equipment.'],
            ['code' => '32.2.1', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'balance' => 'credit', 'parent_code' => '32.2', 'description' => 'Cumulative depreciation on fixed assets.'],
            ['code' => '41.1.1', 'name' => 'Accounts Payable - Vendors', 'type' => 'liability', 'balance' => 'credit', 'parent_code' => '41.1', 'description' => 'Amounts owed to suppliers and vendors for goods and services.'],
            ['code' => '41.1.2', 'name' => 'Withholding Taxes Payable', 'type' => 'liability', 'balance' => 'credit', 'parent_code' => '41.1', 'description' => 'Taxes withheld from employee salaries pending remittance.'],
            ['code' => '41.1.3', 'name' => 'Salary/Wages Payable', 'type' => 'liability', 'balance' => 'credit', 'parent_code' => '41.1', 'description' => 'Accrued salaries and wages owed to employees.'],
            ['code' => '41.2.1', 'name' => 'Donor Advances/Prepayments', 'type' => 'liability', 'balance' => 'credit', 'parent_code' => '41.2', 'description' => 'Funds received in advance of delivering services or reporting.'],
            ['code' => '41.3.1', 'name' => 'Current Portion - Loans', 'type' => 'liability', 'balance' => 'credit', 'parent_code' => '41.3', 'description' => 'Portion of long-term debt due within the next 12 months.'],
            ['code' => '42.1.1', 'name' => 'Long-term Loans', 'type' => 'liability', 'balance' => 'credit', 'parent_code' => '42.1', 'description' => 'Loans with repayment terms exceeding one year.'],
            ['code' => '42.1.2', 'name' => 'Lease Obligations', 'type' => 'liability', 'balance' => 'credit', 'parent_code' => '42.1', 'description' => 'Long-term lease commitments for property or equipment.'],
            ['code' => '51.1', 'name' => 'Operating Reserve', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'unrestricted', 'parent_code' => '51', 'description' => 'Board-designated funds for operational stability.'],
            ['code' => '51.2', 'name' => 'Board Designated Funds', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'unrestricted', 'parent_code' => '51', 'description' => 'Unrestricted funds designated by the Board for specific purposes.'],
            ['code' => '51.3', 'name' => 'Undesignated Unrestricted', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'unrestricted', 'parent_code' => '51', 'description' => 'Unrestricted funds with no specific designation.'],
            ['code' => '52.1', 'name' => 'Donor Restricted - Maternal Health', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'temporarily_restricted', 'parent_code' => '52', 'description' => 'Funds restricted by donors for maternal health programs.'],
            ['code' => '52.2', 'name' => 'Time Restricted Funds - Education', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'temporarily_restricted', 'parent_code' => '52', 'description' => 'Funds restricted by time for education programs.'],
            ['code' => '52.3', 'name' => 'Purpose Restricted Funds - WASH', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'temporarily_restricted', 'parent_code' => '52', 'description' => 'Funds restricted for WASH program activities.'],
            ['code' => '53.1', 'name' => 'Endowment Funds', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'restricted', 'parent_code' => '53', 'description' => 'Funds with donor restrictions requiring principal to be maintained in perpetuity.'],
            ['code' => '53.2', 'name' => 'Perpetual Restricted Funds', 'type' => 'equity', 'balance' => 'credit', 'fund_type' => 'restricted', 'parent_code' => '53', 'description' => 'Funds with permanent restrictions on use.'],
            ['code' => '11.1.1', 'name' => 'Government Grants - Health', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.1', 'description' => 'Revenue from government agencies for health programs.'],
            ['code' => '11.1.2', 'name' => 'Foundation Grants - Education', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.1', 'description' => 'Revenue from foundations for education programs.'],
            ['code' => '11.1.3', 'name' => 'Corporate Grants - WASH', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.1', 'description' => 'Revenue from corporate donors for water projects.'],
            ['code' => '11.1.4', 'name' => 'UN Agency Grants - Emergency', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.1', 'description' => 'Revenue from UN agencies for emergency response.'],
            ['code' => '11.1.5', 'name' => 'Contract Revenue - Training', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.1', 'description' => 'Revenue from service contracts for training programs.'],
            ['code' => '11.2.1', 'name' => 'Individual Donations - Unrestricted', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.2', 'description' => 'Contributions from individuals without restrictions.'],
            ['code' => '11.2.2', 'name' => 'Individual Donations - Health Restricted', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.2', 'description' => 'Contributions from individuals restricted to health programs.'],
            ['code' => '11.2.3', 'name' => 'In-kind Donations', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.2', 'description' => 'Non-cash contributions of goods or services.'],
            ['code' => '11.2.4', 'name' => 'Membership Fees', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.2', 'description' => 'Revenue from membership dues.'],
            ['code' => '11.3.1', 'name' => 'Training/Workshop Fees', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.3', 'description' => 'Fees charged for training programs and workshops.'],
            ['code' => '11.3.2', 'name' => 'Publication Sales', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.3', 'description' => 'Revenue from sale of publications and materials.'],
            ['code' => '11.3.3', 'name' => 'Interest Income', 'type' => 'revenue', 'balance' => 'credit', 'parent_code' => '11.3', 'description' => 'Interest earned on bank deposits and investments.'],
            // 21.1.1+: job titles seeded by ProgramPersonnelJobTitlesSeeder under 21.1
            // 21.2.1–21.2.51: SalariesHFsJobTitlesSeeder under 21.2
            // 52100 Travel and Transport
            ['code' => '22.1.1', 'name' => 'International airfare', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            ['code' => '22.1.2', 'name' => 'Local Airfare', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            ['code' => '22.1.3', 'name' => 'Local travel', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            ['code' => '22.1.4', 'name' => 'Transportation medicine/supplies', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            ['code' => '22.1.5', 'name' => 'Rent of vehicles Head office', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            ['code' => '22.1.6', 'name' => 'Rent of vehicles Project office', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            ['code' => '22.1.7', 'name' => 'Ambulance Rent', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            ['code' => '22.1.8', 'name' => 'Transportation and Perdiem during', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.1'],
            // 52200 Supervision and Monitoring
            ['code' => '22.2.1', 'name' => 'Staff per diem', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.2'],
            ['code' => '22.2.2', 'name' => 'Accommodation', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.2'],
            ['code' => '22.2.3', 'name' => 'Baseline assessment (Perdiem, accommodation)', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.2'],
            ['code' => '22.2.4', 'name' => 'Non binding assessment', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.2'],
            ['code' => '22.2.5', 'name' => 'Accreditation fee', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.2'],
            ['code' => '22.2.6', 'name' => 'Trainers fee', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.2'],
            // 52300 Communication
            ['code' => '22.3.1', 'name' => 'Telephone & top up cards', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.3'],
            ['code' => '22.3.2', 'name' => 'Internet install & monthly fee', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.3'],
            ['code' => '22.3.3', 'name' => 'Advertisement', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.3'],
            // 52400 Repairs and Maintenance
            ['code' => '22.4.1', 'name' => 'Building repair & Maintenance', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.4'],
            ['code' => '22.4.2', 'name' => 'Vehicle repair & maint (includes)', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.4'],
            ['code' => '22.4.3', 'name' => 'Equip repair & maint', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.4'],
            ['code' => '22.4.4', 'name' => 'Ambulance repair & Maintenance', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.4'],
            ['code' => '22.4.5', 'name' => 'Basic Renovation', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.4'],
            ['code' => '22.4.6', 'name' => 'Medical Equipments Repair & Maintenance', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.4'],
            ['code' => '22.4.7', 'name' => 'Non Medical Equip Repair & Maintenance', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.4'],
            // 52500 Utilities (Power and Fuel)
            ['code' => '22.5.1', 'name' => 'Electricity, water, gas (cooking & Other)', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.2', 'name' => 'Gas for Vaccine refrigerator', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.3', 'name' => 'Fuel for Generator', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.4', 'name' => 'Fuel for Motorbike', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.5', 'name' => 'Ambulance Fuel', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.6', 'name' => 'Fuel for heating', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.7', 'name' => 'Winterization wood', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.8', 'name' => 'Winterization Cool', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.9', 'name' => 'Fuel of EPI Vehicle', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.10', 'name' => 'Fuel of Office Vehicle', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.11', 'name' => 'Fuel for Lamp', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.12', 'name' => 'Winterization', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            ['code' => '22.5.13', 'name' => 'Gas for Sterilization', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.5'],
            // 52600 Materials and Supplies/Office
            ['code' => '22.6.1', 'name' => 'Office & general supplies', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.6'],
            ['code' => '22.6.2', 'name' => 'Office & Small Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.6'],
            ['code' => '22.6.3', 'name' => 'Refreshment', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.6'],
            ['code' => '22.6.4', 'name' => 'Cartridge for printer', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.6'],
            ['code' => '22.6.5', 'name' => 'Printing - HMIS & MOPH forms', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.6'],
            ['code' => '22.6.6', 'name' => 'Stationary', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.6'],
            ['code' => '22.6.7', 'name' => 'Cleaning Materials', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.6'],
            // 52700 Pharmaceutical Supplies
            ['code' => '22.7.1', 'name' => 'Drugs', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.2', 'name' => 'Medical supplies', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.3', 'name' => 'Others', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.4', 'name' => 'Baby kit', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.5', 'name' => 'Lab skill consumables', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.6', 'name' => 'Science lab supplies', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.7', 'name' => 'IEC Materials', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.8', 'name' => 'Hygiene Kit for trainees', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.9', 'name' => 'Consumable items for Clinical', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.10', 'name' => 'HIV Rapid Diagnostic Test', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.11', 'name' => 'HBsAg RAPID TEST CARD', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.12', 'name' => 'HCV RAPID TEST CARD', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.13', 'name' => 'SD Bioline Syphilis 3.0 Multi', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.14', 'name' => 'HIV Determinor', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.15', 'name' => 'HIV Confirmatory', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.16', 'name' => 'STI Medicines and hygiene materials', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.17', 'name' => 'NSP KIT', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.18', 'name' => 'Condom', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.19', 'name' => 'Nalaxone', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.20', 'name' => 'Medicine', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            ['code' => '22.7.21', 'name' => 'Gas for RDW50', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.7'],
            // 52800 Rent or Lease Payment
            ['code' => '22.8.1', 'name' => 'Office rent Head office', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            ['code' => '22.8.2', 'name' => 'Staff house rent Head office', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            ['code' => '22.8.3', 'name' => 'Office rent project office', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            ['code' => '22.8.4', 'name' => 'Staff house rent project office', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            ['code' => '22.8.5', 'name' => 'Stock rent', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            ['code' => '22.8.6', 'name' => 'HFs Rent', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            ['code' => '22.8.7', 'name' => 'Training Center', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            ['code' => '22.8.8', 'name' => 'Hostel', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.8'],
            // 52900 Other Cost/Operational Expenses
            ['code' => '22.9.1', 'name' => 'Audit fee (pro rated for overall)', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.2', 'name' => 'Patient food (CHC/DH)', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.3', 'name' => 'Food for Students', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.4', 'name' => 'Staff uniforms', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.5', 'name' => 'Take over cost', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.6', 'name' => 'Publication', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.7', 'name' => 'Miscellaneous', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.8', 'name' => 'Emergency response', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.9', 'name' => 'Bank Charges', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.10', 'name' => 'Kitchen Utilities', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.11', 'name' => 'Rehabilitation of skill lab and', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.12', 'name' => 'Inauguration/Graduation Ceremony', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.13', 'name' => 'Diploma cost for graduates', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.14', 'name' => 'Visa Fee/Work permit', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.15', 'name' => 'On Call Visit', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.16', 'name' => 'Patient Cloths', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.17', 'name' => 'Gas for Kitchen', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            ['code' => '22.9.18', 'name' => 'Oxygen Gas', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '22.9'],
            // 51300 Hardship: job titles 51301–51327 (health facility hardship salary breakdown)
            ['code' => '21.3.1', 'name' => 'Hospital Director', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Hospital Director'],
            ['code' => '21.3.2', 'name' => 'Male Nurse', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Male Nurse'],
            ['code' => '21.3.3', 'name' => 'Female Nurse', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Female Nurse'],
            ['code' => '21.3.4', 'name' => 'Midwife', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Midwife'],
            ['code' => '21.3.5', 'name' => 'MD general male', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for MD general male'],
            ['code' => '21.3.6', 'name' => 'MD general female', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for MD general female'],
            ['code' => '21.3.7', 'name' => 'Surgeon', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Surgeon'],
            ['code' => '21.3.8', 'name' => 'Anesthetist', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Anesthetist'],
            ['code' => '21.3.9', 'name' => 'Dentist', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Dentist'],
            ['code' => '21.3.10', 'name' => 'Pharmacist', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Pharmacist'],
            ['code' => '21.3.11', 'name' => 'Salary', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary - general'],
            ['code' => '21.3.12', 'name' => 'Paramedics, Ancillary Services', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Paramedics and ancillary services'],
            ['code' => '21.3.13', 'name' => 'Laboratory tech', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Laboratory technician'],
            ['code' => '21.3.14', 'name' => 'Pharmacy Tech', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Pharmacy Tech'],
            ['code' => '21.3.15', 'name' => 'X-ray tech', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for X-ray technician'],
            ['code' => '21.3.16', 'name' => 'Dental tech', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Dental technician'],
            ['code' => '21.3.17', 'name' => 'Community health supervisor', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Community health supervisor'],
            ['code' => '21.3.18', 'name' => 'Community Mobilizer', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Community Mobilizer'],
            ['code' => '21.3.19', 'name' => 'Health Service Providers', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Health Service Providers'],
            ['code' => '21.3.20', 'name' => 'Vaccinator', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Vaccinator'],
            ['code' => '21.3.21', 'name' => 'Administrator', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Administrator'],
            ['code' => '21.3.22', 'name' => 'Distributor', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Distributor'],
            ['code' => '21.3.23', 'name' => 'Guard', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Guard'],
            ['code' => '21.3.24', 'name' => 'Cleaner', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Cleaner'],
            ['code' => '21.3.25', 'name' => 'Driver (ambulance, if not included elsewhere)', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for ambulance driver'],
            ['code' => '21.3.26', 'name' => 'Out reach for Vaccinator', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for outreach vaccinator'],
            ['code' => '21.3.27', 'name' => 'Pediatrician', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.3', 'description' => 'Hardship salary for Pediatrician'],
            // 51400: job titles 51401–51412
            ['code' => '21.4.1', 'name' => 'Male Nurse', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Male Nurse'],
            ['code' => '21.4.2', 'name' => 'Female Nurse', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Female Nurse'],
            ['code' => '21.4.3', 'name' => 'MW', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Midwife'],
            ['code' => '21.4.4', 'name' => 'MD', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for MD'],
            ['code' => '21.4.5', 'name' => 'Female MD', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Female MD'],
            ['code' => '21.4.6', 'name' => 'Surgeon', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Surgeon'],
            ['code' => '21.4.7', 'name' => 'Anesthetist', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Anesthetist'],
            ['code' => '21.4.8', 'name' => 'Pediatrician', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Pediatrician'],
            ['code' => '21.4.9', 'name' => 'Guard', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Guard'],
            ['code' => '21.4.10', 'name' => 'Cleaner', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Cleaner'],
            ['code' => '21.4.11', 'name' => 'Driver', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Driver'],
            ['code' => '21.4.12', 'name' => 'Night Duty', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.4', 'description' => 'Salary for Night Duty'],
            // 51500 Wages/Incentive: 51501–51505
            ['code' => '21.5.1', 'name' => 'Trainees', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.5', 'description' => 'Trainee wages and incentives'],
            ['code' => '21.5.2', 'name' => 'Wages of workers', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.5', 'description' => 'Wages for workers'],
            ['code' => '21.5.3', 'name' => 'Perdiem for mobile immunization', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.5', 'description' => 'Per diem for mobile immunization activities'],
            ['code' => '21.5.4', 'name' => 'Supervisor perdiem', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.5', 'description' => 'Supervisor per diem'],
            ['code' => '21.5.5', 'name' => 'Perdiem for outreach activity', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.5', 'description' => 'Per diem for outreach activities'],
            ['code' => '21.6.1', 'name' => 'Staff Benefits', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '21.6', 'description' => ''],
            // 53100 Trainings Cost (Management & General)
            ['code' => '23.1.1', 'name' => 'Initial CHW Training', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.2', 'name' => 'Refresher CHW Training', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.3', 'name' => 'Per diems for CHW monthly meetings', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.4', 'name' => 'Outreach per diem for Vaccination', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.5', 'name' => 'RUD', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.6', 'name' => 'Lab', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.7', 'name' => 'Blood Transfusion', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.8', 'name' => 'IP', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.9', 'name' => 'EPI', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.10', 'name' => 'Nutrition', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.11', 'name' => 'Disability', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.12', 'name' => 'New Born Care', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.13', 'name' => 'IMNCI', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.14', 'name' => 'FP', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.15', 'name' => 'PPFP', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.16', 'name' => 'Basic EOC', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.17', 'name' => 'Mental health', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.18', 'name' => 'TB/Malaria', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.19', 'name' => 'HR Management', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.20', 'name' => 'HMIS', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.21', 'name' => 'BCC/IPCC', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.22', 'name' => 'PDQ', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.23', 'name' => 'ETS', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.24', 'name' => 'Gender AW', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.25', 'name' => 'Equip/Main', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.26', 'name' => 'Primary Eye Care', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.27', 'name' => 'Community Midwifery Education Program', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.28', 'name' => 'Community Nursing Program', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.29', 'name' => 'Performance Based Incentive', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.30', 'name' => 'Integrated Outreach services program', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.31', 'name' => 'Quarterly HFs coordination meeting', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.32', 'name' => 'Accreditation Workshop', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.33', 'name' => 'Emergency obstetric care basic', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.34', 'name' => 'Performance Effective Teaching', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.35', 'name' => 'Essential New Born Care Basic Training', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.36', 'name' => 'Supportive Supervision for Project', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.37', 'name' => 'ToT for CHNE master trainees', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.38', 'name' => 'Curriculum review workshop', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.39', 'name' => 'Capacity Building of Staff', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.40', 'name' => 'Rational use of drug Training', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.41', 'name' => 'Medical Ethic Training', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.42', 'name' => 'STI including HIV/AIDS training', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.43', 'name' => 'Trauma Management', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.44', 'name' => 'Nutrition SOP Initial, HFS Consultation', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.45', 'name' => 'Nutrition SOP Refresher, HFS Consultation', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.46', 'name' => 'Financial Management', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.47', 'name' => 'HQIP', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.48', 'name' => 'X-Ray, DH Male and Female MDs', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.49', 'name' => 'Refresher training of CHSs', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.50', 'name' => 'Initial training of CHSs', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.51', 'name' => 'Training FHAG Groups female CHWs', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.52', 'name' => 'Infection prevention TOT for management', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.53', 'name' => 'Refresher training for Midwives', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.54', 'name' => 'Stock management', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            ['code' => '23.1.55', 'name' => 'Drug supplies for Health Posts', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '23.1'],
            // 24100 Medical Equipments (L3 under 24000 — second digit 4, not 2)
            ['code' => '24.1.1', 'name' => 'Anatomical Modules', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.1'],
            ['code' => '24.1.2', 'name' => 'Nursing Skills Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.1'],
            ['code' => '24.1.3', 'name' => 'Medical Equipment', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.1'],
            // 54200 Office Equipments and Furniture
            ['code' => '24.2.1', 'name' => 'Office Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.2', 'name' => 'Generator', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.3', 'name' => 'Computer', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.4', 'name' => 'Photocopier machine', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.5', 'name' => 'Digital Camera', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.6', 'name' => 'Mobile set', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.7', 'name' => 'Multi-media projector', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.8', 'name' => 'Electronic Equipment', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.9', 'name' => 'Furniture and Fixtures', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.10', 'name' => 'IT Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.11', 'name' => 'Vehicle', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.12', 'name' => 'Air conditioning for classroom', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.13', 'name' => 'Stabilizer', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.14', 'name' => 'Refrigerator', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.15', 'name' => 'Fan', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.16', 'name' => 'Laundry setting (washing machine)', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.17', 'name' => 'Office Table', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.18', 'name' => 'Chair', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.19', 'name' => 'Sofa', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.20', 'name' => 'Dining Table', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.21', 'name' => 'Cupboard', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.22', 'name' => 'Bed', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.23', 'name' => 'Blanket', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.24', 'name' => 'Mattress', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.25', 'name' => 'Bed sheets, pillow, etc.', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.26', 'name' => 'Water dispenser', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.27', 'name' => 'Library Materials', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.28', 'name' => 'Motorbike', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.29', 'name' => 'Training Center Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.30', 'name' => 'Hospital Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.31', 'name' => 'Hostel Equipments', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.32', 'name' => 'Intercom', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.33', 'name' => 'Printer', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
            ['code' => '24.2.34', 'name' => 'Laptop Computer', 'type' => 'expense', 'balance' => 'debit', 'parent_code' => '24.2'],
        ];
    }

    /**
     * Fallback parent for rows without parent_code: strip last segment (dotted) or first digit (L2).
     */
    private function deriveParentCode(string $code): string
    {
        if (str_contains($code, '.')) {
            $parts = explode('.', $code);
            array_pop($parts);

            return implode('.', $parts) ?: $code;
        }
        if (strlen($code) >= 2) {
            return substr($code, 0, 1);
        }

        return $code;
    }
}
