<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('code', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('fund_type', ['unrestricted', 'restricted', 'temporarily_restricted']);
            $table->foreignId('donor_id')->nullable()->constrained('donors')->onDelete('set null');
            $table->date('restriction_start_date')->nullable();
            $table->date('restriction_end_date')->nullable();
            $table->text('restriction_purpose')->nullable();
            $table->decimal('initial_amount', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'fund_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funds');
    }
};
