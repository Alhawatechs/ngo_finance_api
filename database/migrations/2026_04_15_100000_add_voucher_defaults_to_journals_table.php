<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align journal books with voucher header fields: defaults used when recording a voucher into this book.
 * fund_id has no FK — funds may live on the office financial DB while journals stay on central.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journals')) {
            return;
        }

        Schema::table('journals', function (Blueprint $table) {
            if (! Schema::hasColumn('journals', 'location_code')) {
                $table->string('location_code', 1)->nullable()->after('province_code');
            }
            if (! Schema::hasColumn('journals', 'fund_id')) {
                $table->unsignedBigInteger('fund_id')->nullable()->after('location_code');
            }
            if (! Schema::hasColumn('journals', 'currency')) {
                $table->string('currency', 3)->nullable()->after('fund_id');
            }
            if (! Schema::hasColumn('journals', 'exchange_rate')) {
                $table->decimal('exchange_rate', 18, 8)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('journals', 'voucher_type')) {
                $table->string('voucher_type', 20)->nullable()->after('exchange_rate');
            }
            if (! Schema::hasColumn('journals', 'payment_method')) {
                $table->string('payment_method', 30)->nullable()->after('voucher_type');
            }
            if (! Schema::hasColumn('journals', 'default_payee_name')) {
                $table->string('default_payee_name', 255)->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('journals', 'voucher_description_template')) {
                $table->text('voucher_description_template')->nullable()->after('default_payee_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('journals')) {
            return;
        }

        Schema::table('journals', function (Blueprint $table) {
            foreach ([
                'location_code',
                'fund_id',
                'currency',
                'exchange_rate',
                'voucher_type',
                'payment_method',
                'default_payee_name',
                'voucher_description_template',
            ] as $col) {
                if (Schema::hasColumn('journals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
