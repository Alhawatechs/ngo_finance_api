<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Seeds organization-scope and office-scope permissions for user/role hierarchy.
 * Safe to run multiple times (uses firstOrCreate by name).
 */
class PermissionScopeSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Organization scope: central (main office) admins only
            [
                'name' => 'manage_all_offices',
                'display_name' => 'Manage All Offices',
                'description' => 'Can manage users and roles across all offices in the organization',
                'module' => 'users',
            ],
            [
                'name' => 'view_all_offices_users',
                'display_name' => 'View All Offices Users',
                'description' => 'Can view users from all offices in the organization',
                'module' => 'users',
            ],
            [
                'name' => 'manage_organization_roles',
                'display_name' => 'Manage Organization Roles',
                'description' => 'Can create and edit organization-level (main office) roles',
                'module' => 'security',
            ],
            // Office scope: regional admins – apply only within the user's office
            [
                'name' => 'manage_office_users',
                'display_name' => 'Manage Office Users',
                'description' => 'Can manage users within own office only',
                'module' => 'users',
            ],
            [
                'name' => 'manage_office_roles',
                'display_name' => 'Manage Office Roles',
                'description' => 'Can create and edit roles within own office only',
                'module' => 'security',
            ],
            // Archive Management
            [
                'name' => 'archive.view',
                'display_name' => 'View Archive',
                'description' => 'Can view documents in the archive',
                'module' => 'archive',
            ],
            [
                'name' => 'archive.upload',
                'display_name' => 'Upload to Archive',
                'description' => 'Can upload standalone documents to the archive',
                'module' => 'archive',
            ],
            [
                'name' => 'archive.delete',
                'display_name' => 'Delete from Archive',
                'description' => 'Can delete documents from the archive',
                'module' => 'archive',
            ],
        ];

        foreach ($permissions as $data) {
            Permission::firstOrCreate(
                ['name' => $data['name']],
                array_merge($data, ['guard_name' => 'web'])
            );
        }
    }
}
