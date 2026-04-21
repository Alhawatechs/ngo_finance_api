<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
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
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'payment_number']);
            $table->index(['organization_id', 'vendor_id', 'payment_date']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->onDelete('cascade');
            $table->foreignId('vendor_invoice_id')->constrained()->onDelete('cascade');
            $table->decimal('allocated_amount', 18, 2);
            $table->timestamps();

            $table->unique(['payment_id', 'vendor_invoice_id']);
        });

        Schema::create('advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
            $table->string('advance_number', 50);
            $table->enum('advance_type', ['travel', 'project', 'operational', 'salary', 'other']);
            $table->foreignId('employee_id')->constrained('users')->onDelete('restrict');
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
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'advance_number']);
            $table->index(['organization_id', 'employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advances');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
