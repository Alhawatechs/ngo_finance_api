<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ensures super-admin and finance-director roles have delete-chart-of-accounts-permanently.
 * Run with: php artisan db:seed --class=AssignPermanentDeletePermissionSeeder
 */
class AssignPermanentDeletePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::where('name', 'delete-chart-of-accounts-permanently')->first();
        if (!$permission) {
            $this->command->warn('Permission delete-chart-of-accounts-permanently not found. Run migrations first.');
            return;
        }

        $roles = Role::whereIn('name', ['super-admin', 'finance-director'])->get();
        $fixed = 0;
        foreach ($roles as $role) {
            $has = DB::table('role_has_permissions')
                ->where('permission_id', $permission->id)
                ->where('role_id', $role->id)
                ->exists();
            if (!$has) {
                $role->givePermissionTo($permission->name);
                $this->command->info("Granted permanent delete to: {$role->name} (org {$role->organization_id})");
                $fixed++;
            }
        }

        app()->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command->info("Done. Super Admin and Finance Director can permanently delete accounts." . ($fixed > 0 ? " Fixed {$fixed} role(s)." : ' All roles already have the permission.'));
    }
}
