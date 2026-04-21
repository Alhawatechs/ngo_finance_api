<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances: view page vs edit amounts (delegated by Super Admin / Finance Director).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('permissions')->where('name', 'view-opening-balances')->exists()) {
            DB::table('permissions')->insert([
                'name' => 'view-opening-balances',
                'display_name' => 'View Opening Balances',
                'description' => 'Access the Opening Balances screen and chart of accounts opening amounts.',
                'module' => 'finance',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (! DB::table('permissions')->where('name', 'edit-opening-balances')->exists()) {
            DB::table('permissions')->insert([
                'name' => 'edit-opening-balances',
                'display_name' => 'Edit Opening Balances',
                'description' => 'Enter or update opening balance amounts and as-of dates (posting accounts).',
                'module' => 'finance',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $viewId = (int) DB::table('permissions')->where('name', 'view-opening-balances')->value('id');
        $editId = (int) DB::table('permissions')->where('name', 'edit-opening-balances')->value('id');
        if ($viewId === 0 || $editId === 0) {
            return;
        }

        $roleIds = DB::table('roles')->whereIn('name', ['super-admin', 'finance-director'])->pluck('id');
        foreach ($roleIds as $rid) {
            foreach ([$viewId, $editId] as $pid) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $pid,
                    'role_id' => $rid,
                ]);
            }
        }

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $names = ['view-opening-balances', 'edit-opening-balances'];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('name', $names)->delete();
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
