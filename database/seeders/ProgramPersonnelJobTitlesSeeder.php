<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds job title / role accounts under 21.1 (Management Salaries).
 * Uses codes 21.1.1 … 21.1.107 (107 roles) — aligned with org PDF export (e.g. chart-of-accounts-2026-03-23).
 * Run after NGOChartOfAccountsSeeder: php artisan db:seed --class=ProgramPersonnelJobTitlesSeeder
 */
class ProgramPersonnelJobTitlesSeeder extends Seeder
{
    /** @return list<string> PDF order; codes 21.1.1 … 21.1.n */
    public static function jobTitles(): array
    {
        return [
            'General Director',
            'Senior Advisor',
            'Health program Director',
            'Training Capacity Building Dir',
            'M&E Program Director',
            'Finance Director',
            'Operation Director',
            'Program Coordinator',
            'Training Coordinator',
            'Technical Manager',
            'CVs Program Manager',
            'CHNL Program Manager',
            'Monitoring Officer/Manager',
            'CBHC Officer/Manager',
            'Pharmacy Manager',
            'RH Manager',
            'Senior Finance Manager',
            'Finance Manager',
            'Logistic Manager',
            'Admin HR Manager',
            'Project Manager',
            'Deputy Technical Manager',
            'Senior M&E HMIS Manager',
            'Deputy Admin/Finance Manager',
            'Finance Controller',
            'Finance Monitor/Supervisor',
            'Provincial Monitors',
            'Finance Officer',
            'Logistic Officer',
            'Procurement Officer',
            'Inventory/IT Officer',
            'Liaison Officer',
            'Admin Officer',
            'HR Officer',
            'Transport Officer',
            'Human Resources Dev or Capacit',
            'RH Officer',
            'HMIS Officer',
            'Quality Assurance Officer',
            'CDC/Hospital Officer',
            'Capacity Dvlpmnt Officer',
            'Nutrition Officer',
            'CBHC Officer',
            'EPI/Lab Officer',
            'Pharmacy Officer',
            'Mental Health/Disability Offic',
            'IMCI Officer',
            'TB Officer',
            'Project Supervisors',
            'Cluster Supervisor',
            'Construction Engineer',
            'Bio Medical Engineer',
            'Trainers',
            'Clinical Trainers',
            'Language and Computer Trainer',
            'Accountant',
            'Cashier',
            'Finance Assistant',
            'RH Assistant',
            'Pharmacy Assistant',
            'EPI Assistant',
            'HMIS Assistant',
            'CBW Trainer',
            'Logistic Assistant',
            'Procurement Assistant',
            'IT Assistant',
            'Inventory Assistant',
            'HR Assistant',
            'Admin Assistant',
            'Office Assistant',
            'Maintenance Assistant',
            'Store Keeper',
            'Receptionist',
            'Mechanic',
            'Guard',
            'Cleaner',
            'Cook',
            'Driver',
            'Laundry',
            'Baby Sitter',
            'Program Manager',
            'Hostel Principle',
            'Training supervisor',
            'Deputy Project Manager',
            'Health Educator',
            'Cluster Manager',
            'Admin/Logistic Officer',
            'Admin/HR Officer',
            'Admin/Logistic Assistant',
            'Admin/HR Assistant',
            'CSO Child survival Office',
            'Health Chief Officer',
            'Operation Manager/Officer',
            'Hospital Direct',
            'Medical Director',
            'Head Nurse',
            'Psychosocial Counsellor (M)',
            'Psychosocial Counsellor (F)',
            'Senior Cluster Manager',
            'EPI Officer',
            'Technical Assistant',
            'Maintenance',
            'EPI Manager',
            'M&E Officer',
            'Head of Internal Audit',
            'EPHS Coordinator',
            'Operation Assistant',
        ];
    }

    public function run(): void
    {
        $organization = Organization::first();
        if (! $organization) {
            $this->command->warn('No organization found. Create an organization first.');

            return;
        }

        $orgId = $organization->id;
        $defaultCurrency = $organization->default_currency ?? 'USD';

        $parent = ChartOfAccount::where('organization_id', $orgId)->where('account_code', '21.1')->first();
        if (! $parent) {
            $this->command->warn('Account 21.1 (Management Salaries) not found. Run NGOChartOfAccountsSeeder first.');

            return;
        }

        $jobTitles = self::jobTitles();

        $created = 0;
        foreach ($jobTitles as $i => $name) {
            $code = '21.1.'.((int) $i + 1);
            ChartOfAccount::withTrashed()->updateOrCreate(
                ['organization_id' => $orgId, 'account_code' => $code],
                [
                    'parent_id' => $parent->id,
                    'account_name' => $name,
                    'account_type' => 'expense',
                    'normal_balance' => 'debit',
                    'level' => 4,
                    'is_header' => false,
                    'is_posting' => true,
                    'currency_code' => $defaultCurrency,
                    'description' => 'Salary/compensation for '.$name,
                    'is_active' => true,
                    'deleted_at' => null,
                ]
            );
            $created++;
        }

        Cache::forget("coa_tree_{$orgId}_o0_t0");
        Cache::forget("coa_tree_{$orgId}_o0_t1");

        $this->command->info("Program Personnel Job Titles: {$created} accounts created under 21.1 (21.1.1+).");
    }
}
