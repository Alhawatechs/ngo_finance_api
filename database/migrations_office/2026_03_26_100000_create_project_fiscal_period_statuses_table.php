<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project period close overlay (NGO): rows exist when a project has closed or locked a fiscal period for posting.
 * Run: php artisan migrate --database=... --path=database/migrations_office/2026_03_26_100000_create_project_fiscal_period_statuses_table.php --force
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_fiscal_period_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('fiscal_period_id')->constrained()->onDelete('cascade');
            $table->enum('status', ['closed', 'locked']);
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'fiscal_period_id'], 'pfps_proj_fiscal_uq');
            $table->index(['organization_id', 'project_id'], 'pfps_org_proj_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_fiscal_period_statuses');
    }
};
