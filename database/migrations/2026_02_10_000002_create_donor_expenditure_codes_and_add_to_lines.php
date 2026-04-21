<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Donor expenditure codes: donor/project-specific expense codes for donor reporting.
     * Organization keeps a single CoA for books and financial statements; these codes
     * are used only to report expenditure to donors in their required format.
     */
    public function up(): void
    {
        Schema::create('donor_expenditure_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('donor_id')->nullable()->constrained()->onDelete('set null');
            $table->string('code', 50);
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('donor_expenditure_codes')->onDelete('set null');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'donor_id']);
        });

        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('donor_expenditure_code_id')
                ->nullable()
                ->after('account_id')
                ->constrained('donor_expenditure_codes')
                ->onDelete('set null');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('donor_expenditure_code_id')
                ->nullable()
                ->after('project_id')
                ->constrained('donor_expenditure_codes')
                ->onDelete('set null');
        });

        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->foreignId('donor_expenditure_code_id')
                ->nullable()
                ->after('project_id')
                ->constrained('donor_expenditure_codes')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropForeign(['donor_expenditure_code_id']);
        });
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['donor_expenditure_code_id']);
        });
        Schema::table('voucher_lines', function (Blueprint $table) {
            $table->dropForeign(['donor_expenditure_code_id']);
        });
        Schema::dropIfExists('donor_expenditure_codes');
    }
};
