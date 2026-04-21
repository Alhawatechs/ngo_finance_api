<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add permission to edit account codes. Without this permission, users cannot change
     * auto-generated account codes. Super Admin and Finance Director get it by default.
     */
    public function up(): void
    {
        $exists = DB::table('permissions')->where('name', 'edit-chart-of-accounts-code')->exists();
        if (!$exists) {
            DB::table('permissions')->insert([
                'name' => 'edit-chart-of-accounts-code',
                'display_name' => 'Edit Chart of Accounts Codes',
                'description' => 'Can change account codes. Without this, account codes are auto-generated and read-only.',
                'module' => 'finance',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        $perm = DB::table('permissions')->where('name', 'edit-chart-of-accounts-code')->first();
        if ($perm) {
            DB::table('role_has_permissions')->where('permission_id', $perm->id)->delete();
            DB::table('permissions')->where('id', $perm->id)->delete();
        }
    }
};
