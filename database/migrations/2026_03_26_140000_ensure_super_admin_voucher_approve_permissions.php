<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Permission;
use App\Models\Role;

/**
 * Ensures every Super Administrator role has explicit approve-voucher-level-1..4 permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'approve-voucher-level-1',
            'approve-voucher-level-2',
            'approve-voucher-level-3',
            'approve-voucher-level-4',
        ];

        Role::query()->where('name', 'super-admin')->get()->each(function (Role $role) use ($names) {
            foreach ($names as $permissionName) {
                $perm = Permission::query()->where('name', $permissionName)->first();
                if ($perm && ! $role->hasPermissionTo($perm)) {
                    $role->givePermissionTo($perm);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally left blank — permissions remain valid for super-admin.
    }
};
