<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Extra demo logins for QA / training (password: password for all).
 * Idempotent — safe to run multiple times (matches by organization + email).
 *
 * php artisan db:seed --class=DemoUsersSeeder
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->first();
        if (! $organization) {
            $this->command?->error('No organization found. Run BootstrapOrgAdminSeeder or migrate:fresh --seed first.');

            return;
        }

        $headOffice = Office::query()
            ->where('organization_id', $organization->id)
            ->where('is_head_office', true)
            ->first()
            ?? Office::query()->where('organization_id', $organization->id)->first();

        if (! $headOffice) {
            $this->command?->error('No office found for this organization.');

            return;
        }

        $regionalOffice = Office::query()
            ->where('organization_id', $organization->id)
            ->where('is_head_office', false)
            ->first();

        $role = fn (string $name) => Role::query()
            ->where('organization_id', $organization->id)
            ->whereNull('office_id')
            ->where('name', $name)
            ->first();

        $defs = [
            [
                'email' => 'demo-finance@aada.org.af',
                'role' => 'finance-manager',
                'office' => $headOffice,
                'name' => 'Demo Finance Manager',
                'employee_id' => 'DEMO-FM-001',
                'position' => 'Finance Manager',
                'approval_level' => 3,
                'approval_limit' => 50000,
                'can_manage_all_offices' => false,
            ],
            [
                'email' => 'demo-accountant@aada.org.af',
                'role' => 'accountant',
                'office' => $headOffice,
                'name' => 'Demo Accountant',
                'employee_id' => 'DEMO-ACC-001',
                'position' => 'Accountant',
                'approval_level' => 1,
                'approval_limit' => 5000,
                'can_manage_all_offices' => false,
            ],
            [
                'email' => 'demo-viewer@aada.org.af',
                'role' => 'viewer',
                'office' => $headOffice,
                'name' => 'Demo Viewer',
                'employee_id' => 'DEMO-VW-001',
                'position' => 'Reporting',
                'approval_level' => 0,
                'approval_limit' => 0,
                'can_manage_all_offices' => false,
            ],
            [
                'email' => 'demo-director@aada.org.af',
                'role' => 'finance-director',
                'office' => $headOffice,
                'name' => 'Demo Finance Director',
                'employee_id' => 'DEMO-FD-001',
                'position' => 'Finance Director',
                'approval_level' => 4,
                'approval_limit' => 500000,
                'can_manage_all_offices' => false,
            ],
            [
                'email' => 'demo-gd@aada.org.af',
                'role' => 'general-director',
                'office' => $headOffice,
                'name' => 'Demo General Director',
                'employee_id' => 'DEMO-GD-001',
                'position' => 'General Director',
                'approval_level' => 4,
                'approval_limit' => 100000,
                'can_manage_all_offices' => false,
            ],
        ];

        if ($regionalOffice) {
            $defs[] = [
                'email' => 'demo-regional@aada.org.af',
                'role' => 'finance-manager',
                'office' => $regionalOffice,
                'name' => 'Demo Regional Finance',
                'employee_id' => 'DEMO-RG-001',
                'position' => 'Regional Finance Manager',
                'approval_level' => 3,
                'approval_limit' => 25000,
                'can_manage_all_offices' => false,
            ];
        }

        foreach ($defs as $row) {
            $r = $role($row['role']);
            if (! $r) {
                $this->command?->warn("Role \"{$row['role']}\" not found — run BootstrapOrgAdminSeeder first. Skipping {$row['email']}.");

                continue;
            }

            $user = User::query()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'email' => $row['email'],
                ],
                [
                    'office_id' => $row['office']->id,
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'password' => 'password',
                    'position' => $row['position'],
                    'department' => 'Finance',
                    'status' => 'active',
                    'approval_level' => $row['approval_level'],
                    'approval_limit' => $row['approval_limit'],
                    'can_manage_all_offices' => $row['can_manage_all_offices'],
                ]
            );

            $user->office_id = $row['office']->id;
            $user->save();

            $user->password = 'password';
            $user->status = 'active';
            $user->save();

            $user->syncRoles([$r]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Demo users ready. Password for all: password');
        $this->command?->table(
            ['Email', 'Role'],
            collect($defs)->map(fn ($d) => [$d['email'], $d['role']])->all()
        );
    }
}
