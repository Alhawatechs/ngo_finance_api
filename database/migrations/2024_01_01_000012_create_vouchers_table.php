<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->string('voucher_number', 50);
            $table->enum('voucher_type', ['payment', 'receipt', 'journal', 'contra']);
            $table->date('voucher_date');
            $table->string('payee_name')->nullable();
            $table->text('description');
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('total_amount', 18, 2);
            $table->decimal('base_currency_amount', 18, 2);
            $table->enum('payment_method', ['cash', 'check', 'bank_transfer', 'mobile_money'])->nullable();
            $table->string('check_number', 50)->nullable();
            $table->string('bank_reference')->nullable();
            $table->enum('status', ['draft', 'submitted', 'pending_approval', 'approved', 'rejected', 'posted', 'cancelled'])->default('draft');
            $table->integer('current_approval_level')->default(0);
            $table->integer('required_approval_level')->default(1);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('submitted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'voucher_number']);
            $table->index(['organization_id', 'office_id', 'voucher_date']);
            $table->index(['organization_id', 'status', 'current_approval_level']);
            $table->index(['organization_id', 'project_id']);
        });

        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('line_number');
            $table->text('description')->nullable();
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->string('cost_center', 50)->nullable();
            $table->timestamps();

            $table->index(['voucher_id', 'account_id']);
        });

        Schema::create('voucher_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->onDelete('cascade');
            $table->integer('approval_level');
            $table->foreignId('approver_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('action', ['pending', 'approved', 'rejected', 'skipped'])->default('pending');
            $table->text('comments')->nullable();
            $table->timestamp('action_at')->nullable();
            $table->timestamps();

            $table->unique(['voucher_id', 'approval_level']);
            $table->index(['voucher_id', 'approval_level', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_approvals');
        Schema::dropIfExists('voucher_lines');
        Schema::dropIfExists('vouchers');
    }
};
