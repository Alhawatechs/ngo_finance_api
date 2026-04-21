<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_fiscal_period_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('fiscal_period_id')->constrained()->onDelete('cascade');
            /** Absence of a row means project posting is allowed for this period (when org fiscal period is open). */
            $table->enum('status', ['closed', 'locked']);
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Short names: MySQL max identifier length is 64 chars; Laravel default unique name is too long.
            $table->unique(['project_id', 'fiscal_period_id'], 'pfps_proj_fiscal_uq');
            $table->index(['organization_id', 'project_id'], 'pfps_org_proj_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_fiscal_period_statuses');
    }
};
