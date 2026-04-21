<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('donor_id')->constrained()->onDelete('cascade');
            $table->foreignId('grant_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('fund_id')->nullable()->constrained()->onDelete('set null');
            $table->string('donation_number', 50);
            $table->date('donation_date');
            $table->date('received_date')->nullable();
            $table->enum('donation_type', ['cash', 'in_kind', 'pledge_payment', 'grant_disbursement']);
            $table->text('description');
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('amount', 18, 2);
            $table->decimal('base_currency_amount', 18, 2);
            $table->enum('receipt_method', ['bank_transfer', 'check', 'cash', 'wire_transfer', 'in_kind']);
            $table->string('bank_reference')->nullable();
            $table->string('check_number', 50)->nullable();
            $table->enum('restriction_type', ['unrestricted', 'restricted', 'temporarily_restricted'])->default('unrestricted');
            $table->text('restriction_description')->nullable();
            $table->foreignId('receipt_voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->foreignId('bank_account_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('status', ['pending', 'received', 'acknowledged', 'cancelled'])->default('received');
            $table->date('acknowledgment_date')->nullable();
            $table->text('acknowledgment_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'donation_number']);
            $table->index(['organization_id', 'donor_id', 'donation_date']);
        });

        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('donor_id')->constrained()->onDelete('cascade');
            $table->foreignId('grant_id')->nullable()->constrained()->onDelete('set null');
            $table->string('pledge_number', 50);
            $table->date('pledge_date');
            $table->text('description');
            $table->string('currency', 3)->default('USD');
            $table->decimal('pledged_amount', 18, 2);
            $table->decimal('received_amount', 18, 2)->default(0);
            $table->decimal('outstanding_amount', 18, 2);
            $table->date('expected_fulfillment_date')->nullable();
            $table->enum('payment_schedule', ['one_time', 'monthly', 'quarterly', 'annual', 'custom'])->default('one_time');
            $table->json('payment_schedule_details')->nullable();
            $table->enum('status', ['active', 'partially_fulfilled', 'fulfilled', 'cancelled', 'written_off'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'pledge_number']);
            $table->index(['organization_id', 'donor_id', 'status']);
        });

        Schema::create('pledge_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pledge_id')->constrained()->onDelete('cascade');
            $table->foreignId('donation_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 18, 2);
            $table->date('payment_date');
            $table->timestamps();

            $table->unique(['pledge_id', 'donation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledge_payments');
        Schema::dropIfExists('pledges');
        Schema::dropIfExists('donations');
    }
};
