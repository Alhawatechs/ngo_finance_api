<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?', [$indexName]);
        return count($result) > 0;
    }

    public function up(): void
    {
        // Roles: add office_id (null = org-level/main office) if not present
        if (!Schema::hasColumn('roles', 'office_id')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->foreignId('office_id')->nullable()->after('organization_id')->constrained('offices')->onDelete('cascade');
            });
        }

        // Add new unique first (so FK on organization_id keeps an index), then drop old unique
        if (!$this->indexExists('roles', 'roles_org_office_name_unique')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->unique(['organization_id', 'office_id', 'name'], 'roles_org_office_name_unique');
            });
        }
        if ($this->indexExists('roles', 'roles_organization_id_name_unique')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropUnique(['organization_id', 'name']);
            });
        }

        // Users: email unique per organization, add can_manage_all_offices
        if ($this->indexExists('users', 'users_email_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['email']);
            });
        }
        if (!Schema::hasColumn('users', 'can_manage_all_offices')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('can_manage_all_offices')->default(false)->after('office_id');
            });
        }
        if (!$this->indexExists('users', 'users_organization_id_email_unique')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique(['organization_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_org_office_name_unique');
            $table->unique(['organization_id', 'name']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'email']);
            $table->unique(['email']);
            $table->dropColumn('can_manage_all_offices');
        });
    }
};
