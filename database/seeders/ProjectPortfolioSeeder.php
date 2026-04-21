<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Office;
use App\Models\Grant;
use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectPortfolioSeeder extends Seeder
{
    /**
     * Seed draft NGO projects for the Project Portfolio sub-module.
     * Seeds on default connection for every organization (so any org the user belongs to will have projects).
     * Depends on DatabaseSeeder (organization, offices) and DonorManagementSeeder (grants).
     */
    public function run(): void
    {
        $organizations = Organization::all();
        if ($organizations->isEmpty()) {
            $this->command->warn('Run DatabaseSeeder first (organization required).');
            return;
        }

        foreach ($organizations as $organization) {
            $this->seedProjectsForOrganization($organization->id);
        }

        $this->command->info('Project Portfolio: draft NGO projects seeded.');
    }

    protected function seedProjectsForOrganization(int $orgId): void
    {
        $offices = Office::where('organization_id', $orgId)->get();
        if ($offices->isEmpty()) {
            return;
        }

        $headOffice = $offices->firstWhere('code', 'KBL') ?? $offices->first();
        $kbl = $headOffice;
        $mzr = $offices->firstWhere('code', 'MZR') ?? $kbl;
        $hrt = $offices->firstWhere('code', 'HRT') ?? $kbl;
        $kdh = $offices->firstWhere('code', 'KDH') ?? $kbl;

        $grants = Grant::where('organization_id', $orgId)->get();
        if ($grants->isEmpty()) {
            return;
        }

        $unicefGrant = $grants->firstWhere('grant_code', 'UNICEF-EDU-2024');
        $whoGrant = $grants->firstWhere('grant_code', 'WHO-HEALTH-2024');
        $euGrant = $grants->firstWhere('grant_code', 'EU-LIVELIHOOD-2024');

        $projects = [
            [
                'grant' => $unicefGrant,
                'office' => $kbl,
                'project_code' => 'PRJ-EDU-001',
                'project_name' => 'Non-Formal Education – Kabul',
                'description' => 'Community-based non-formal education and literacy classes for out-of-school children in Kabul.',
                'start_date' => '2025-03-01',
                'end_date' => '2025-12-31',
                'budget_amount' => 120000.00,
                'currency' => 'USD',
                'sector' => 'Education',
                'location' => 'Kabul',
                'beneficiaries_target' => 2500,
                'project_manager' => null,
            ],
            [
                'grant' => $unicefGrant,
                'office' => $mzr,
                'project_code' => 'PRJ-EDU-002',
                'project_name' => 'Teacher Training – Balkh',
                'description' => 'In-service teacher training and classroom support in Balkh province.',
                'start_date' => '2025-04-01',
                'end_date' => '2026-03-31',
                'budget_amount' => 95000.00,
                'currency' => 'USD',
                'sector' => 'Education',
                'location' => 'Balkh',
                'beneficiaries_target' => 1800,
                'project_manager' => null,
            ],
            [
                'grant' => $whoGrant,
                'office' => $kbl,
                'project_code' => 'PRJ-HEALTH-001',
                'project_name' => 'Primary Healthcare – Kabul Province',
                'description' => 'Medical supplies and basic equipment for primary health facilities in Kabul province.',
                'start_date' => '2025-02-01',
                'end_date' => '2025-11-30',
                'budget_amount' => 85000.00,
                'currency' => 'USD',
                'sector' => 'Health',
                'location' => 'Kabul',
                'beneficiaries_target' => 15000,
                'project_manager' => null,
            ],
            [
                'grant' => $whoGrant,
                'office' => $hrt,
                'project_code' => 'PRJ-HEALTH-002',
                'project_name' => 'Medical Supplies Distribution – Herat',
                'description' => 'Distribution of essential medicines and supplies to health centers in Herat.',
                'start_date' => '2025-05-01',
                'end_date' => '2026-04-30',
                'budget_amount' => 72000.00,
                'currency' => 'USD',
                'sector' => 'Health',
                'location' => 'Herat',
                'beneficiaries_target' => 12000,
                'project_manager' => null,
            ],
            [
                'grant' => $euGrant,
                'office' => $hrt,
                'project_code' => 'PRJ-LIV-001',
                'project_name' => 'Vocational Training – Herat',
                'description' => 'Vocational training and skills development for youth and women in Herat.',
                'start_date' => '2025-06-01',
                'end_date' => '2026-05-31',
                'budget_amount' => 140000.00,
                'currency' => 'EUR',
                'sector' => 'Livelihoods',
                'location' => 'Herat',
                'beneficiaries_target' => 600,
                'project_manager' => null,
            ],
            [
                'grant' => $euGrant,
                'office' => $kdh,
                'project_code' => 'PRJ-LIV-002',
                'project_name' => 'Small Business Grants – Kandahar',
                'description' => 'Small business grants and mentorship for entrepreneurs in Kandahar.',
                'start_date' => '2025-07-01',
                'end_date' => '2026-06-30',
                'budget_amount' => 98000.00,
                'currency' => 'EUR',
                'sector' => 'Livelihoods',
                'location' => 'Kandahar',
                'beneficiaries_target' => 400,
                'project_manager' => null,
            ],
            [
                'grant' => $unicefGrant,
                'office' => $kbl,
                'project_code' => 'PRJ-EDU-003',
                'project_name' => 'Education in Emergencies – Pilot',
                'description' => 'Pilot for education in emergencies and temporary learning spaces.',
                'start_date' => '2025-09-01',
                'end_date' => '2026-02-28',
                'budget_amount' => 55000.00,
                'currency' => 'USD',
                'sector' => 'Education',
                'location' => 'Kabul',
                'beneficiaries_target' => 800,
                'project_manager' => null,
            ],
        ];

        foreach ($projects as $data) {
            $grant = $data['grant'];
            $office = $data['office'];
            if (!$grant || !$office) {
                continue;
            }

            Project::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'project_code' => $data['project_code'],
                ],
                [
                    'grant_id' => $grant->id,
                    'office_id' => $office->id,
                    'project_name' => $data['project_name'],
                    'description' => $data['description'],
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'budget_amount' => $data['budget_amount'],
                    'currency' => $data['currency'],
                    'status' => 'draft',
                    'sector' => $data['sector'],
                    'location' => $data['location'],
                    'beneficiaries_target' => $data['beneficiaries_target'],
                    'project_manager' => $data['project_manager'],
                ]
            );
        }
    }
}
