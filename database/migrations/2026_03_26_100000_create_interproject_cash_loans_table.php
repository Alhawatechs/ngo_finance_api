<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('interproject_cash_loans')) {
            return;
        }

        Schema::create('interproject_cash_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('lender_project_id')->constrained('projects')->onDelete('restrict');
            $table->foreignId('borrower_project_id')->constrained('projects')->onDelete('restrict');
            $table->string('loan_number', 50);
            $table->date('effective_date');
            $table->date('due_date')->nullable();
            $table->decimal('principal', 18, 2);
            $table->string('currency', 3);
            $table->string('status', 32)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();

            $table->unique(['organization_id', 'loan_number']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interproject_cash_loans');
    }
};
