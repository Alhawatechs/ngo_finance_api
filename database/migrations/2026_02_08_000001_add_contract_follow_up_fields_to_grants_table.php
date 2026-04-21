<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->string('location', 255)->nullable()->after('terms_conditions')->comment('Geographical coverage e.g. province/region');
            $table->string('document_type', 100)->nullable()->after('contract_date')->comment('e.g. Programme Document, Amendment');
            $table->decimal('donor_contribution_amount', 18, 2)->nullable()->after('currency');
            $table->decimal('partner_contribution_amount', 18, 2)->nullable()->after('donor_contribution_amount');
        });
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn(['location', 'document_type', 'donor_contribution_amount', 'partner_contribution_amount']);
        });
    }
};
