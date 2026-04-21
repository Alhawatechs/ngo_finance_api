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
        Schema::table('organizations', function (Blueprint $table) {
            // NGO Profile
            $table->text('mission_statement')->nullable()->after('website');
            $table->text('vision_statement')->nullable()->after('mission_statement');
            $table->string('tagline', 255)->nullable()->after('vision_statement');
            $table->date('establishment_date')->nullable()->after('tagline');
            $table->string('organization_type', 100)->nullable()->after('establishment_date'); // NGO, INGO, Foundation, etc.
            
            // Legal & Compliance
            $table->string('tax_id', 100)->nullable()->after('organization_type');
            $table->string('tax_exemption_number', 100)->nullable()->after('tax_id');
            $table->date('tax_exemption_date')->nullable()->after('tax_exemption_number');
            $table->string('ngo_registration_body', 255)->nullable()->after('tax_exemption_date');
            $table->date('registration_date')->nullable()->after('ngo_registration_body');
            $table->date('registration_expiry_date')->nullable()->after('registration_date');
            $table->string('legal_status', 100)->nullable()->after('registration_expiry_date'); // Registered, Pending, etc.
            
            // Leadership
            $table->string('executive_director', 255)->nullable()->after('legal_status');
            $table->string('executive_director_email', 255)->nullable()->after('executive_director');
            $table->string('board_chair', 255)->nullable()->after('executive_director_email');
            $table->string('finance_director', 255)->nullable()->after('board_chair');
            $table->string('finance_director_email', 255)->nullable()->after('finance_director');
            
            // Address Extended
            $table->string('state_province', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('state_province');
            $table->string('fax', 50)->nullable()->after('phone');
            $table->string('secondary_phone', 50)->nullable()->after('fax');
            $table->string('secondary_email', 255)->nullable()->after('email');
            
            // Social Media
            $table->string('facebook_url', 255)->nullable()->after('website');
            $table->string('twitter_url', 255)->nullable()->after('facebook_url');
            $table->string('linkedin_url', 255)->nullable()->after('twitter_url');
            $table->string('instagram_url', 255)->nullable()->after('linkedin_url');
            $table->string('youtube_url', 255)->nullable()->after('instagram_url');
            
            // Operational Info
            $table->json('sectors_of_operation')->nullable()->after('youtube_url'); // Health, Education, etc.
            $table->json('geographic_areas')->nullable()->after('sectors_of_operation'); // Regions/provinces
            $table->integer('staff_count')->nullable()->after('geographic_areas');
            $table->integer('volunteer_count')->nullable()->after('staff_count');
            $table->integer('beneficiaries_count')->nullable()->after('volunteer_count');
            $table->integer('active_projects_count')->nullable()->after('beneficiaries_count');
            
            // Banking Information (for transparency)
            $table->string('primary_bank_name', 255)->nullable()->after('active_projects_count');
            $table->string('primary_bank_branch', 255)->nullable()->after('primary_bank_name');
            $table->string('primary_bank_account', 100)->nullable()->after('primary_bank_branch');
            $table->string('primary_bank_swift', 50)->nullable()->after('primary_bank_account');
            
            // Reporting & Audit
            $table->string('external_auditor', 255)->nullable()->after('primary_bank_swift');
            $table->date('last_audit_date')->nullable()->after('external_auditor');
            $table->string('audit_opinion', 50)->nullable()->after('last_audit_date'); // Unqualified, Qualified, etc.
            $table->json('statutory_reports')->nullable()->after('audit_opinion'); // Required reports list
            
            // Additional Settings
            $table->string('timezone', 50)->default('UTC')->after('fiscal_year_start_month');
            $table->string('date_format', 20)->default('Y-m-d')->after('timezone');
            $table->string('number_format', 20)->default('1,234.56')->after('date_format');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'mission_statement', 'vision_statement', 'tagline', 'establishment_date', 'organization_type',
                'tax_id', 'tax_exemption_number', 'tax_exemption_date', 'ngo_registration_body',
                'registration_date', 'registration_expiry_date', 'legal_status',
                'executive_director', 'executive_director_email', 'board_chair',
                'finance_director', 'finance_director_email',
                'state_province', 'postal_code', 'fax', 'secondary_phone', 'secondary_email',
                'facebook_url', 'twitter_url', 'linkedin_url', 'instagram_url', 'youtube_url',
                'sectors_of_operation', 'geographic_areas', 'staff_count', 'volunteer_count',
                'beneficiaries_count', 'active_projects_count',
                'primary_bank_name', 'primary_bank_branch', 'primary_bank_account', 'primary_bank_swift',
                'external_auditor', 'last_audit_date', 'audit_opinion', 'statutory_reports',
                'timezone', 'date_format', 'number_format',
            ]);
        });
    }
};
