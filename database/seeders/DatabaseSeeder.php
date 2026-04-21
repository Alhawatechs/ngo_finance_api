<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Organization;
use App\Models\Office;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\Donor;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create Organization (generic placeholders - update in Organization Setup)
        $organization = Organization::create([
            'name' => 'Organization',
            'short_name' => 'ORG',
            'registration_number' => 'NGO-2010-0123',
            'address' => 'House #123, Street 4, Karte-e-Mamorin',
            'city' => 'Kabul',
            'country' => 'Afghanistan',
            'phone' => '+93 20 2301234',
            'email' => 'info@aada.org.af',
            'website' => 'https://www.aada.org.af',
            'default_currency' => 'AFN',
            'fiscal_year_start_month' => 1,
        ]);

        // Create Offices
        $offices = [
            ['name' => 'Kabul Head Office', 'code' => 'KBL', 'is_head_office' => true, 'city' => 'Kabul', 'province' => 'Kabul'],
            ['name' => 'Mazar-i-Sharif Regional Office', 'code' => 'MZR', 'is_head_office' => false, 'city' => 'Mazar-i-Sharif', 'province' => 'Balkh'],
            ['name' => 'Herat Regional Office', 'code' => 'HRT', 'is_head_office' => false, 'city' => 'Herat', 'province' => 'Herat'],
            ['name' => 'Kandahar Regional Office', 'code' => 'KDH', 'is_head_office' => false, 'city' => 'Kandahar', 'province' => 'Kandahar'],
        ];

        foreach ($offices as $officeData) {
            Office::create(array_merge($officeData, ['organization_id' => $organization->id]));
        }

        $headOffice = Office::where('code', 'KBL')->first();

        // Create Permissions
        $permissions = [
            // User Management
            ['name' => 'manage-users', 'display_name' => 'Manage Users', 'module' => 'users'],
            ['name' => 'view-users', 'display_name' => 'View Users', 'module' => 'users'],
            // Organization scope (central admin)
            ['name' => 'manage_all_offices', 'display_name' => 'Manage All Offices', 'description' => 'Can manage users and roles across all offices', 'module' => 'users'],
            ['name' => 'view_all_offices_users', 'display_name' => 'View All Offices Users', 'description' => 'Can view users from all offices', 'module' => 'users'],
            ['name' => 'manage_organization_roles', 'display_name' => 'Manage Organization Roles', 'description' => 'Can create and edit organization-level roles', 'module' => 'security'],
            // Office scope (regional admin)
            ['name' => 'manage_office_users', 'display_name' => 'Manage Office Users', 'description' => 'Can manage users within own office only', 'module' => 'users'],
            ['name' => 'manage_office_roles', 'display_name' => 'Manage Office Roles', 'description' => 'Can create and edit roles within own office only', 'module' => 'security'],
            // Role Management
            ['name' => 'manage-roles', 'display_name' => 'Manage Roles', 'module' => 'security'],
            
            // Chart of Accounts
            ['name' => 'manage-chart-of-accounts', 'display_name' => 'Manage Chart of Accounts', 'description' => 'Legacy full access; prefer Edit + Delete (temporary) permissions.', 'module' => 'finance'],
            ['name' => 'edit-chart-of-accounts', 'display_name' => 'Edit Chart of Accounts', 'description' => 'Add, edit, activate, or deactivate accounts in the chart list.', 'module' => 'finance'],
            ['name' => 'delete-chart-of-accounts', 'display_name' => 'Delete Chart of Accounts (temporary)', 'description' => 'Temporarily delete and restore accounts.', 'module' => 'finance'],
            ['name' => 'assign-chart-of-accounts-permissions', 'display_name' => 'Assign Chart of Accounts Permissions', 'description' => 'Delegate COA edit/delete permissions to other roles (Super Admin / Finance Director).', 'module' => 'finance'],
            ['name' => 'view-chart-of-accounts', 'display_name' => 'View Chart of Accounts', 'module' => 'finance'],
            ['name' => 'view-opening-balances', 'display_name' => 'View Opening Balances', 'description' => 'Access the Opening Balances screen and opening amounts.', 'module' => 'finance'],
            ['name' => 'edit-opening-balances', 'display_name' => 'Edit Opening Balances', 'description' => 'Update opening balance amounts and as-of dates.', 'module' => 'finance'],

            // Journal books (office-scoped unless View all; Finance Director / Super Admin get full set via role below)
            ['name' => 'view-journal-books', 'display_name' => 'View Journal Books', 'description' => 'List and open journal books (scoped to own office unless View all applies).', 'module' => 'finance'],
            ['name' => 'view-all-journal-books', 'display_name' => 'View All Journal Books (all offices)', 'description' => 'See journal books for every office including head office.', 'module' => 'finance'],
            ['name' => 'create-journal-books', 'display_name' => 'Create Journal Books', 'module' => 'finance'],
            ['name' => 'edit-journal-books', 'display_name' => 'Edit Journal Books', 'module' => 'finance'],
            ['name' => 'delete-journal-books', 'display_name' => 'Delete Journal Books (temporary)', 'description' => 'Soft-delete and restore journal books.', 'module' => 'finance'],
            ['name' => 'delete-journal-books-permanently', 'display_name' => 'Delete Journal Books Permanently', 'description' => 'Permanently remove soft-deleted journal books.', 'module' => 'finance'],
            
            // Vouchers
            ['name' => 'create-voucher', 'display_name' => 'Create Voucher', 'module' => 'finance'],
            ['name' => 'edit-voucher', 'display_name' => 'Edit Voucher', 'module' => 'finance'],
            ['name' => 'view-voucher', 'display_name' => 'View Voucher', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-1', 'display_name' => 'Approve voucher — L1 Finance Controller', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-2', 'display_name' => 'Approve voucher — L2 Finance Manager', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-3', 'display_name' => 'Approve voucher — L3 Finance Director', 'module' => 'finance'],
            ['name' => 'approve-voucher-level-4', 'display_name' => 'Approve voucher — L4 General Director', 'module' => 'finance'],
            
            // Projects
            ['name' => 'manage-projects', 'display_name' => 'Manage Projects', 'module' => 'projects'],
            ['name' => 'view-projects', 'display_name' => 'View Projects', 'module' => 'projects'],
            
            // Budgets
            ['name' => 'manage-budgets', 'display_name' => 'Manage Budgets', 'module' => 'budget'],
            ['name' => 'view-budgets', 'display_name' => 'View Budgets', 'module' => 'budget'],
            
            // Reports
            ['name' => 'view-reports', 'display_name' => 'View Reports', 'module' => 'reports'],
            ['name' => 'export-reports', 'display_name' => 'Export Reports', 'module' => 'reports'],
            
            // Treasury
            ['name' => 'manage-treasury', 'display_name' => 'Manage Treasury', 'module' => 'treasury'],
            ['name' => 'view-treasury', 'display_name' => 'View Treasury', 'module' => 'treasury'],
            
            // Donors
            ['name' => 'manage-donors', 'display_name' => 'Manage Donors', 'module' => 'donors'],
            ['name' => 'view-donors', 'display_name' => 'View Donors', 'module' => 'donors'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::create($permissionData);
        }

        // Create Roles
        $roles = [
            [
                'name' => 'super-admin',
                'display_name' => 'Super Administrator',
                'description' => 'Full system access',
                'is_system' => true,
                'permissions' => Permission::all()->pluck('id')->toArray(), // includes manage_all_offices, view_all_offices_users, manage_organization_roles
            ],
            [
                'name' => 'finance-director',
                'display_name' => 'Finance Director',
                'description' => 'Finance department head with full approval authority',
                'is_system' => true,
                'permissions' => Permission::whereIn('module', ['finance', 'treasury', 'budget', 'reports'])->pluck('id')->toArray(),
            ],
            [
                'name' => 'general-director',
                'display_name' => 'General Director',
                'description' => 'Executive oversight; chart of accounts maintenance and key reporting',
                'is_system' => true,
                'permissions' => Permission::whereIn('name', [
                    'edit-chart-of-accounts',
                    'delete-chart-of-accounts',
                    'view-chart-of-accounts',
                    'view-reports',
                    'export-reports',
                ])->pluck('id')->toArray(),
            ],
            [
                'name' => 'finance-manager',
                'display_name' => 'Finance Manager',
                'description' => 'Finance management with level 3 approval',
                'is_system' => false,
                'permissions' => Permission::whereIn('name', [
                    'view-chart-of-accounts', 'create-voucher', 'edit-voucher', 'view-voucher',
                    'approve-voucher-level-1', 'approve-voucher-level-2', 'approve-voucher-level-3',
                    'view-budgets', 'view-reports', 'view-treasury',
                    'view-journal-books', 'create-journal-books', 'edit-journal-books', 'delete-journal-books',
                ])->pluck('id')->toArray(),
            ],
            [
                'name' => 'accountant',
                'display_name' => 'Accountant',
                'description' => 'Day-to-day accounting operations',
                'is_system' => false,
                'permissions' => Permission::whereIn('name', [
                    'view-chart-of-accounts', 'create-voucher', 'edit-voucher', 'view-voucher',
                    'view-budgets', 'view-reports', 'view-treasury',
                    'view-journal-books', 'create-journal-books', 'edit-journal-books',
                ])->pluck('id')->toArray(),
            ],
            [
                'name' => 'viewer',
                'display_name' => 'Viewer',
                'description' => 'Read-only access to financial data',
                'is_system' => false,
                'permissions' => Permission::where('name', 'like', 'view-%')->pluck('id')->toArray(),
            ],
        ];

        foreach ($roles as $roleData) {
            $permissions = $roleData['permissions'];
            unset($roleData['permissions']);
            $roleData['organization_id'] = $organization->id;
            $roleData['office_id'] = null; // org-level roles (main office)
            
            $role = Role::create($roleData);
            $role->syncPermissions($permissions);
        }

        // Create Users
        $adminRole = Role::where('name', 'super-admin')->first();
        $financeDirectorRole = Role::where('name', 'finance-director')->first();
        $accountantRole = Role::where('name', 'accountant')->first();

        $admin = User::create([
            'organization_id' => $organization->id,
            'office_id' => $headOffice->id,
            'employee_id' => 'EMP-001',
            'name' => 'Ahmad Dost',
            'email' => 'admin@aada.org.af',
            'password' => Hash::make('password'),
            'position' => 'Finance Director',
            'department' => 'Finance',
            'status' => 'active',
            'approval_level' => 4,
            'approval_limit' => 500000,
            'can_manage_all_offices' => true,
        ]);
        $admin->assignRole($adminRole);

        $financeManager = User::create([
            'organization_id' => $organization->id,
            'office_id' => $headOffice->id,
            'employee_id' => 'EMP-002',
            'name' => 'Fatima Ahmadi',
            'email' => 'fatima@aada.org.af',
            'password' => Hash::make('password'),
            'position' => 'Finance Manager',
            'department' => 'Finance',
            'status' => 'active',
            'approval_level' => 3,
            'approval_limit' => 50000,
        ]);
        $financeManager->assignRole($financeDirectorRole);

        $accountant = User::create([
            'organization_id' => $organization->id,
            'office_id' => $headOffice->id,
            'employee_id' => 'EMP-003',
            'name' => 'Mohammad Nazir',
            'email' => 'nazir@aada.org.af',
            'password' => Hash::make('password'),
            'position' => 'Senior Accountant',
            'department' => 'Finance',
            'status' => 'active',
            'approval_level' => 1,
            'approval_limit' => 5000,
        ]);
        $accountant->assignRole($accountantRole);

        // Create Currencies
        $currencies = [
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'is_default' => true],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_default' => false],
            ['code' => 'AFN', 'name' => 'Afghan Afghani', 'symbol' => '؋', 'is_default' => false],
        ];

        foreach ($currencies as $currencyData) {
            Currency::create(array_merge($currencyData, ['organization_id' => $organization->id]));
        }

        // Create Exchange Rates
        $rates = [
            ['from_currency' => 'USD', 'to_currency' => 'AFN', 'rate' => 71.25],
            ['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.08],
            ['from_currency' => 'EUR', 'to_currency' => 'AFN', 'rate' => 76.95],
        ];

        foreach ($rates as $rateData) {
            ExchangeRate::create(array_merge($rateData, [
                'organization_id' => $organization->id,
                'effective_date' => now()->format('Y-m-d'),
                'source' => 'Manual Entry',
                'created_by' => $admin->id,
            ]));
        }

        // Create Fiscal Year and Periods
        $fiscalYear = FiscalYear::create([
            'organization_id' => $organization->id,
            'name' => 'FY 2024',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'open',
            'is_current' => true,
        ]);

        for ($month = 1; $month <= 12; $month++) {
            $startDate = sprintf('2024-%02d-01', $month);
            $endDate = date('Y-m-t', strtotime($startDate));

            FiscalPeriod::create([
                'fiscal_year_id' => $fiscalYear->id,
                'name' => date('F Y', strtotime($startDate)),
                'period_number' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $month <= now()->month ? 'open' : 'draft',
            ]);
        }

        // Create Chart of Accounts (reference NGO workbook: database/seeders/data/reference-coa-hierarchy.json)
        $this->call(NGOChartOfAccountsSeeder::class);
        // Reference chart already includes personnel lines under 21.1 / 21.2; skip bulk job-title seeder.
        // $this->call(ProgramPersonnelJobTitlesSeeder::class);

        // Ensure Super Admin and Finance Director can edit account codes
        $this->call(AssignEditChartOfAccountsCodePermissionSeeder::class);

        // Create Donors
        $donors = [
            ['code' => 'UNICEF', 'name' => 'United Nations Children\'s Fund', 'short_name' => 'UNICEF', 'donor_type' => 'multilateral', 'country' => 'International'],
            ['code' => 'WHO', 'name' => 'World Health Organization', 'short_name' => 'WHO', 'donor_type' => 'multilateral', 'country' => 'International'],
            ['code' => 'EU', 'name' => 'European Union', 'short_name' => 'EU', 'donor_type' => 'multilateral', 'country' => 'Belgium'],
            ['code' => 'USAID', 'name' => 'United States Agency for International Development', 'short_name' => 'USAID', 'donor_type' => 'bilateral', 'country' => 'United States'],
        ];

        foreach ($donors as $donorData) {
            Donor::create(array_merge($donorData, [
                'organization_id' => $organization->id,
                'reporting_currency' => 'USD',
            ]));
        }

        // Donor Management draft data (donors with contacts, grants, donations, pledges)
        $this->call(DonorManagementSeeder::class);

        // Project Portfolio draft NGO projects (depends on grants and offices)
        $this->call(ProjectPortfolioSeeder::class);

        // Additional demo projects (DEMO-PRJ-*; ensures grant if none exist)
        $this->call(DemoProjectsSeeder::class);

        // Budget format templates (Legacy, UNICEF HER, UNFPA WHO)
        $this->call(BudgetFormatTemplateSeeder::class);

        // Extra demo accounts (see DemoUsersSeeder docblock)
        $this->call(DemoUsersSeeder::class);

        // In-app notification bell / slide panel samples (admin@aada.org.af or first user)
        $this->call(SampleNotificationsSeeder::class);
    }
}
