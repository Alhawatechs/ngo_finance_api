<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('office_id')->constrained()->onDelete('cascade');
            $table->foreignId('gl_account_id')->constrained('chart_of_accounts')->onDelete('restrict');
            $table->string('name');
            $table->string('code', 20);
            $table->string('currency', 3)->default('USD');
            $table->enum('cash_type', ['petty_cash', 'main_cash', 'safe']);
            $table->decimal('current_balance', 18, 2)->default(0);
            $table->decimal('limit_amount', 18, 2)->nullable()->comment('Maximum allowed balance');
            $table->foreignId('custodian_id')->nullable()->constrained('users')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'office_id', 'currency']);
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
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
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
            $table->foreignId('counted_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('verified_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['cash_account_id', 'count_date']);
        });

        Schema::create('inter_office_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('transfer_number', 50);
            $table->date('transfer_date');
            $table->foreignId('from_office_id')->constrained('offices')->onDelete('restrict');
            $table->foreignId('to_office_id')->constrained('offices')->onDelete('restrict');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->text('description');
            $table->enum('transfer_method', ['cash', 'bank_transfer', 'check']);
            $table->enum('status', ['pending', 'in_transit', 'received', 'cancelled'])->default('pending');
            $table->foreignId('sent_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->foreignId('received_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('received_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'transfer_number']);
            $table->index(['organization_id', 'from_office_id', 'to_office_id'], 'iot_org_from_to_office_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_office_transfers');
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('cash_accounts');
    }
};
