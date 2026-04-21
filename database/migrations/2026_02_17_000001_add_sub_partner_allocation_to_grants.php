<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->decimal('sub_partner_allocation_amount', 18, 2)->nullable()->after('partner_details')
                ->comment('Amount allocated from project budget to sub-partner (sub-recipient)');
        });
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn('sub_partner_allocation_amount');
        });
    }
};
