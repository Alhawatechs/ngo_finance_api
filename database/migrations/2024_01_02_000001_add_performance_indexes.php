<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes on frequently queried columns.
     */
    public function up(): void
    {
        // Chart of accounts - organization_id, parent_id already exists in create migration; add others
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->index(['organization_id', 'account_type']);
            $table->index(['organization_id', 'is_active']);
        });

        // Journal entries - organization_id, entry_date, status, office_id
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'entry_date']);
            $table->index('office_id');
        });

        // Journal entry lines - journal_entry_id, account_id
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->index('journal_entry_id');
            $table->index('account_id');
            $table->index(['project_id', 'account_id']);
            $table->index(['fund_id', 'account_id']);
        });

        // Vouchers - organization_id, status, voucher_date, office_id
        Schema::table('vouchers', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'voucher_date']);
            $table->index('office_id');
        });

        // Vendors - organization_id, is_active
        Schema::table('vendors', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active']);
        });

        // Donors - organization_id
        Schema::table('donors', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active']);
        });

        // Projects - organization_id, grant_id, status, office_id
        Schema::table('projects', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
            $table->index('grant_id');
            $table->index('office_id');
        });

        // Grants - organization_id, donor_id, status
        Schema::table('grants', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
            $table->index('donor_id');
        });

        // Budgets - organization_id, fiscal_year_id, status
        Schema::table('budgets', function (Blueprint $table) {
            $table->index(['organization_id', 'status']);
            $table->index('fiscal_year_id');
        });

        // Funds - organization_id, fund_type, is_active
        Schema::table('funds', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active']);
            $table->index('fund_type');
        });

        // Bank accounts - organization_id, office_id, is_active
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active']);
            $table->index('office_id');
        });

        // Cash accounts - organization_id, office_id, is_active
        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active']);
            $table->index('office_id');
        });

        // Exchange rates - effective_date for date-range lookups (table uses from_currency, to_currency)
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->index('effective_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'account_type']);
            $table->dropIndex(['organization_id', 'is_active']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['organization_id', 'entry_date']);
            $table->dropIndex(['office_id']);
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['journal_entry_id']);
            $table->dropIndex(['account_id']);
            $table->dropIndex(['project_id', 'account_id']);
            $table->dropIndex(['fund_id', 'account_id']);
        });

        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['organization_id', 'voucher_date']);
            $table->dropIndex(['office_id']);
        });

        Schema::table('vendors', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_active']);
        });

        Schema::table('donors', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_active']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['grant_id']);
            $table->dropIndex(['office_id']);
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['donor_id']);
        });

        Schema::table('budgets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropIndex(['fiscal_year_id']);
        });

        Schema::table('funds', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropIndex(['fund_type']);
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropIndex(['office_id']);
        });

        Schema::table('cash_accounts', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropIndex(['office_id']);
        });

        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropIndex(['effective_date']);
        });
    }
};
