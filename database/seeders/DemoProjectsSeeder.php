<?php

namespace Database\Seeders;

use App\Models\Donor;
use App\Models\Grant;
use App\Models\Office;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Seeder;

/**
 * Sample projects for the portfolio (idempotent on project_code per organization).
 * Creates a demo donor + grant if the org has no grants yet.
 *
 * php artisan db:seed --class=DemoProjectsSeeder
 */
class DemoProjectsSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = Organization::query()->get();
        if ($organizations->isEmpty()) {
            $this->command?->error('No organization found. Run BootstrapOrgAdminSeeder or migrate:fresh --seed first.');

            return;
        }

        foreach ($organizations as $organization) {
            $this->seedForOrganization($organization);
        }

        $this->command?->info('Demo projects seeded (codes DEMO-PRJ-*).');
    }

    protected function seedForOrganization(Organization $organization): void
    {
        $orgId = $organization->id;

        $offices = Office::query()->where('organization_id', $orgId)->get();
        if ($offices->isEmpty()) {
            $this->command?->warn("Organization {$orgId}: no offices — skipping demo projects.");

            return;
        }

        $headOffice = $offices->firstWhere('is_head_office', true) ?? $offices->first();
        $regional = $offices->firstWhere('is_head_office', false) ?? $headOffice;

        $grant = $this->ensureDemoGrant($orgId);

        $rows = [
            [
                'project_code' => 'DEMO-PRJ-001',
                'project_name' => 'WASH — Community water systems',
                'description' => 'Demo: rehabilitation of water points and hygiene promotion in target communities.',
                'office' => $headOffice,
                'start_date' => '2025-01-15',
                'end_date' => '2025-12-31',
                'budget_amount' => 185000.00,
                'currency' => 'USD',
                'status' => 'draft',
                'sector' => 'WASH',
                'location' => $headOffice->city ?? 'Kabul',
                'beneficiaries_target' => 3200,
            ],
            [
                'project_code' => 'DEMO-PRJ-002',
                'project_name' => 'Livelihoods — Agriculture inputs',
                'description' => 'Demo: seasonal seed and tool distribution with extension support.',
                'office' => $headOffice,
                'start_date' => '2025-03-01',
                'end_date' => '2026-02-28',
                'budget_amount' => 240000.00,
                'currency' => 'USD',
                'status' => 'active',
                'sector' => 'Agriculture',
                'location' => $headOffice->city ?? 'Kabul',
                'beneficiaries_target' => 1500,
            ],
            [
                'project_code' => 'DEMO-PRJ-003',
                'project_name' => 'Protection — Child-friendly spaces',
                'description' => 'Demo: safe spaces and psychosocial support for children.',
                'office' => $regional,
                'start_date' => '2025-04-01',
                'end_date' => '2026-03-31',
                'budget_amount' => 128000.00,
                'currency' => 'USD',
                'status' => 'planning',
                'sector' => 'Protection',
                'location' => $regional->city ?? 'Regional',
                'beneficiaries_target' => 900,
            ],
            [
                'project_code' => 'DEMO-PRJ-004',
                'project_name' => 'M&E — Baseline and surveys',
                'description' => 'Demo: household baseline and midline data collection.',
                'office' => $headOffice,
                'start_date' => '2025-06-01',
                'end_date' => '2025-11-30',
                'budget_amount' => 45000.00,
                'currency' => 'USD',
                'status' => 'draft',
                'sector' => 'M&E',
                'location' => 'National',
                'beneficiaries_target' => null,
            ],
        ];

        foreach ($rows as $data) {
            $office = $data['office'];
            unset($data['office']);

            Project::query()->firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'project_code' => $data['project_code'],
                ],
                array_merge($data, [
                    'grant_id' => $grant->id,
                    'office_id' => $office->id,
                    'project_manager' => null,
                ])
            );
        }
    }

    protected function ensureDemoGrant(int $organizationId): Grant
    {
        $existing = Grant::query()->where('organization_id', $organizationId)->first();
        if ($existing) {
            return $existing;
        }

        $donor = Donor::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'code' => 'DEMO-DONOR',
            ],
            [
                'name' => 'Demo funding partner',
                'short_name' => 'Demo',
                'donor_type' => 'multilateral',
                'country' => 'International',
                'reporting_currency' => 'USD',
            ]
        );

        return Grant::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'grant_code' => 'DEMO-GRANT-2025',
            ],
            [
                'donor_id' => $donor->id,
                'grant_name' => 'Multi-sector demonstration grant',
                'description' => 'Auto-created by DemoProjectsSeeder for sample projects.',
                'start_date' => '2025-01-01',
                'end_date' => '2026-12-31',
                'total_amount' => 1_000_000.00,
                'currency' => 'USD',
                'status' => 'active',
            ]
        );
    }
}
