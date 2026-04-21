<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('donor_id')->constrained()->onDelete('cascade');
            $table->string('grant_code', 50);
            $table->string('grant_name');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('total_amount', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'active', 'on_hold', 'completed', 'closed'])->default('draft');
            $table->text('terms_conditions')->nullable();
            $table->string('contract_reference')->nullable();
            $table->date('contract_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'grant_code']);
            $table->index(['organization_id', 'donor_id', 'status']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('grant_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('office_id')->nullable()->constrained()->onDelete('set null');
            $table->string('project_code', 50);
            $table->string('project_name');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('budget_amount', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'active', 'on_hold', 'completed', 'closed'])->default('draft');
            $table->string('project_manager')->nullable();
            $table->string('sector', 100)->nullable()->comment('Health, Education, etc.');
            $table->string('location')->nullable();
            $table->integer('beneficiaries_target')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'project_code']);
            $table->index(['organization_id', 'grant_id', 'status']);
            $table->index(['organization_id', 'office_id']);
        });

        Schema::create('project_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('cascade');
            $table->string('budget_line_code', 50)->nullable();
            $table->string('description');
            $table->decimal('budget_amount', 18, 2);
            $table->decimal('revised_amount', 18, 2)->nullable();
            $table->decimal('spent_amount', 18, 2)->default(0);
            $table->decimal('committed_amount', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'account_id', 'budget_line_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_budgets');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('grants');
    }
};
