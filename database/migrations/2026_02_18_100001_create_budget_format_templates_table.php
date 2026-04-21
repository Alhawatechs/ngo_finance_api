<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Budget format templates: reusable structures per donor (UNICEF HER, UNFPA WHO, Legacy).
     */
    public function up(): void
    {
        Schema::create('budget_format_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('code', 50)->index();
            $table->foreignId('donor_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('structure_type', ['account_based', 'donor_code_based', 'activity_based', 'hybrid'])->default('account_based');
            $table->json('column_definition')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'donor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_format_templates');
    }
};
