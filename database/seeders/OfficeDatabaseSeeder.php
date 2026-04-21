<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Illuminate\Database\Seeder;

/**
 * Seeds default chart of accounts (dotted hierarchy) and current fiscal year for an office database.
 * L1 order: 1 Income, 2 Expenses, 3 Assets, 4 Liabilities, 5 Fund Balance.
 */
class OfficeDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedChartOfAccounts();
        $this->seedFiscalYear();
    }

    private function seedChartOfAccounts(): void
    {
        $accounts = [
            ['code' => '3', 'name' => 'Assets', 'type' => 'asset', 'balance' => 'debit', 'level' => 1, 'is_header' => true, 'is_posting' => false],
            ['code' => '31', 'name' => 'Current Assets', 'type' => 'asset', 'balance' => 'debit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '3'],
            ['code' => '31.1', 'name' => 'Cash and Bank', 'type' => 'asset', 'balance' => 'debit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '31'],
            ['code' => '31.1.1', 'name' => 'Operating Cash (Main Account)', 'type' => 'asset', 'balance' => 'debit', 'level' => 4, 'is_cash' => true, 'parent' => '31.1'],
            ['code' => '31.1.2', 'name' => 'Bank - Operating Account', 'type' => 'asset', 'balance' => 'debit', 'level' => 4, 'is_bank' => true, 'parent' => '31.1'],
            ['code' => '31.2', 'name' => 'Receivables and Advances', 'type' => 'asset', 'balance' => 'debit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '31'],
            ['code' => '31.2.1', 'name' => 'Grants/Donor Receivables', 'type' => 'asset', 'balance' => 'debit', 'level' => 4, 'parent' => '31.2'],
            ['code' => '31.2.2', 'name' => 'Employee Advances', 'type' => 'asset', 'balance' => 'debit', 'level' => 4, 'parent' => '31.2'],
            ['code' => '32', 'name' => 'Non-Current Assets', 'type' => 'asset', 'balance' => 'debit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '3'],
            ['code' => '32.1', 'name' => 'Property, Plant & Equipment', 'type' => 'asset', 'balance' => 'debit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '32'],
            ['code' => '32.1.1', 'name' => 'Vehicles', 'type' => 'asset', 'balance' => 'debit', 'level' => 4, 'parent' => '32.1'],
            ['code' => '32.1.2', 'name' => 'Furniture and Fixtures', 'type' => 'asset', 'balance' => 'debit', 'level' => 4, 'parent' => '32.1'],
            ['code' => '32.2', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'balance' => 'credit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '32'],
            ['code' => '32.2.1', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'balance' => 'credit', 'level' => 4, 'parent' => '32.2'],
            ['code' => '4', 'name' => 'Liabilities', 'type' => 'liability', 'balance' => 'credit', 'level' => 1, 'is_header' => true, 'is_posting' => false],
            ['code' => '41', 'name' => 'Current Liabilities', 'type' => 'liability', 'balance' => 'credit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '4'],
            ['code' => '41.1', 'name' => 'Accounts Payable and Accruals', 'type' => 'liability', 'balance' => 'credit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '41'],
            ['code' => '41.1.1', 'name' => 'Accounts Payable - Vendors', 'type' => 'liability', 'balance' => 'credit', 'level' => 4, 'parent' => '41.1'],
            ['code' => '41.1.2', 'name' => 'Withholding Taxes Payable', 'type' => 'liability', 'balance' => 'credit', 'level' => 4, 'parent' => '41.1'],
            ['code' => '41.1.3', 'name' => 'Salary/Wages Payable', 'type' => 'liability', 'balance' => 'credit', 'level' => 4, 'parent' => '41.1'],
            ['code' => '42', 'name' => 'Non-Current Liabilities', 'type' => 'liability', 'balance' => 'credit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '4'],
            ['code' => '5', 'name' => 'Fund Balance', 'type' => 'equity', 'balance' => 'credit', 'level' => 1, 'is_header' => true, 'is_posting' => false],
            ['code' => '51', 'name' => 'Unrestricted Fund Balance', 'type' => 'equity', 'balance' => 'credit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'fund_type' => 'unrestricted', 'parent' => '5'],
            ['code' => '51.1', 'name' => 'Operating Reserve', 'type' => 'equity', 'balance' => 'credit', 'level' => 3, 'fund_type' => 'unrestricted', 'parent' => '51'],
            ['code' => '51.2', 'name' => 'Undesignated Unrestricted', 'type' => 'equity', 'balance' => 'credit', 'level' => 3, 'fund_type' => 'unrestricted', 'parent' => '51'],
            ['code' => '52', 'name' => 'Temporarily Restricted Fund Balance', 'type' => 'equity', 'balance' => 'credit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'fund_type' => 'temporarily_restricted', 'parent' => '5'],
            ['code' => '52.1', 'name' => 'Donor Restricted', 'type' => 'equity', 'balance' => 'credit', 'level' => 3, 'fund_type' => 'temporarily_restricted', 'parent' => '52'],
            ['code' => '1', 'name' => 'Income', 'type' => 'revenue', 'balance' => 'credit', 'level' => 1, 'is_header' => true, 'is_posting' => false],
            ['code' => '11', 'name' => 'Income by source', 'type' => 'revenue', 'balance' => 'credit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '1'],
            ['code' => '11.1', 'name' => 'Grant Revenue', 'type' => 'revenue', 'balance' => 'credit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '11'],
            ['code' => '11.1.1', 'name' => 'Government Grants', 'type' => 'revenue', 'balance' => 'credit', 'level' => 4, 'parent' => '11.1'],
            ['code' => '11.2', 'name' => 'Donations', 'type' => 'revenue', 'balance' => 'credit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '11'],
            ['code' => '11.2.1', 'name' => 'Individual Donations - Unrestricted', 'type' => 'revenue', 'balance' => 'credit', 'level' => 4, 'parent' => '11.2'],
            ['code' => '11.3', 'name' => 'Other Income', 'type' => 'revenue', 'balance' => 'credit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '11'],
            ['code' => '11.3.1', 'name' => 'Interest Income', 'type' => 'revenue', 'balance' => 'credit', 'level' => 4, 'parent' => '11.3'],
            ['code' => '2', 'name' => 'Expenses', 'type' => 'expense', 'balance' => 'debit', 'level' => 1, 'is_header' => true, 'is_posting' => false],
            ['code' => '21', 'name' => 'Personnel Costs', 'type' => 'expense', 'balance' => 'debit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '2'],
            ['code' => '21.1', 'name' => 'Program Personnel', 'type' => 'expense', 'balance' => 'debit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '21'],
            ['code' => '22', 'name' => 'Program & Direct Costs', 'type' => 'expense', 'balance' => 'debit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '2'],
            ['code' => '22.1', 'name' => 'Program Activities', 'type' => 'expense', 'balance' => 'debit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '22'],
            ['code' => '22.1.1', 'name' => 'Goods and Services - Program', 'type' => 'expense', 'balance' => 'debit', 'level' => 4, 'parent' => '22.1'],
            ['code' => '22.1.2', 'name' => 'Staff Travel', 'type' => 'expense', 'balance' => 'debit', 'level' => 4, 'parent' => '22.1'],
            ['code' => '23', 'name' => 'Management & General', 'type' => 'expense', 'balance' => 'debit', 'level' => 2, 'is_header' => true, 'is_posting' => false, 'parent' => '2'],
            ['code' => '23.1', 'name' => 'Management & Administration', 'type' => 'expense', 'balance' => 'debit', 'level' => 3, 'is_header' => true, 'is_posting' => false, 'parent' => '23'],
            ['code' => '23.1.1', 'name' => 'Office Rent & Utilities', 'type' => 'expense', 'balance' => 'debit', 'level' => 4, 'parent' => '23.1'],
            ['code' => '23.1.2', 'name' => 'Office Supplies', 'type' => 'expense', 'balance' => 'debit', 'level' => 4, 'parent' => '23.1'],
            ['code' => '23.1.3', 'name' => 'Telephone & Internet', 'type' => 'expense', 'balance' => 'debit', 'level' => 4, 'parent' => '23.1'],
        ];

        $createdAccounts = [];
        foreach ($accounts as $accountData) {
            $parentId = isset($accountData['parent']) ? ($createdAccounts[$accountData['parent']]->id ?? null) : null;
            $account = ChartOfAccount::create([
                'organization_id' => null,
                'parent_id' => $parentId,
                'account_code' => $accountData['code'],
                'account_name' => $accountData['name'],
                'account_type' => $accountData['type'],
                'normal_balance' => $accountData['balance'],
                'level' => $accountData['level'],
                'is_header' => $accountData['is_header'] ?? false,
                'is_posting' => $accountData['is_posting'] ?? true,
                'is_bank_account' => $accountData['is_bank'] ?? false,
                'is_cash_account' => $accountData['is_cash'] ?? false,
                'fund_type' => $accountData['fund_type'] ?? null,
            ]);
            $createdAccounts[$accountData['code']] = $account;
        }
    }

    private function seedFiscalYear(): void
    {
        $year = (int) date('Y');
        $start = "{$year}-01-01";
        $end = "{$year}-12-31";
        $fy = FiscalYear::create([
            'organization_id' => null,
            'name' => "FY {$year}",
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'open',
            'is_current' => true,
        ]);
        for ($month = 1; $month <= 12; $month++) {
            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate = date('Y-m-t', strtotime($startDate));
            FiscalPeriod::create([
                'fiscal_year_id' => $fy->id,
                'name' => date('F Y', strtotime($startDate)),
                'period_number' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $month <= (int) date('n') ? 'open' : 'draft',
            ]);
        }
    }
}
