<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Ensures period-close permissions exist and are assigned:
 * - view-period-close: super-admin, finance-director, finance-manager, accountant
 * - manage-period-close: super-admin, finance-director (default; grant others via Roles UI)
 * - permanently-lock-period-close: super-admin, finance-director (default; grant others via Roles UI)
 *
 * Run: php artisan db:seed --class=AssignPeriodClosePermissionsSeeder
 */
class AssignPeriodClosePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $view = Permission::query()->firstOrCreate(
            ['name' => 'view-period-close'],
            [
                'display_name' => 'View Period Close',
                'description' => 'View project period close status, voucher ranges, and totals.',
                'module' => 'finance',
                'guard_name' => 'web',
            ]
        );

        $manage = Permission::query()->firstOrCreate(
            ['name' => 'manage-period-close'],
            [
                'display_name' => 'Manage Period Close (temporary)',
                'description' => 'Temporarily close or reopen project posting for fiscal periods.',
                'module' => 'finance',
                'guard_name' => 'web',
            ]
        );
        $manage->update([
            'display_name' => 'Manage Period Close (temporary)',
            'description' => 'Temporarily close or reopen project posting for fiscal periods.',
        ]);

        $permanent = Permission::query()->firstOrCreate(
            ['name' => 'permanently-lock-period-close'],
            [
                'display_name' => 'Permanently Lock Period Close',
                'description' => 'Permanently lock project posting for a fiscal period after it has been temporarily closed.',
                'module' => 'finance',
                'guard_name' => 'web',
            ]
        );

        $grant = function (Role $role, Permission $perm): void {
            $has = DB::table('role_has_permissions')
                ->where('permission_id', $perm->id)
                ->where('role_id', $role->id)
                ->exists();
            if (! $has) {
                $role->givePermissionTo($perm->name);
                $this->command?->info("Granted {$perm->name} to {$role->name} (org {$role->organization_id})");
            }
        };

        foreach (Role::query()->whereIn('name', ['super-admin', 'finance-director'])->get() as $role) {
            $grant($role, $view);
            $grant($role, $manage);
            $grant($role, $permanent);
        }

        foreach (Role::query()->whereIn('name', ['finance-manager', 'accountant'])->get() as $role) {
            $grant($role, $view);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('Period close permissions assigned.');
    }
}
