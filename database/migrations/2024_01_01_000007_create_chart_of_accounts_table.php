<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->onDelete('cascade');
            $table->string('account_code', 20);
            $table->string('account_name');
            $table->enum('account_type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->enum('normal_balance', ['debit', 'credit']);
            $table->integer('level')->default(1)->comment('Hierarchy level 1-4');
            $table->boolean('is_header')->default(false)->comment('Header accounts for grouping');
            $table->boolean('is_posting')->default(true)->comment('Can post transactions');
            $table->boolean('is_bank_account')->default(false);
            $table->boolean('is_cash_account')->default(false);
            $table->boolean('is_control_account')->default(false);
            $table->enum('fund_type', ['unrestricted', 'restricted', 'temporarily_restricted'])->nullable();
            $table->string('currency_code', 3)->nullable()->comment('For foreign currency accounts');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'account_code']);
            $table->index(['organization_id', 'account_type', 'is_active']);
            $table->index(['organization_id', 'parent_id']);
            $table->index(['organization_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
