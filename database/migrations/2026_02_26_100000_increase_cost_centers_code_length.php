<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Allow longer hierarchical codes (e.g. AB:DH:2078-Want-Waigal).
     */
    public function up(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->string('code', 255)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->string('code', 50)->change();
        });
    }
};
