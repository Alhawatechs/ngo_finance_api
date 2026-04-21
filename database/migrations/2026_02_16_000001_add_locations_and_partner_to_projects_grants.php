<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('locations')->nullable()->after('location')->comment('One or more project locations');
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->string('partner_name', 255)->nullable()->after('partner_contribution_amount');
            $table->text('partner_details')->nullable()->after('partner_name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('locations');
        });
        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn(['partner_name', 'partner_details']);
        });
    }
};
