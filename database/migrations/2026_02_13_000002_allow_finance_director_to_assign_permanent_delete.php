<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow Finance Director to manage organization roles so they can assign
     * delete-chart-of-accounts-permanently to any staff (role).
     */
    public function up(): void
    {
        $manageOrgRoles = DB::table('permissions')->where('name', 'manage_organization_roles')->first();
        if (!$manageOrgRoles) {
            return;
        }

        $financeDirectorRole = DB::table('roles')->where('name', 'finance-director')->first();
        if (!$financeDirectorRole) {
            return;
        }

        $has = DB::table('role_has_permissions')
            ->where('permission_id', $manageOrgRoles->id)
            ->where('role_id', $financeDirectorRole->id)
            ->exists();

        if (!$has) {
            DB::table('role_has_permissions')->insert([
                'permission_id' => $manageOrgRoles->id,
                'role_id' => $financeDirectorRole->id,
            ]);
        }

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $manageOrgRoles = DB::table('permissions')->where('name', 'manage_organization_roles')->first();
        $financeDirectorRole = DB::table('roles')->where('name', 'finance-director')->first();
        if ($manageOrgRoles && $financeDirectorRole) {
            DB::table('role_has_permissions')
                ->where('permission_id', $manageOrgRoles->id)
                ->where('role_id', $financeDirectorRole->id)
                ->delete();
        }
        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
