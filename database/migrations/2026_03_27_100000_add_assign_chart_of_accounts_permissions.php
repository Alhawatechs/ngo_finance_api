<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets Super Administrator and Finance Director delegate chart of accounts permissions to other roles.
 * Restricted permissions: edit-chart-of-accounts, delete-chart-of-accounts (temporary),
 * delete-chart-of-accounts-permanently, assign-chart-of-accounts-permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (! DB::table('permissions')->where('name', 'assign-chart-of-accounts-permissions')->exists()) {
            DB::table('permissions')->insert([
                'name' => 'assign-chart-of-accounts-permissions',
                'display_name' => 'Assign Chart of Accounts Permissions',
                'description' => 'Delegate Edit / Delete (temporary) / Permanently delete chart of accounts permissions to other roles. Typically held by Super Administrator and Finance Director.',
                'module' => 'finance',
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $assignId = (int) DB::table('permissions')->where('name', 'assign-chart-of-accounts-permissions')->value('id');
        if (! $assignId) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', ['super-admin', 'finance-director'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $assignId,
                'role_id' => $roleId,
            ]);
        }

        $permanentId = DB::table('permissions')->where('name', 'delete-chart-of-accounts-permanently')->value('id');
        if ($permanentId) {
            $delegatorRoleIds = DB::table('role_has_permissions')
                ->where('permission_id', $permanentId)
                ->pluck('role_id');
            foreach ($delegatorRoleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $assignId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $perm = DB::table('permissions')->where('name', 'assign-chart-of-accounts-permissions')->first();
        if ($perm) {
            DB::table('role_has_permissions')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
