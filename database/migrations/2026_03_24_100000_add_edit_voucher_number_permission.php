<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optional: allow overriding auto-suggested voucher numbers (e.g. from journal book / coding block).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('permissions')->where('name', 'edit-voucher-number')->exists();
        if ($exists) {
            return;
        }

        DB::table('permissions')->insert([
            'name' => 'edit-voucher-number',
            'display_name' => 'Edit voucher number (override suggestion)',
            'description' => 'Change the voucher number when it was suggested from coding block or journal book.',
            'module' => 'finance',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permId = (int) DB::table('permissions')->where('name', 'edit-voucher-number')->value('id');
        $roleIds = DB::table('roles')->whereIn('name', ['super-admin', 'finance-director'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $perm = DB::table('permissions')->where('name', 'edit-voucher-number')->first();
        if (! $perm) {
            return;
        }
        DB::table('role_has_permissions')->where('permission_id', $perm->id)->delete();
        DB::table('permissions')->where('id', $perm->id)->delete();
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
