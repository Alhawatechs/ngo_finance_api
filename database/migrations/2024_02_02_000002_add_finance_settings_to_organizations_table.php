<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Financial Settings
            $table->json('secondary_currencies')->nullable()->after('default_currency');
            $table->unsignedTinyInteger('fiscal_year_end_month')->default(12)->after('fiscal_year_start_month');
            $table->string('accounting_method', 20)->default('accrual')->after('fiscal_year_end_month'); // accrual, cash, modified_cash
            $table->string('budget_control_level', 20)->default('warning')->after('accounting_method'); // none, warning, block
            $table->boolean('allow_negative_budgets')->default(false)->after('budget_control_level');
            $table->boolean('require_budget_check')->default(true)->after('allow_negative_budgets');
            $table->decimal('default_tax_rate', 5, 2)->default(0)->after('require_budget_check');
            $table->boolean('enable_multi_currency')->default(true)->after('default_tax_rate');
            $table->string('exchange_rate_source', 20)->default('manual')->after('enable_multi_currency'); // manual, api
            $table->boolean('cost_center_mandatory')->default(false)->after('exchange_rate_source');
            $table->boolean('project_mandatory')->default(true)->after('cost_center_mandatory');
            $table->boolean('fund_mandatory')->default(true)->after('project_mandatory');
            
            // Document Settings
            $table->string('voucher_number_format', 50)->default('PREFIX-YYYY-NNNN')->after('fund_mandatory');
            $table->string('voucher_number_reset', 20)->default('yearly')->after('voucher_number_format'); // never, yearly, monthly
            $table->string('payment_voucher_prefix', 10)->default('PV')->after('voucher_number_reset');
            $table->string('receipt_voucher_prefix', 10)->default('RV')->after('payment_voucher_prefix');
            $table->string('journal_voucher_prefix', 10)->default('JV')->after('receipt_voucher_prefix');
            $table->string('contra_voucher_prefix', 10)->default('CV')->after('journal_voucher_prefix');
            $table->string('purchase_order_prefix', 10)->default('PO')->after('contra_voucher_prefix');
            $table->string('invoice_prefix', 10)->default('INV')->after('purchase_order_prefix');
            $table->unsignedInteger('next_payment_voucher_number')->default(1)->after('invoice_prefix');
            $table->unsignedInteger('next_receipt_voucher_number')->default(1)->after('next_payment_voucher_number');
            $table->unsignedInteger('next_journal_voucher_number')->default(1)->after('next_receipt_voucher_number');
            $table->unsignedTinyInteger('voucher_print_copies')->default(2)->after('next_journal_voucher_number');
            $table->boolean('show_amount_in_words')->default(true)->after('voucher_print_copies');
            $table->boolean('show_signature_lines')->default(true)->after('show_amount_in_words');
            $table->boolean('require_narration')->default(true)->after('show_signature_lines');
            
            // Approval Settings
            $table->boolean('enable_approval_workflow')->default(true)->after('require_narration');
            $table->unsignedTinyInteger('approval_levels')->default(3)->after('enable_approval_workflow');
            $table->decimal('approval_limit_level1', 15, 2)->default(1000)->after('approval_levels');
            $table->decimal('approval_limit_level2', 15, 2)->default(10000)->after('approval_limit_level1');
            $table->decimal('approval_limit_level3', 15, 2)->default(50000)->after('approval_limit_level2');
            $table->boolean('require_dual_signature')->default(true)->after('approval_limit_level3');
            $table->decimal('dual_signature_threshold', 15, 2)->default(5000)->after('require_dual_signature');
            $table->boolean('allow_self_approval')->default(false)->after('dual_signature_threshold');
            $table->decimal('auto_approve_below', 15, 2)->default(100)->after('allow_self_approval');
            $table->boolean('require_supporting_documents')->default(true)->after('auto_approve_below');
            
            // Extended Leadership/Signatories
            $table->string('authorized_signatory_1', 255)->nullable()->after('finance_director_email');
            $table->string('authorized_signatory_1_title', 100)->nullable()->after('authorized_signatory_1');
            $table->string('authorized_signatory_2', 255)->nullable()->after('authorized_signatory_1_title');
            $table->string('authorized_signatory_2_title', 100)->nullable()->after('authorized_signatory_2');
            $table->string('authorized_signatory_3', 255)->nullable()->after('authorized_signatory_2_title');
            $table->string('authorized_signatory_3_title', 100)->nullable()->after('authorized_signatory_3');
            
            // Extended Banking
            $table->string('primary_bank_iban', 50)->nullable()->after('primary_bank_swift');
            $table->string('secondary_bank_name', 255)->nullable()->after('primary_bank_iban');
            $table->string('secondary_bank_branch', 255)->nullable()->after('secondary_bank_name');
            $table->string('secondary_bank_account', 100)->nullable()->after('secondary_bank_branch');
            $table->boolean('enable_online_banking')->default(false)->after('secondary_bank_account');
            $table->json('payment_methods')->nullable()->after('enable_online_banking');
            
            // System Settings
            $table->string('language', 10)->default('en')->after('number_format');
            $table->boolean('enable_notifications')->default(true)->after('language');
            $table->boolean('enable_email_alerts')->default(true)->after('enable_notifications');
            $table->unsignedSmallInteger('session_timeout')->default(30)->after('enable_email_alerts');
            $table->unsignedSmallInteger('require_password_change')->default(90)->after('session_timeout');
            $table->boolean('enable_two_factor')->default(false)->after('require_password_change');
            $table->unsignedTinyInteger('data_retention_years')->default(7)->after('enable_two_factor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                // Financial Settings
                'secondary_currencies', 'fiscal_year_end_month', 'accounting_method',
                'budget_control_level', 'allow_negative_budgets', 'require_budget_check',
                'default_tax_rate', 'enable_multi_currency', 'exchange_rate_source',
                'cost_center_mandatory', 'project_mandatory', 'fund_mandatory',
                
                // Document Settings
                'voucher_number_format', 'voucher_number_reset',
                'payment_voucher_prefix', 'receipt_voucher_prefix', 'journal_voucher_prefix',
                'contra_voucher_prefix', 'purchase_order_prefix', 'invoice_prefix',
                'next_payment_voucher_number', 'next_receipt_voucher_number', 'next_journal_voucher_number',
                'voucher_print_copies', 'show_amount_in_words', 'show_signature_lines', 'require_narration',
                
                // Approval Settings
                'enable_approval_workflow', 'approval_levels',
                'approval_limit_level1', 'approval_limit_level2', 'approval_limit_level3',
                'require_dual_signature', 'dual_signature_threshold',
                'allow_self_approval', 'auto_approve_below', 'require_supporting_documents',
                
                // Extended Leadership
                'authorized_signatory_1', 'authorized_signatory_1_title',
                'authorized_signatory_2', 'authorized_signatory_2_title',
                'authorized_signatory_3', 'authorized_signatory_3_title',
                
                // Extended Banking
                'primary_bank_iban', 'secondary_bank_name', 'secondary_bank_branch',
                'secondary_bank_account', 'enable_online_banking', 'payment_methods',
                
                // System Settings
                'language', 'enable_notifications', 'enable_email_alerts',
                'session_timeout', 'require_password_change', 'enable_two_factor', 'data_retention_years',
            ]);
        });
    }
};
