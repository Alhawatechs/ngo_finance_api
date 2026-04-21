<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
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
            $table->string('reporting_frequency', 50)->nullable()->comment('Monthly, Quarterly, etc.');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'donor_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donors');
    }
};
