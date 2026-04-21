<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organization::incrementVoucherNumber('contra') referenced next_contra_voucher_number, which was never added
 * alongside contra_voucher_prefix — caused SQL errors when saving contra vouchers (and could break tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'next_contra_voucher_number')) {
                $table->unsignedInteger('next_contra_voucher_number')->default(1)->after('next_journal_voucher_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'next_contra_voucher_number')) {
                $table->dropColumn('next_contra_voucher_number');
            }
        });
    }
};
