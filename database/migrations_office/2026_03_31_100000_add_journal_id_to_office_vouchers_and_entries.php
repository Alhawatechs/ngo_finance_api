<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office financial DBs store vouchers and journal_entries per office. The central migration
 * `2026_03_22_100000_add_journal_id_to_vouchers` only runs on the default connection.
 *
 * `journal_id` references the central `journals` table (same numeric id); we do not add a
 * foreign key here because `journals` lives on the default connection only.
 *
 * Run: php artisan migrate --database=office_{id} --path=database/migrations_office --force
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            if (! Schema::hasColumn('vouchers', 'journal_id')) {
                $table->unsignedBigInteger('journal_id')->nullable()->after('project_id');
                $table->index('journal_id');
            }
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_entries', 'journal_id')) {
                $table->unsignedBigInteger('journal_id')->nullable()->after('organization_id');
                $table->index('journal_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            if (Schema::hasColumn('vouchers', 'journal_id')) {
                $table->dropColumn('journal_id');
            }
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            if (Schema::hasColumn('journal_entries', 'journal_id')) {
                $table->dropColumn('journal_id');
            }
        });
    }
};
