<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('user_name')->nullable();
            $table->string('action', 50);
            $table->string('model_type');
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('url')->nullable();
            $table->string('method', 10)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['model_type', 'model_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('documentable_type');
            $table->unsignedBigInteger('documentable_id');
            $table->string('title');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type', 50);
            $table->integer('file_size');
            $table->enum('document_type', ['invoice', 'receipt', 'contract', 'report', 'correspondence', 'other'])->default('other');
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['organization_id', 'document_type']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type');
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read', 'created_at']);
        });

        Schema::create('fund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('grant_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->string('request_number', 50);
            $table->date('request_date');
            $table->enum('request_type', ['dct', 'reimbursement', 'advance', 'other']);
            $table->text('description');
            $table->string('currency', 3)->default('USD');
            $table->decimal('requested_amount', 18, 2);
            $table->decimal('approved_amount', 18, 2)->nullable();
            $table->decimal('received_amount', 18, 2)->default(0);
            $table->date('expected_receipt_date')->nullable();
            $table->date('received_date')->nullable();
            $table->enum('status', ['draft', 'submitted', 'under_review', 'approved', 'partially_received', 'received', 'rejected', 'cancelled'])->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('donation_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'request_number']);
            $table->index(['organization_id', 'grant_id', 'status']);
        });

        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
            $table->foreignId('fiscal_period_id')->constrained()->onDelete('restrict');
            $table->string('payroll_number', 50);
            $table->date('payroll_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('currency', 3)->default('USD');
            $table->decimal('gross_salary', 18, 2);
            $table->decimal('total_deductions', 18, 2);
            $table->decimal('net_salary', 18, 2);
            $table->integer('employee_count');
            $table->enum('status', ['draft', 'submitted', 'approved', 'processed', 'cancelled'])->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('prepared_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'payroll_number']);
            $table->index(['organization_id', 'office_id', 'payroll_date']);
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained()->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('basic_salary', 18, 2);
            $table->decimal('allowances', 18, 2)->default(0);
            $table->decimal('overtime', 18, 2)->default(0);
            $table->decimal('gross_salary', 18, 2);
            $table->decimal('tax_deduction', 18, 2)->default(0);
            $table->decimal('pension_deduction', 18, 2)->default(0);
            $table->decimal('other_deductions', 18, 2)->default(0);
            $table->decimal('total_deductions', 18, 2);
            $table->decimal('net_salary', 18, 2);
            $table->json('deduction_details')->nullable();
            $table->json('allowance_details')->nullable();
            $table->timestamps();

            $table->unique(['payroll_id', 'employee_id']);
        });

        Schema::create('tax_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('fiscal_period_id')->constrained()->onDelete('restrict');
            $table->enum('tax_type', ['salary_withholding', 'contractor_withholding', 'rental_withholding', 'other']);
            $table->date('transaction_date');
            $table->string('reference')->nullable();
            $table->text('description');
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('tax_amount', 18, 2);
            $table->string('payee_name')->nullable();
            $table->string('payee_tax_id', 50)->nullable();
            $table->foreignId('source_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->boolean('is_remitted')->default(false);
            $table->foreignId('remittance_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->date('remittance_date')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'fiscal_period_id', 'tax_type']);
            $table->index(['organization_id', 'is_remitted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_journals');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('fund_requests');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('audit_logs');
    }
};
