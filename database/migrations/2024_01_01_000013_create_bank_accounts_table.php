<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
            $table->foreignId('gl_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->string('bank_name');
            $table->string('branch_name')->nullable();
            $table->string('account_number');
            $table->string('account_name');
            $table->enum('account_type', ['checking', 'savings', 'fixed_deposit', 'money_market']);
            $table->string('currency', 3)->default('USD');
            $table->string('swift_code', 20)->nullable();
            $table->string('iban', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->decimal('available_balance', 18, 2)->default(0);
            $table->date('last_reconciled_date')->nullable();
            $table->decimal('last_reconciled_balance', 18, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'account_number']);
            $table->index(['organization_id', 'office_id', 'is_active']);
        });

        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->onDelete('cascade');
            $table->date('statement_date');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('opening_balance', 18, 2);
            $table->decimal('closing_balance', 18, 2);
            $table->decimal('total_debits', 18, 2)->default(0);
            $table->decimal('total_credits', 18, 2)->default(0);
            $table->integer('transaction_count')->default(0);
            $table->string('statement_file')->nullable();
            $table->enum('status', ['pending', 'imported', 'reconciled'])->default('pending');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->unique(['bank_account_id', 'period_start', 'period_end']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('bank_statement_id')->nullable()->constrained()->onDelete('set null');
            $table->date('reconciliation_date');
            $table->decimal('statement_balance', 18, 2);
            $table->decimal('book_balance', 18, 2);
            $table->decimal('adjusted_book_balance', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->enum('status', ['in_progress', 'completed', 'approved'])->default('in_progress');
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'reconciliation_date']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->onDelete('set null');
            $table->date('transaction_date');
            $table->date('value_date')->nullable();
            $table->enum('transaction_type', ['deposit', 'withdrawal', 'transfer_in', 'transfer_out', 'fee', 'interest', 'check']);
            $table->string('reference')->nullable();
            $table->text('description');
            $table->decimal('debit_amount', 18, 2)->default(0);
            $table->decimal('credit_amount', 18, 2)->default(0);
            $table->decimal('running_balance', 18, 2);
            $table->string('check_number', 50)->nullable();
            $table->string('payee_payer')->nullable();
            $table->boolean('is_reconciled')->default(false);
            $table->foreignId('reconciliation_id')->nullable()->constrained('bank_reconciliations')->onDelete('set null');
            $table->enum('status', ['pending', 'cleared', 'bounced', 'cancelled'])->default('cleared');
            $table->timestamps();

            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['bank_account_id', 'is_reconciled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('bank_accounts');
    }
};
