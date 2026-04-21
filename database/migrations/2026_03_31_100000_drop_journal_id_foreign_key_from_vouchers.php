<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal books live on the default (central) connection. Vouchers may live in an office-specific
 * financial database. A foreign key from office.vouchers.journal_id → office.journals(id) fails
 * when the journal row exists only on central — typical after creating a book in the UI.
 * Store journal_id as a plain reference (same pattern as inter_office_transfers ↔ vouchers).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vouchers') || ! Schema::hasColumn('vouchers', 'journal_id')) {
            return;
        }

        try {
            Schema::table('vouchers', function (Blueprint $table) {
                $table->dropForeign(['journal_id']);
            });

            return;
        } catch (\Throwable $e) {
            // Fall through: constraint name differs or driver-specific
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $names = ['vouchers_journal_id_foreign'];
        foreach ($names as $constraintName) {
            try {
                DB::statement('ALTER TABLE `vouchers` DROP FOREIGN KEY `'.$constraintName.'`');

                return;
            } catch (\Throwable $e) {
                // try next
            }
        }
    }

    public function down(): void
    {
        // Intentionally not re-adding FK: it breaks office DB + central journals.
    }
};
