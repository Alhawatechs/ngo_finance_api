<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office DB: vendors, vendor_invoices, payments, advances, departments.
 * User references are unsignedBigInteger (users in central DB).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Vendors (ap_account_id -> chart_of_accounts)
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('vendor_code', 20);
            $table->string('name');
            $table->enum('vendor_type', ['supplier', 'contractor', 'consultant', 'service_provider', 'other']);
            $table->string('tax_id', 50)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('payment_terms', 50)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('credit_limit', 18, 2)->nullable();
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->foreignId('ap_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('vendor_code');
        });

        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->foreignId('vendor_id')->constrained()->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->string('invoice_number', 50);
            $table->string('vendor_invoice_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date');
            $table->date('received_date')->nullable();
            $table->text('description');
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('balance_due', 18, 2);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'partially_paid', 'paid', 'cancelled'])->default('draft');
            $table->foreignId('voucher_id')->nullable()->constrained()->onDelete('set null');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();
            $table->unique('invoice_number');
            $table->index(['vendor_id', 'status']);
            $table->index('due_date');
        });

        Schema::create('vendor_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_invoice_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('line_number');
            $table->text('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('cost_center', 50)->nullable();
            $table->timestamps();
            $table->index(['vendor_invoice_id', 'account_id']);
        });

        // Payments
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->onDelete('set null');
            $table->string('payment_number', 50);
            $table->date('payment_date');
            $table->enum('payment_type', ['vendor_payment', 'expense_payment', 'advance', 'refund']);
            $table->enum('payment_method', ['cash', 'check', 'bank_transfer', 'mobile_money']);
            $table->foreignId('bank_account_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('cash_account_id')->nullable()->constrained()->onDelete('set null');
            $table->string('payee_name');
            $table->text('description');
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('amount', 18, 2);
            $table->decimal('base_currency_amount', 18, 2);
            $table->string('check_number', 50)->nullable();
            $table->date('check_date')->nullable();
            $table->string('bank_reference')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'processed', 'cancelled', 'bounced'])->default('draft');
            $table->foreignId('voucher_id')->nullable()->constrained()->onDelete('set null');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('payment_number');
            $table->index(['vendor_id', 'payment_date']);
            $table->index('status');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->foreignId('vendor_invoice_id')->constrained()->onDelete('cascade');
            $table->decimal('allocated_amount', 18, 2);
            $table->timestamps();
            $table->unique(['payment_id', 'vendor_invoice_id']);
        });

        // Advances (employee_id = user id in central)
        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->string('advance_number', 50);
            $table->enum('advance_type', ['travel', 'project', 'operational', 'salary', 'other']);
            $table->unsignedBigInteger('employee_id');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->date('advance_date');
            $table->date('expected_settlement_date');
            $table->text('purpose');
            $table->string('currency', 3)->default('USD');
            $table->decimal('amount', 18, 2);
            $table->decimal('settled_amount', 18, 2)->default(0);
            $table->decimal('outstanding_amount', 18, 2);
            $table->enum('status', ['pending', 'approved', 'disbursed', 'partially_settled', 'settled', 'cancelled'])->default('pending');
            $table->foreignId('disbursement_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->foreignId('settlement_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('advance_number');
            $table->index(['employee_id', 'status']);
        });

        // Departments (office-scoped; manager_id = user id in central)
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('departments')->onDelete('set null');
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique('code');
            $table->index('is_active');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('advances');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('vendor_invoice_lines');
        Schema::dropIfExists('vendor_invoices');
        Schema::dropIfExists('vendors');
    }
};
