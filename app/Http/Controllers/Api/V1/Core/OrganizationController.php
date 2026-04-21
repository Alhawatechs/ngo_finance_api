<?php

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class OrganizationController extends Controller
{
    protected ImageProcessingService $imageService;

    public function __construct(ImageProcessingService $imageService)
    {
        $this->imageService = $imageService;
    }
    /**
     * Get the current user's organization settings
     */
    public function show(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found',
            ], 404);
        }

        return response()->json([
            'data' => $this->formatOrganization($organization),
        ]);
    }

    /**
     * Update organization settings
     */
    public function update(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            // Basic Info (mandatory)
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'registration_number' => 'nullable|string|max:100',
            'tagline' => 'nullable|string|max:255',
            'mission_statement' => 'nullable|string|max:2000',
            'vision_statement' => 'nullable|string|max:2000',
            'establishment_date' => 'nullable|date',
            'organization_type' => 'nullable|string|max:100',
            
            // Legal & Compliance
            'tax_id' => 'nullable|string|max:100',
            'tax_exemption_number' => 'nullable|string|max:100',
            'tax_exemption_date' => 'nullable|date',
            'ngo_registration_body' => 'nullable|string|max:255',
            'registration_date' => 'nullable|date',
            'registration_expiry_date' => 'nullable|date',
            'legal_status' => 'nullable|string|max:100',
            
            // Leadership
            'executive_director' => 'nullable|string|max:255',
            'executive_director_email' => 'nullable|email|max:255',
            'board_chair' => 'nullable|string|max:255',
            'finance_director' => 'nullable|string|max:255',
            'finance_director_email' => 'nullable|email|max:255',
            'authorized_signatory_1' => 'nullable|string|max:255',
            'authorized_signatory_1_title' => 'nullable|string|max:100',
            'authorized_signatory_2' => 'nullable|string|max:255',
            'authorized_signatory_2_title' => 'nullable|string|max:100',
            'authorized_signatory_3' => 'nullable|string|max:255',
            'authorized_signatory_3_title' => 'nullable|string|max:100',
            'board_members' => 'nullable|array',
            'board_members.*.name' => 'nullable|string|max:255',
            'board_members.*.role' => 'nullable|string|max:100',
            'board_members.*.email' => 'nullable|email|max:255',
            'board_members.*.phone' => 'nullable|string|max:50',
            'key_staff' => 'nullable|array',
            'key_staff.*.name' => 'nullable|string|max:255',
            'key_staff.*.role' => 'nullable|string|max:100',
            'key_staff.*.email' => 'nullable|email|max:255',
            'key_staff.*.phone' => 'nullable|string|max:50',

            // Address
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'state_province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            
            // Contact (at least one of email or phone required)
            'phone' => 'required_without:email|nullable|string|max:50',
            'secondary_phone' => 'nullable|string|max:50',
            'fax' => 'nullable|string|max:50',
            'email' => 'required_without:phone|nullable|email|max:255',
            'secondary_email' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            
            // Social Media
            'facebook_url' => 'nullable|url|max:255',
            'twitter_url' => 'nullable|url|max:255',
            'linkedin_url' => 'nullable|url|max:255',
            'instagram_url' => 'nullable|url|max:255',
            'youtube_url' => 'nullable|url|max:255',
            
            // Operational (sectors mandatory for NGO)
            'sectors_of_operation' => 'required|array|min:1',
            'geographic_areas' => 'nullable|array',
            'staff_count' => 'nullable|integer|min:0',
            'volunteer_count' => 'nullable|integer|min:0',
            'beneficiaries_count' => 'nullable|integer|min:0',
            'active_projects_count' => 'nullable|integer|min:0',
            
            // Banking
            'primary_bank_name' => 'nullable|string|max:255',
            'primary_bank_branch' => 'nullable|string|max:255',
            'primary_bank_account' => 'nullable|string|max:100',
            'primary_bank_swift' => 'nullable|string|max:50',
            
            // Reporting & Audit
            'external_auditor' => 'nullable|string|max:255',
            'last_audit_date' => 'nullable|date',
            'audit_opinion' => 'nullable|string|max:50',
            'statutory_reports' => 'nullable|array',
            
            // Financial Settings (mandatory)
            'default_currency' => 'required|string|size:3',
            'fiscal_year_start_month' => 'required|integer|min:1|max:12',
            'timezone' => 'nullable|string|max:50',
            'date_format' => 'nullable|string|max:20',
            'number_format' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $organization->update($validator->validated());

        return response()->json([
            'message' => 'Organization updated successfully',
            'data' => $this->formatOrganization($organization),
        ]);
    }

    /**
     * Upload organization logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Delete old logo if exists
        if ($organization->logo_path) {
            Storage::disk('public')->delete($organization->logo_path);
        }

        try {
            // Process logo - check/add transparency and optimize
            $result = $this->imageService->processLogo($request->file('logo'), 'logos');
            
            $organization->update([
                'logo_path' => $result['path'],
            ]);

            return response()->json([
                'message' => 'Logo uploaded and processed successfully',
                'data' => [
                    'logo_url' => $organization->logo_url,
                    'logo_path' => $result['path'],
                    'had_transparency' => $result['had_transparency'],
                    'dimensions' => [
                        'width' => $result['width'],
                        'height' => $result['height'],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            // Fallback to simple upload if processing fails
            $path = $request->file('logo')->store('logos', 'public');
            
            $organization->update([
                'logo_path' => $path,
            ]);

            return response()->json([
                'message' => 'Logo uploaded successfully (without processing)',
                'data' => [
                    'logo_url' => $organization->logo_url,
                    'logo_path' => $path,
                ],
            ]);
        }
    }

    /**
     * Remove organization logo
     */
    public function removeLogo(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found',
            ], 404);
        }

        if ($organization->logo_path) {
            Storage::disk('public')->delete($organization->logo_path);
            
            $organization->update([
                'logo_path' => null,
            ]);
        }

        return response()->json([
            'message' => 'Logo removed successfully',
        ]);
    }

    /**
     * Upload organization license document
     */
    public function uploadLicense(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'license' => [
                'required',
                'file',
                'max:10240', // 10MB max
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/jpeg,image/jpg,image/pjpeg,image/png',
            ],
        ], [
            'license.required' => 'Please select a license file to upload.',
            'license.file' => 'The uploaded file is invalid.',
            'license.mimetypes' => 'License must be PDF, Word (doc/docx), or image (jpeg, jpg, png).',
            'license.max' => 'License file must not exceed 10 MB.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Delete previous license if exists
            if ($organization->license_path) {
                Storage::disk('public')->delete($organization->license_path);
            }

            $file = $request->file('license');
            $path = $file->store('licenses', 'public');

            $organization->update([
                'license_path' => $path,
            ]);

            return response()->json([
                'message' => 'License uploaded successfully',
                'data' => [
                    'license_url' => $organization->fresh()->license_url,
                    'license_path' => $path,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('License upload failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Failed to save license: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove organization license
     */
    public function removeLicense(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found',
            ], 404);
        }

        if ($organization->license_path) {
            Storage::disk('public')->delete($organization->license_path);
            $organization->update([
                'license_path' => null,
            ]);
        }

        return response()->json([
            'message' => 'License removed successfully',
        ]);
    }

    /**
     * Get organization branding (public endpoint for login page etc.)
     */
    public function branding(Request $request): JsonResponse
    {
        // Get organization by domain or default
        $organization = Organization::where('is_active', true)->first();

        if (!$organization) {
            return response()->json([
                'data' => [
                    'name' => config('erp.organization.name', 'AADA Finance'),
                    'short_name' => config('erp.organization.short_name', 'AADA'),
                    'logo_url' => null,
                    'tagline' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'name' => $organization->name,
                'short_name' => $organization->short_name,
                'logo_url' => $organization->logo_url,
                'tagline' => $organization->tagline,
            ],
        ]);
    }

    /**
     * Get organization statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        if (!$organization) {
            return response()->json([
                'message' => 'Organization not found',
            ], 404);
        }

        return response()->json([
            'data' => [
                'staff_count' => $organization->staff_count ?? $organization->users()->count(),
                'volunteer_count' => $organization->volunteer_count ?? 0,
                'beneficiaries_count' => $organization->beneficiaries_count ?? 0,
                'active_projects_count' => $organization->active_projects_count ?? $organization->projects()->where('status', 'active')->count(),
                'offices_count' => $organization->offices()->count(),
                'donors_count' => $organization->donors()->count(),
                'years_in_operation' => $organization->getYearsInOperation(),
                'registration_expiring_soon' => $organization->isRegistrationExpiringSoon(),
                'registration_expired' => $organization->isRegistrationExpired(),
            ],
        ]);
    }

    /**
     * Format organization data for API response
     */
    private function formatOrganization(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            
            // Basic Info
            'name' => $organization->name,
            'short_name' => $organization->short_name,
            'registration_number' => $organization->registration_number,
            'tagline' => $organization->tagline,
            'mission_statement' => $organization->mission_statement,
            'vision_statement' => $organization->vision_statement,
            'establishment_date' => $organization->establishment_date?->format('Y-m-d'),
            'organization_type' => $organization->organization_type,
            
            // Legal & Compliance
            'tax_id' => $organization->tax_id,
            'tax_exemption_number' => $organization->tax_exemption_number,
            'tax_exemption_date' => $organization->tax_exemption_date?->format('Y-m-d'),
            'ngo_registration_body' => $organization->ngo_registration_body,
            'registration_date' => $organization->registration_date?->format('Y-m-d'),
            'registration_expiry_date' => $organization->registration_expiry_date?->format('Y-m-d'),
            'legal_status' => $organization->legal_status,
            'license_url' => $organization->license_url,
            
            // Leadership
            'executive_director' => $organization->executive_director,
            'executive_director_email' => $organization->executive_director_email,
            'board_chair' => $organization->board_chair,
            'finance_director' => $organization->finance_director,
            'finance_director_email' => $organization->finance_director_email,
            'authorized_signatory_1' => $organization->authorized_signatory_1,
            'authorized_signatory_1_title' => $organization->authorized_signatory_1_title,
            'authorized_signatory_2' => $organization->authorized_signatory_2,
            'authorized_signatory_2_title' => $organization->authorized_signatory_2_title,
            'authorized_signatory_3' => $organization->authorized_signatory_3,
            'authorized_signatory_3_title' => $organization->authorized_signatory_3_title,
            'board_members' => $organization->board_members ?? [],
            'key_staff' => $organization->key_staff ?? [],

            // Address
            'address' => $organization->address,
            'city' => $organization->city,
            'state_province' => $organization->state_province,
            'postal_code' => $organization->postal_code,
            'country' => $organization->country,
            
            // Contact
            'phone' => $organization->phone,
            'secondary_phone' => $organization->secondary_phone,
            'fax' => $organization->fax,
            'email' => $organization->email,
            'secondary_email' => $organization->secondary_email,
            'website' => $organization->website,
            
            // Social Media
            'facebook_url' => $organization->facebook_url,
            'twitter_url' => $organization->twitter_url,
            'linkedin_url' => $organization->linkedin_url,
            'instagram_url' => $organization->instagram_url,
            'youtube_url' => $organization->youtube_url,
            
            // Operational
            'sectors_of_operation' => $organization->sectors_of_operation ?? [],
            'geographic_areas' => $organization->geographic_areas ?? [],
            'staff_count' => $organization->staff_count,
            'volunteer_count' => $organization->volunteer_count,
            'beneficiaries_count' => $organization->beneficiaries_count,
            'active_projects_count' => $organization->active_projects_count,
            
            // Banking
            'primary_bank_name' => $organization->primary_bank_name,
            'primary_bank_branch' => $organization->primary_bank_branch,
            'primary_bank_account' => $organization->primary_bank_account,
            'primary_bank_swift' => $organization->primary_bank_swift,
            
            // Reporting & Audit
            'external_auditor' => $organization->external_auditor,
            'last_audit_date' => $organization->last_audit_date?->format('Y-m-d'),
            'audit_opinion' => $organization->audit_opinion,
            'statutory_reports' => $organization->statutory_reports ?? [],
            
            // Settings
            'logo_url' => $organization->logo_url,
            'default_currency' => $organization->default_currency,
            'fiscal_year_start_month' => $organization->fiscal_year_start_month,
            'fiscal_year_end_month' => $organization->fiscal_year_end_month,
            'timezone' => $organization->timezone,
            'date_format' => $organization->date_format,
            'number_format' => $organization->number_format,
            
            // Finance settings (drive vouchers, reports, validation)
            'project_mandatory' => (bool) ($organization->project_mandatory ?? true),
            'fund_mandatory' => (bool) ($organization->fund_mandatory ?? true),
            'cost_center_mandatory' => (bool) ($organization->cost_center_mandatory ?? false),
            'require_budget_check' => (bool) ($organization->require_budget_check ?? true),
            'budget_control_level' => $organization->budget_control_level ?? 'warning',
            'accounting_method' => $organization->accounting_method ?? 'accrual',
            'payment_voucher_prefix' => $organization->payment_voucher_prefix ?? 'PV',
            'receipt_voucher_prefix' => $organization->receipt_voucher_prefix ?? 'RV',
            'journal_voucher_prefix' => $organization->journal_voucher_prefix ?? 'JV',
            'contra_voucher_prefix' => $organization->contra_voucher_prefix ?? 'CV',
            
            // Meta
            'is_active' => $organization->is_active,
            'years_in_operation' => $organization->getYearsInOperation(),
            'created_at' => $organization->created_at,
            'updated_at' => $organization->updated_at,
        ];
    }
}
