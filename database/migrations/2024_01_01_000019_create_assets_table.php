<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('asset_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('set null');
            $table->foreignId('depreciation_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('set null');
            $table->foreignId('accumulated_depreciation_account_id')->nullable()->constrained('chart_of_accounts')->onDelete('set null');
            $table->enum('depreciation_method', ['straight_line', 'declining_balance', 'sum_of_years', 'units_of_production'])->default('straight_line');
            $table->integer('useful_life_years')->default(5);
            $table->decimal('salvage_value_percentage', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained('asset_categories')->onDelete('restrict');
            $table->foreignId('project_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->string('asset_code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('model')->nullable();
            $table->string('manufacturer')->nullable();
            $table->date('acquisition_date');
            $table->enum('acquisition_type', ['purchase', 'donation', 'transfer', 'lease']);
            $table->decimal('acquisition_cost', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('salvage_value', 18, 2)->default(0);
            $table->integer('useful_life_months');
            $table->date('depreciation_start_date');
            $table->decimal('accumulated_depreciation', 18, 2)->default(0);
            $table->decimal('book_value', 18, 2);
            $table->string('location')->nullable();
            $table->foreignId('custodian_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('condition', ['new', 'good', 'fair', 'poor', 'disposed'])->default('good');
            $table->enum('status', ['active', 'inactive', 'under_maintenance', 'disposed', 'lost', 'stolen'])->default('active');
            $table->date('warranty_expiry_date')->nullable();
            $table->date('disposal_date')->nullable();
            $table->decimal('disposal_value', 18, 2)->nullable();
            $table->text('disposal_notes')->nullable();
            $table->foreignId('disposal_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->foreignId('purchase_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'asset_code']);
            $table->index(['organization_id', 'office_id', 'status']);
            $table->index(['organization_id', 'category_id']);
        });

        Schema::create('asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->onDelete('cascade');
            $table->foreignId('fiscal_period_id')->constrained()->onDelete('restrict');
            $table->date('depreciation_date');
            $table->decimal('depreciation_amount', 18, 2);
            $table->decimal('accumulated_depreciation', 18, 2);
            $table->decimal('book_value', 18, 2);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            $table->unique(['asset_id', 'fiscal_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciations');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_categories');
    }
};
