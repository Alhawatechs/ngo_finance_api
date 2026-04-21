<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inter_office_transfers', function (Blueprint $table) {
            $table->dropForeign(['sent_voucher_id']);
            $table->dropForeign(['received_voucher_id']);
        });
        Schema::table('inter_office_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('sent_voucher_id')->nullable()->change();
            $table->unsignedBigInteger('received_voucher_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inter_office_transfers', function (Blueprint $table) {
            $table->foreign('sent_voucher_id')->references('id')->on('vouchers')->onDelete('set null');
            $table->foreign('received_voucher_id')->references('id')->on('vouchers')->onDelete('set null');
        });
    }
};
