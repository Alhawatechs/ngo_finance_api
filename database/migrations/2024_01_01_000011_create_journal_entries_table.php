<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
            $table->foreignId('fiscal_period_id')->constrained()->onDelete('restrict');
            $table->string('entry_number', 50);
            $table->date('entry_date');
            $table->date('posting_date')->nullable();
            $table->enum('entry_type', ['standard', 'adjusting', 'closing', 'reversing', 'recurring']);
            $table->string('reference')->nullable();
            $table->text('description');
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('total_debit', 18, 2);
            $table->decimal('total_credit', 18, 2);
            $table->enum('status', ['draft', 'pending', 'posted', 'reversed'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('posted_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->onDelete('set null');
            $table->string('source_type')->nullable()->comment('voucher, payroll, depreciation, etc.');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'entry_number']);
            $table->index(['organization_id', 'office_id', 'entry_date']);
            $table->index(['organization_id', 'fiscal_period_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('office_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('line_number');
            $table->text('description')->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_currency_debit', 18, 2)->default(0);
            $table->decimal('base_currency_credit', 18, 2)->default(0);
            $table->string('reference')->nullable();
            $table->string('cost_center', 50)->nullable();
            $table->json('dimensions')->nullable()->comment('Additional tracking dimensions');
            $table->timestamps();

            $table->index(['journal_entry_id', 'account_id']);
            $table->index(['account_id', 'fund_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};
