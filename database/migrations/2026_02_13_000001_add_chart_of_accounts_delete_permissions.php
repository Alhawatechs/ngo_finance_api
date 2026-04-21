<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add permission for permanently deleting chart of accounts.
     * Temporarily delete and restore use existing manage-chart-of-accounts.
     */
    public function up(): void
    {
        $exists = DB::table('permissions')->where('name', 'delete-chart-of-accounts-permanently')->exists();
        if (!$exists) {
            $permId = DB::table('permissions')->insertGetId([
                'name' => 'delete-chart-of-accounts-permanently',
                'display_name' => 'Permanently Delete Chart of Accounts',
                'description' => 'Can permanently delete accounts (frees code for reuse). Requires manage-chart-of-accounts for temporarily delete and restore.',
                'module' => 'finance',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Assign to super-admin and finance-director roles
            $roleIds = DB::table('roles')->whereIn('name', ['super-admin', 'finance-director'])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permId,
                    'role_id' => $roleId,
                ]);
            }

            app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        $perm = DB::table('permissions')->where('name', 'delete-chart-of-accounts-permanently')->first();
        if ($perm) {
            DB::table('role_has_permissions')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }
    }
};
