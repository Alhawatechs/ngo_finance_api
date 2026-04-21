<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Organizational Units (Departments/Divisions/Units)
        Schema::create('organizational_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('organizational_units')->onDelete('set null');
            $table->string('name', 255);
            $table->string('code', 50)->nullable();
            $table->enum('type', ['division', 'department', 'unit', 'section', 'team'])->default('department');
            $table->text('description')->nullable();
            $table->string('head_title', 100)->nullable(); // e.g., "Director", "Manager"
            $table->foreignId('head_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->integer('level')->default(0); // Hierarchy level (0 = top)
            $table->integer('sort_order')->default(0);
            $table->string('color', 20)->nullable(); // For organogram display
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['organization_id', 'parent_id']);
            $table->index(['organization_id', 'type']);
        });

        // Positions/Job Titles
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('organizational_unit_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('reports_to_id')->nullable()->constrained('positions')->onDelete('set null');
            $table->string('title', 255);
            $table->string('code', 50)->nullable();
            $table->enum('level', ['executive', 'senior_management', 'middle_management', 'supervisory', 'professional', 'support'])->default('professional');
            $table->text('description')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('qualifications')->nullable();
            $table->integer('grade')->nullable(); // Salary grade
            $table->integer('headcount')->default(1); // Number of positions available
            $table->decimal('min_salary', 15, 2)->nullable();
            $table->decimal('max_salary', 15, 2)->nullable();
            $table->boolean('is_supervisory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['organization_id', 'organizational_unit_id']);
            $table->index(['organization_id', 'level']);
        });

        // Position Assignments (User to Position mapping)
        Schema::create('position_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_primary')->default(true); // Primary position
            $table->boolean('is_acting')->default(false); // Acting in role
            $table->string('employment_type', 50)->default('full_time'); // full_time, part_time, contract
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['position_id', 'is_active']);
            $table->index(['user_id', 'is_primary']);
        });

        // Segregation of Duties Rules
        Schema::create('segregation_of_duties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->enum('rule_type', ['incompatible_positions', 'incompatible_functions', 'approval_separation', 'custom'])->default('incompatible_positions');
            $table->foreignId('position_a_id')->nullable()->constrained('positions')->onDelete('cascade');
            $table->foreignId('position_b_id')->nullable()->constrained('positions')->onDelete('cascade');
            $table->string('function_a', 100)->nullable(); // e.g., "create_voucher"
            $table->string('function_b', 100)->nullable(); // e.g., "approve_voucher"
            $table->enum('severity', ['warning', 'block'])->default('warning');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['organization_id', 'rule_type']);
        });

        // Approval Hierarchy/Reporting Lines
        Schema::create('reporting_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('subordinate_position_id')->constrained('positions')->onDelete('cascade');
            $table->foreignId('supervisor_position_id')->constrained('positions')->onDelete('cascade');
            $table->enum('relationship_type', ['direct', 'dotted', 'functional', 'project'])->default('direct');
            $table->text('description')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['subordinate_position_id', 'supervisor_position_id', 'relationship_type'], 'unique_reporting_line');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reporting_lines');
        Schema::dropIfExists('segregation_of_duties');
        Schema::dropIfExists('position_assignments');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('organizational_units');
    }
};
