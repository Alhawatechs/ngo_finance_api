<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Granular chart-of-accounts list permissions (replace broad manage-chart-of-accounts for edit/delete actions).
 * edit-chart-of-accounts: add/update accounts, activate/deactivate.
 * delete-chart-of-accounts: temporarily delete and restore.
 * Existing roles with manage-chart-of-accounts receive both new permissions.
 * Creates general-director role per organization with COA edit/delete + view.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $defs = [
            [
                'name' => 'edit-chart-of-accounts',
                'display_name' => 'Edit Chart of Accounts',
                'description' => 'Add, edit, activate, or deactivate accounts in the chart of accounts list.',
                'module' => 'finance',
            ],
            [
                'name' => 'delete-chart-of-accounts',
                'display_name' => 'Delete Chart of Accounts (temporary)',
                'description' => 'Temporarily delete accounts and restore deleted accounts. Permanent delete uses a separate permission.',
                'module' => 'finance',
            ],
        ];

        foreach ($defs as $def) {
            if (DB::table('permissions')->where('name', $def['name'])->exists()) {
                continue;
            }
            DB::table('permissions')->insert([
                'name' => $def['name'],
                'display_name' => $def['display_name'],
                'description' => $def['description'],
                'module' => $def['module'],
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $editId = DB::table('permissions')->where('name', 'edit-chart-of-accounts')->value('id');
        $deleteId = DB::table('permissions')->where('name', 'delete-chart-of-accounts')->value('id');

        if (! $editId || ! $deleteId) {
            return;
        }

        $editId = (int) $editId;
        $deleteId = (int) $deleteId;

        $managePerm = DB::table('permissions')->where('name', 'manage-chart-of-accounts')->first();
        if ($managePerm) {
            $roleIds = DB::table('role_has_permissions')
                ->where('permission_id', $managePerm->id)
                ->pluck('role_id')
                ->unique()
                ->all();

            foreach ($roleIds as $roleId) {
                foreach ([$editId, $deleteId] as $pid) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $pid,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        $viewPerm = DB::table('permissions')->where('name', 'view-chart-of-accounts')->first();

        $orgIds = DB::table('organizations')->pluck('id');
        foreach ($orgIds as $orgId) {
            $gd = DB::table('roles')
                ->where('organization_id', $orgId)
                ->whereNull('office_id')
                ->where('name', 'general-director')
                ->first();

            if (! $gd) {
                $gdId = DB::table('roles')->insertGetId([
                    'organization_id' => $orgId,
                    'office_id' => null,
                    'name' => 'general-director',
                    'display_name' => 'General Director',
                    'description' => 'Executive oversight; chart of accounts maintenance and key reporting.',
                    'guard_name' => 'web',
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $gdId = (int) $gd->id;
            }

            foreach ([$editId, $deleteId] as $pid) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $pid,
                    'role_id' => $gdId,
                ]);
            }
            if ($viewPerm) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $viewPerm->id,
                    'role_id' => $gdId,
                ]);
            }
        }

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = ['edit-chart-of-accounts', 'delete-chart-of-accounts'];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
