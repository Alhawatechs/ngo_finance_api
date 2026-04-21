<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Office database schema: financial tables only. No organization_id/office_id.
 * User references are unsignedBigInteger (users live in central DB).
 * Run this migration with --database=<office_connection> and --path=database/migrations_office.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Chart of accounts (organization_id/office_id nullable for model compatibility; DB is the scope)
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->onDelete('cascade');
            $table->string('account_code', 20);
            $table->string('account_name');
            $table->enum('account_type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->integer('level')->default(1);
            $table->boolean('is_header')->default(false);
            $table->boolean('is_posting')->default(true);
            $table->boolean('is_bank_account')->default(false);
            $table->boolean('is_cash_account')->default(false);
            $table->boolean('is_control_account')->default(false);
            $table->enum('fund_type', ['unrestricted', 'restricted', 'temporarily_restricted'])->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('account_code');
            $table->index(['account_type', 'is_active']);
            $table->index('parent_id');
        });

        // 2. Donors
        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('code', 20);
            $table->string('name');
            $table->string('short_name', 50)->nullable();
            $table->enum('donor_type', ['bilateral', 'multilateral', 'foundation', 'corporate', 'individual', 'government']);
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('country', 100)->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->string('reporting_currency', 3)->default('USD');
            $table->string('reporting_frequency', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique('code');
        });

        // 3. Funds
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('donor_id')->nullable()->constrained('donors')->onDelete('set null');
            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('fund_type', ['unrestricted', 'restricted', 'temporarily_restricted']);
            $table->date('restriction_start_date')->nullable();
            $table->date('restriction_end_date')->nullable();
            $table->text('restriction_purpose')->nullable();
            $table->decimal('initial_amount', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique('code');
        });

        // 4. Fiscal years (closed_by = user id in central DB)
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['draft', 'open', 'closed', 'locked'])->default('draft');
            $table->boolean('is_current')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
            $table->unique('name');
            $table->index('is_current');
        });

        // 5. Fiscal periods
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->onDelete('cascade');
            $table->string('name', 50);
            $table->integer('period_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['draft', 'open', 'closed', 'locked'])->default('draft');
            $table->boolean('is_adjustment_period')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
            $table->unique(['fiscal_year_id', 'period_number']);
            $table->index(['fiscal_year_id', 'status']);
        });

        // 6. Grants
        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
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
            $table->unique('grant_code');
            $table->index(['donor_id', 'status']);
        });

        // 7. Projects
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->foreignId('grant_id')->nullable()->constrained()->onDelete('set null');
            $table->string('project_code', 50);
            $table->string('project_name');
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('budget_amount', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'active', 'on_hold', 'completed', 'closed'])->default('draft');
            $table->string('project_manager')->nullable();
            $table->string('sector', 100)->nullable();
            $table->string('location')->nullable();
            $table->integer('beneficiaries_target')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('project_code');
            $table->index(['grant_id', 'status']);
        });

        // 8. Project budgets
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

        // 9. Journal entries (before vouchers for optional journal_entry_id)
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
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
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversal_entry_id')->nullable()->constrained('journal_entries')->onDelete('set null');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('entry_number');
            $table->index(['fiscal_period_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        // 10. Journal entry lines
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
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
            $table->json('dimensions')->nullable();
            $table->timestamps();
            $table->index(['journal_entry_id', 'account_id']);
            $table->index(['account_id', 'fund_id', 'project_id']);
        });

        // 11. Vouchers
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
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
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('voucher_number');
            $table->index(['status', 'current_approval_level']);
            $table->index('project_id');
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
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->enum('action', ['pending', 'approved', 'rejected', 'skipped'])->default('pending');
            $table->text('comments')->nullable();
            $table->timestamp('action_at')->nullable();
            $table->timestamps();
            $table->unique(['voucher_id', 'approval_level']);
        });

        // 12. Bank accounts
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
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
            $table->unique('account_number');
            $table->index('is_active');
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
            $table->unsignedBigInteger('uploaded_by')->nullable();
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
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('approved_by')->nullable();
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

        // 13. Cash accounts
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('office_id')->nullable();
            $table->foreignId('gl_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->string('name');
            $table->string('code', 20);
            $table->string('currency', 3)->default('USD');
            $table->enum('cash_type', ['petty_cash', 'main_cash', 'safe']);
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->decimal('limit_amount', 18, 2)->nullable();
            $table->unsignedBigInteger('custodian_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique('code');
            $table->index('currency');
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('voucher_id')->nullable()->constrained()->onDelete('set null');
            $table->string('transaction_number', 50);
            $table->date('transaction_date');
            $table->enum('transaction_type', ['withdrawal', 'deposit', 'exchange', 'transfer_in', 'transfer_out', 'adjustment']);
            $table->text('description');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('running_balance', 18, 2);
            $table->string('payee_payer')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('related_transaction_id')->nullable()->constrained('cash_transactions')->onDelete('set null');
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index(['cash_account_id', 'transaction_date']);
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_account_id')->constrained()->onDelete('cascade');
            $table->date('count_date');
            $table->decimal('expected_balance', 18, 2);
            $table->decimal('actual_balance', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->json('denomination_details')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('counted_by');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['cash_account_id', 'count_date']);
        });

        // 14. Budgets
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->foreignId('fiscal_year_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('office_id')->nullable();
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
            $table->unsignedBigInteger('prepared_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['budget_code', 'version']);
            $table->index(['fiscal_year_id', 'status']);
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
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['budget_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        $tables = [
            'budget_revisions', 'budget_lines', 'budgets',
            'cash_counts', 'cash_transactions', 'cash_accounts',
            'bank_transactions', 'bank_reconciliations', 'bank_statements', 'bank_accounts',
            'voucher_approvals', 'voucher_lines', 'vouchers',
            'journal_entry_lines', 'journal_entries',
            'project_budgets', 'projects', 'grants',
            'fiscal_periods', 'fiscal_years',
            'funds', 'donors',
            'chart_of_accounts',
        ];
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
