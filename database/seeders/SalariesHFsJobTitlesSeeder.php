<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Seeds salary sub-accounts 21.2.1–21.2.51 under 21.2 Salaries HFs (Health Facilities).
 * Run when 21.2 exists: php artisan db:seed --class=SalariesHFsJobTitlesSeeder
 */
class SalariesHFsJobTitlesSeeder extends Seeder
{
    /** @return list<string> codes 21.2.1 … 21.2.n in this order */
    public static function jobTitles(): array
    {
        return [
            'Hospital Director',
            'Male Nurse',
            'Female Nurse',
            'Midwife',
            'MD general male',
            'MD general female',
            'Surgeon',
            'Anesthetist',
            'Pediatrician',
            'Dentist',
            'Pharmacist',
            'Salary',
            'Paramedics, Ancillary Services',
            'Laboratory tech',
            'Pharmacy Tech',
            'X-ray tech',
            'Dental tech',
            'Community health supervisor',
            'Community Mobilizer',
            'Health Service Providers',
            'Vaccinator',
            'Administrator',
            'Distributor',
            'Guard',
            'Cleaner',
            'Driver (ambulance, if not included elsewhere)',
            'Out reach for Vaccinator',
            'MD Assistant',
            'Internal Specialist',
            'Physiotherapist',
            'Cook',
            'Blood Bank Technician',
            'Nutritionist',
            'G.Practitioner',
            'Ward Nurse',
            'ER (Emergency Room) & OPD Nurse',
            'Obstetricians and Gynaecologist',
            'Radiologist (X-Ray)',
            'OT (Operating Theatre & Sterilization)',
            'Anaesthetics Nurse',
            'Technical Assistant',
            'Maintainance',
            'Focal Point',
            'Data Collection Officer',
            'Nutrition counsellor',
            'Psychosocial counsellor',
            'Peer-Worker',
            'Social Worker',
            'Outreach Coordinator',
            'Facility Coordinator',
            'CHS',
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

        $parent = ChartOfAccount::where('organization_id', $orgId)->where('account_code', '21.2')->first();
        if (! $parent) {
            $this->command->warn('Account 21.2 (Salaries HFs) not found. Ensure NGOChartOfAccountsSeeder ran first.');

            return;
        }

        $jobTitles = self::jobTitles();

        $created = 0;
        foreach ($jobTitles as $i => $name) {
            $code = '21.2.'.((int) $i + 1);
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
                    'description' => 'Salary for '.$name,
                    'is_active' => true,
                    'deleted_at' => null,
                ]
            );
            $created++;
        }

        Cache::forget("coa_tree_{$orgId}_o0_t0");
        Cache::forget("coa_tree_{$orgId}_o0_t1");
        $this->command->info("Salaries HFs job titles: {$created} accounts created under 21.2 (21.2.1–21.2.{$created}).");
    }
}
