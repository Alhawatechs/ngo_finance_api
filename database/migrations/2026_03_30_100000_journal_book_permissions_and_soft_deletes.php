<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal books: soft deletes, granular permissions, office scoping for provincial staff.
 * Super Admin and Finance Director receive full permissions by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('journals') && ! Schema::hasColumn('journals', 'deleted_at')) {
            Schema::table('journals', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        $now = now();
        $defs = [
            [
                'name' => 'view-journal-books',
                'display_name' => 'View Journal Books',
                'description' => 'List and open journal books for the organization (scoped to own office unless View all journal books applies).',
                'module' => 'finance',
            ],
            [
                'name' => 'view-all-journal-books',
                'display_name' => 'View All Journal Books (all offices)',
                'description' => 'See journal books for every office and province, including head office. Super Admin / Finance Director have this by default.',
                'module' => 'finance',
            ],
            [
                'name' => 'create-journal-books',
                'display_name' => 'Create Journal Books',
                'description' => 'Add new journal books (scoped to own office for provincial users).',
                'module' => 'finance',
            ],
            [
                'name' => 'edit-journal-books',
                'display_name' => 'Edit Journal Books',
                'description' => 'Update journal book name, project, office, province, and status.',
                'module' => 'finance',
            ],
            [
                'name' => 'delete-journal-books',
                'display_name' => 'Delete Journal Books (temporary)',
                'description' => 'Soft-delete journal books and restore deleted books.',
                'module' => 'finance',
            ],
            [
                'name' => 'delete-journal-books-permanently',
                'display_name' => 'Delete Journal Books Permanently',
                'description' => 'Permanently remove soft-deleted journal books when allowed (entries unlinked first).',
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

        $permIds = DB::table('permissions')
            ->whereIn('name', array_column($defs, 'name'))
            ->pluck('id', 'name')
            ->all();

        $fullAccessNames = [
            'view-journal-books',
            'view-all-journal-books',
            'create-journal-books',
            'edit-journal-books',
            'delete-journal-books',
            'delete-journal-books-permanently',
        ];

        $roleIds = DB::table('roles')->whereIn('name', ['super-admin', 'finance-director'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($fullAccessNames as $name) {
                $pid = $permIds[$name] ?? null;
                if ($pid) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $pid,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        // Accountants / finance managers: view + create + edit (no delete-all, no view-all by default)
        $basicNames = ['view-journal-books', 'create-journal-books', 'edit-journal-books'];
        $basicRoleIds = DB::table('roles')->whereIn('name', ['accountant', 'finance-manager'])->pluck('id');
        foreach ($basicRoleIds as $roleId) {
            foreach ($basicNames as $name) {
                $pid = $permIds[$name] ?? null;
                if ($pid) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $pid,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        // Soft delete (restore) for finance-manager
        $delId = $permIds['delete-journal-books'] ?? null;
        if ($delId) {
            $fmIds = DB::table('roles')->where('name', 'finance-manager')->pluck('id');
            foreach ($fmIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $delId,
                    'role_id' => $roleId,
                ]);
            }
        }

        // Viewer: read-only journal books list (scoped)
        $viewId = $permIds['view-journal-books'] ?? null;
        if ($viewId) {
            $viewerIds = DB::table('roles')->where('name', 'viewer')->pluck('id');
            foreach ($viewerIds as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $viewId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('journals') && Schema::hasColumn('journals', 'deleted_at')) {
            Schema::table('journals', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        $names = [
            'view-journal-books',
            'view-all-journal-books',
            'create-journal-books',
            'edit-journal-books',
            'delete-journal-books',
            'delete-journal-books-permanently',
        ];
        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
