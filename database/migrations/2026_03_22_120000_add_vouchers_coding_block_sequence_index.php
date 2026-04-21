<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speeds up CodingBlockVoucherNumberService::getNextSequence (monthly count per project/province/location).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->index(
                ['organization_id', 'project_id', 'province_code', 'location_code', 'voucher_date'],
                'vouchers_org_proj_prov_loc_date_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex('vouchers_org_proj_prov_loc_date_idx');
        });
    }
};
