<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('fiscal_year_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->string('budget_code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('budget_type', ['operational', 'project', 'capital', 'consolidated']);
            $table->string('currency', 3)->default('USD');
            $table->decimal('total_amount', 18, 2);
            $table->integer('version')->default(1);
            $table->enum('status', ['draft', 'submitted', 'approved', 'active', 'revised', 'closed'])->default('draft');
            $table->foreignId('prepared_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'budget_code', 'version']);
            $table->index(['organization_id', 'fiscal_year_id', 'status']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->string('line_code', 50)->nullable();
            $table->text('description')->nullable();
            $table->decimal('annual_amount', 18, 2);
            $table->decimal('q1_amount', 18, 2)->nullable();
            $table->decimal('q2_amount', 18, 2)->nullable();
            $table->decimal('q3_amount', 18, 2)->nullable();
            $table->decimal('q4_amount', 18, 2)->nullable();
            $table->json('monthly_amounts')->nullable();
            $table->decimal('revised_amount', 18, 2)->nullable();
            $table->decimal('actual_amount', 18, 2)->default(0);
            $table->decimal('committed_amount', 18, 2)->default(0);
            $table->decimal('available_amount', 18, 2);
            $table->timestamps();

            $table->unique(['budget_id', 'account_id', 'line_code']);
            $table->index(['budget_id', 'account_id']);
        });

        Schema::create('budget_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->integer('revision_number');
            $table->date('revision_date');
            $table->text('reason');
            $table->decimal('original_amount', 18, 2);
            $table->decimal('revised_amount', 18, 2);
            $table->decimal('change_amount', 18, 2);
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');
            $table->foreignId('requested_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['budget_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_revisions');
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
