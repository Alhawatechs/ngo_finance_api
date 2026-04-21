<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Option 2: keep existing org/data — ensure head office, permissions, roles, and one super-admin user.
 * Safe to run multiple times (idempotent).
 *
 * php artisan db:seed --class=BootstrapOrgAdminSeeder
 */
class BootstrapOrgAdminSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->first();
        if (! $organization) {
            $this->command?->error('No organization found. Create one first or run php artisan migrate:fresh --seed.');

            return;
        }

        $headOffice = Office::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'code' => 'KBL',
            ],
            [
                'name' => 'Kabul Head Office',
                'is_head_office' => true,
                'city' => $organization->city ?? 'Kabul',
                'province' => $organization->city ?? 'Kabul',
            ]
        );

        foreach ($this->permissionDefinitions() as $row) {
            $name = $row['name'];
            unset($row['name']);
            Permission::query()->firstOrCreate(
                ['name' => $name],
                array_merge($row, ['guard_name' => 'web'])
            );
        }

        $roleDefs = [
            [
                'name' => 'super-admin',
                'display_name' => 'Super Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permission_ids' => fn () => Permission::query()->pluck('id')->all(),
            ],
            [
                'name' => 'finance-director',
                'display_name' => 'Finance Director',
                'description' => 'Finance department head with full approval authority',
                'is_system' => true,
                'permission_ids' => fn () => Permission::query()->whereIn('module', ['finance', 'treasury', 'budget', 'reports'])->pluck('id')->all(),
            ],
            [
                'name' => 'general-director',
                'display_name' => 'General Director',
                'description' => 'Executive oversight; chart of accounts maintenance and key reporting',
                'is_system' => true,
                'permission_ids' => fn () => Permission::query()->whereIn('name', [
                    'edit-chart-of-accounts',
                    'delete-chart-of-accounts',
                    'view-chart-of-accounts',
                    'view-reports',
                    'export-reports',
                ])->pluck('id')->all(),
            ],
            [
                'name' => 'finance-manager',
                'display_name' => 'Finance Manager',
                'description' => 'Finance management with level 3 approval',
                'is_system' => false,
                'permission_ids' => fn () => Permission::query()->whereIn('name', [
                    'view-chart-of-accounts', 'create-voucher', 'edit-voucher', 'view-voucher',
                    'approve-voucher-level-1', 'approve-voucher-level-2', 'approve-voucher-level-3',
                    'view-budgets', 'view-reports', 'view-treasury',
                    'view-journal-books', 'create-journal-books', 'edit-journal-books', 'delete-journal-books',
                    'view-period-close',
                ])->pluck('id')->all(),
            ],
            [
                'name' => 'accountant',
                'display_name' => 'Accountant',
                'description' => 'Day-to-day accounting operations',
                'is_system' => false,
                'permission_ids' => fn () => Permission::query()->whereIn('name', [
                    'view-chart-of-accounts', 'create-voucher', 'edit-voucher', 'view-voucher',
                    'view-budgets', 'view-reports', 'view-treasury',
                    'view-journal-books', 'create-journal-books', 'edit-journal-books',
                    'view-period-close',
                ])->pluck('id')->all(),
            ],
            [
                'name' => 'viewer',
                'display_name' => 'Viewer',
                'description' => 'Read-only access to financial data',
                'is_system' => false,
                'permission_ids' => fn () => Permission::query()->where('name', 'like', 'view-%')->pluck('id')->all(),
            ],
        ];

        foreach ($roleDefs as $def) {
            $permFn = $def['permission_ids'];
            unset($def['permission_ids']);
            $roleName = $def['name'];
            unset($def['name']);
            $ids = $permFn();

            $role = Role::query()->firstOrCreate(
                [
                    'organization_id' => $organization->id,
                    'office_id' => null,
                    'name' => $roleName,
                ],
                array_merge($def, ['guard_name' => 'web', 'name' => $roleName])
            );

            $role->syncPermissions($ids);
        }

        $adminRole = Role::query()
            ->where('organization_id', $organization->id)
            ->whereNull('office_id')
            ->where('name', 'super-admin')
            ->first();

        $user = User::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'email' => 'admin@aada.org.af',
            ],
            [
                'office_id' => $headOffice->id,
                'employee_id' => 'EMP-001',
                'name' => 'System Administrator',
                'password' => 'password',
                'position' => 'Administrator',
                'department' => 'IT',
                'status' => 'active',
                'approval_level' => 4,
                'approval_limit' => 500000,
                'can_manage_all_offices' => true,
            ]
        );

        if ($user->office_id === null) {
            $user->office_id = $headOffice->id;
            $user->save();
        }

        $user->password = 'password';
        $user->status = 'active';
        $user->save();

        if ($adminRole) {
            $user->syncRoles([$adminRole]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Bootstrap complete. Login: admin@aada.org.af / password');
    }

    /**
     * @return list<array{name: string, display_name: string, module?: string, description?: string}>
     */
    private function permissionDefinitions(): array
    {
        return [
            ['name' => 'manage-users', 'display_name' => 'Manage Users', 'module' => 'users'],
            ['name' => 'view-users', 'display_name' => 'View Users', 'module' => 'users'],
            ['name' => 'manage_all_offices', 'display_name' => 'Manage All Offices', 'description' => 'Can manage users and roles across all offices', 'module' => 'users'],
            ['name' => 'view_all_offices_users', 'display_name' => 'View All Offices Users', 'description' => 'Can view users from all offices', 'module' => 'users'],
            ['name' => 'manage_organization_roles', 'display_name' => 'Manage Organization Roles', 'description' => 'Can create and edit organization-level roles', 'module' => 'security'],
            ['name' => 'manage_office_users', 'display_name' => 'Manage Office Users', 'description' => 'Can manage users within own office only', 'module' => 'users'],
            ['name' => 'manage_office_roles', 'display_name' => 'Manage Office Roles', 'description' => 'Can create and edit roles within own office only', 'module' => 'security'],
            ['name' => 'manage-roles', 'display_name' => 'Manage Roles', 'module' => 'security'],
            ['name' => 'manage-chart-of-accounts', 'display_name' => 'Manage Chart of Accounts', 'description' => 'Legacy full access; prefer Edit + Delete (temporary) permissions.', 'module' => 'finance'],
            ['name' => 'edit-chart-of-accounts', 'display_name' => 'Edit Chart of Accounts', 'description' => 'Add, edit, activate, or deactivate accounts in the chart list.', 'module' => 'finance'],
            ['name' => 'delete-chart-of-accounts', 'display_name' => 'Delete Chart of Accounts (temporary)', 'description' => 'Temporarily delete and restore accounts.', 'module' => 'finance'],
            ['name' => 'assign-chart-of-accounts-permissions', 'display_name' => 'Assign Chart of Accounts Permissions', 'description' => 'Delegate COA edit/delete permissions to other roles (Super Admin / Finance Director).', 'module' => 'finance'],
            ['name' => 'view-chart-of-accounts', 'display_name' => 'View Chart of Accounts', 'module' => 'finance'],
            ['name' => 'view-opening-balances', 'display_name' => 'View Opening Balances', 'description' => 'Access the Opening Balances screen and opening amounts.', 'module' => 'finance'],
            ['name' => 'edit-opening-balances', 'display_name' => 'Edit Opening Balances', 'description' => 'Update opening balance amounts and as-of dates.', 'module' => 'finance'],
            ['name' => 'view-journal-books', 'display_name' => 'View Journal Books', 'description' => 'List and open journal books (scoped to own office unless View all applies).', 'module' => 'finance'],
            ['name' => 'view-all-journal-books', 'display_name' => 'View All Journal Books (all offices)', 'description' => 'See journal books for every office including head office.', 'module' => 'finance'],
            ['name' => 'create-journal-books', 'display_name' => 'Create Journal Books', 'module' => 'finance'],
            ['name' => 'edit-journal-books', 'display_name' => 'Edit Journal Books', 'module' => 'finance'],
            ['name' => 'delete-journal-books', 'display_name' => 'Delete Journal Books (temporary)', 'description' => 'Soft-delete and restore journal books.', 'module' => 'finance'],
            ['name' => 'delete-journal-books-permanently', 'display_name' => 'Delete Journal Books Permanently', 'description' => 'Permanently remove soft-deleted journal books.', 'module' => 'finance'],
            ['name' => 'create-voucher', 'display_name' => 'Create Voucher', 'module' => 'finance'],
            ['name' => 'edit-voucher', 'display_name' => 'Edit Voucher', 'module' => 'finance'],
            ['name' => 'view-voucher', 'display_name' => 'View Voucher', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-1', 'display_name' => 'Approve voucher — L1 Finance Controller', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-2', 'display_name' => 'Approve voucher — L2 Finance Manager', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-3', 'display_name' => 'Approve voucher — L3 Finance Director', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-4', 'display_name' => 'Approve voucher — L4 General Director', 'module' => 'finance'],
            ['name' => 'manage-projects', 'display_name' => 'Manage Projects', 'module' => 'projects'],
            ['name' => 'view-projects', 'display_name' => 'View Projects', 'module' => 'projects'],
            ['name' => 'manage-budgets', 'display_name' => 'Manage Budgets', 'module' => 'budget'],
            ['name' => 'view-budgets', 'display_name' => 'View Budgets', 'module' => 'budget'],
            ['name' => 'view-reports', 'display_name' => 'View Reports', 'module' => 'reports'],
            ['name' => 'export-reports', 'display_name' => 'Export Reports', 'module' => 'reports'],
            ['name' => 'manage-treasury', 'display_name' => 'Manage Treasury', 'module' => 'treasury'],
            ['name' => 'view-treasury', 'display_name' => 'View Treasury', 'module' => 'treasury'],
            ['name' => 'manage-donors', 'display_name' => 'Manage Donors', 'module' => 'donors'],
            ['name' => 'view-donors', 'display_name' => 'View Donors', 'module' => 'donors'],
            ['name' => 'view-period-close', 'display_name' => 'View Period Close', 'description' => 'View project period close status, voucher ranges, and totals.', 'module' => 'finance'],
            ['name' => 'manage-period-close', 'display_name' => 'Manage Period Close (temporary)', 'description' => 'Temporarily close or reopen project posting for fiscal periods.', 'module' => 'finance'],
            ['name' => 'permanently-lock-period-close', 'display_name' => 'Permanently Lock Period Close', 'description' => 'Permanently lock project posting for a fiscal period after it has been temporarily closed.', 'module' => 'finance'],
        ];
    }
}
