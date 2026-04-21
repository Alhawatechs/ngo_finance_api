<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Basic Info
        'name',
        'short_name',
        'registration_number',
        'tagline',
        'mission_statement',
        'vision_statement',
        'establishment_date',
        'organization_type',
        
        // Financial Settings
        'default_currency',
        'secondary_currencies',
        'fiscal_year_start_month',
        'fiscal_year_end_month',
        'accounting_method',
        'budget_control_level',
        'allow_negative_budgets',
        'require_budget_check',
        'default_tax_rate',
        'enable_multi_currency',
        'exchange_rate_source',
        'cost_center_mandatory',
        'project_mandatory',
        'fund_mandatory',
        
        // Document Settings
        'voucher_number_format',
        'voucher_number_reset',
        'payment_voucher_prefix',
        'receipt_voucher_prefix',
        'journal_voucher_prefix',
        'contra_voucher_prefix',
        'purchase_order_prefix',
        'invoice_prefix',
        'next_payment_voucher_number',
        'next_receipt_voucher_number',
        'next_journal_voucher_number',
        'next_contra_voucher_number',
        'voucher_print_copies',
        'show_amount_in_words',
        'show_signature_lines',
        'require_narration',
        'coding_block_config',

        // Legal & Compliance
        'tax_id',
        'tax_exemption_number',
        'tax_exemption_date',
        'ngo_registration_body',
        'registration_date',
        'registration_expiry_date',
        'legal_status',
        'license_path',
        
        // Leadership
        'executive_director',
        'executive_director_email',
        'board_chair',
        'finance_director',
        'finance_director_email',
        'authorized_signatory_1',
        'authorized_signatory_1_title',
        'authorized_signatory_2',
        'authorized_signatory_2_title',
        'authorized_signatory_3',
        'authorized_signatory_3_title',
        'board_members',
        'key_staff',
        
        // Address
        'address',
        'city',
        'state_province',
        'postal_code',
        'country',
        
        // Contact
        'phone',
        'secondary_phone',
        'fax',
        'email',
        'secondary_email',
        'website',
        
        // Social Media
        'facebook_url',
        'twitter_url',
        'linkedin_url',
        'instagram_url',
        'youtube_url',
        
        // Operational
        'sectors_of_operation',
        'geographic_areas',
        'staff_count',
        'volunteer_count',
        'beneficiaries_count',
        'active_projects_count',
        
        // Banking
        'primary_bank_name',
        'primary_bank_branch',
        'primary_bank_account',
        'primary_bank_swift',
        'primary_bank_iban',
        'secondary_bank_name',
        'secondary_bank_branch',
        'secondary_bank_account',
        'enable_online_banking',
        'payment_methods',
        
        // Reporting & Audit
        'external_auditor',
        'last_audit_date',
        'audit_opinion',
        'statutory_reports',
        
        // System Settings
        'logo_path',
        'timezone',
        'date_format',
        'number_format',
        'language',
        'enable_notifications',
        'enable_email_alerts',
        'session_timeout',
        'require_password_change',
        'enable_two_factor',
        'data_retention_years',
        'is_active',
    ];

    protected $appends = ['logo_url', 'license_url'];

    protected function casts(): array
    {
        return [
            // Booleans
            'is_active' => 'boolean',
            'allow_negative_budgets' => 'boolean',
            'require_budget_check' => 'boolean',
            'enable_multi_currency' => 'boolean',
            'cost_center_mandatory' => 'boolean',
            'project_mandatory' => 'boolean',
            'fund_mandatory' => 'boolean',
            'show_amount_in_words' => 'boolean',
            'show_signature_lines' => 'boolean',
            'require_narration' => 'boolean',
            'enable_online_banking' => 'boolean',
            'enable_notifications' => 'boolean',
            'enable_email_alerts' => 'boolean',
            'enable_two_factor' => 'boolean',
            
            // Integers
            'fiscal_year_start_month' => 'integer',
            'fiscal_year_end_month' => 'integer',
            'voucher_print_copies' => 'integer',
            'next_payment_voucher_number' => 'integer',
            'next_receipt_voucher_number' => 'integer',
            'next_journal_voucher_number' => 'integer',
            'staff_count' => 'integer',
            'volunteer_count' => 'integer',
            'beneficiaries_count' => 'integer',
            'active_projects_count' => 'integer',
            'session_timeout' => 'integer',
            'require_password_change' => 'integer',
            'data_retention_years' => 'integer',
            
            // Decimals
            'default_tax_rate' => 'decimal:2',
            
            // Dates
            'establishment_date' => 'date',
            'tax_exemption_date' => 'date',
            'registration_date' => 'date',
            'registration_expiry_date' => 'date',
            'last_audit_date' => 'date',
            
            // Arrays
            'sectors_of_operation' => 'array',
            'geographic_areas' => 'array',
            'statutory_reports' => 'array',
            'secondary_currencies' => 'array',
            'payment_methods' => 'array',
            'board_members' => 'array',
            'key_staff' => 'array',
            'coding_block_config' => 'array',
        ];
    }

    // Relationships
    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function approvalWorkflowSetting(): HasOne
    {
        return $this->hasOne(ApprovalWorkflowSetting::class);
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(Currency::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function chartOfAccounts(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    public function donors(): HasMany
    {
        return $this->hasMany(Donor::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    // Helpers
    public function getHeadOffice(): ?Office
    {
        return $this->offices()->where('is_head_office', true)->first();
    }

    public function getDefaultCurrency(): ?Currency
    {
        return $this->currencies()->where('code', $this->default_currency)->first();
    }

    /**
     * Get list of active currency codes for this organization (for validation).
     * If no currencies are configured in the Currency table, returns config fallback so any configured code is allowed.
     */
    public function getActiveCurrencyCodes(): array
    {
        $codes = $this->currencies()->where('is_active', true)->pluck('code')->toArray();
        if (!empty($codes)) {
            return $codes;
        }
        return config('erp.currencies.supported', ['USD', 'EUR', 'AFN']);
    }

    /**
     * Get active currency codes for an organization by ID (for use in controllers).
     */
    public static function getActiveCurrencyCodesForOrg(int $organizationId): array
    {
        $codes = Currency::where('organization_id', $organizationId)->where('is_active', true)->pluck('code')->toArray();
        if (!empty($codes)) {
            return $codes;
        }
        return config('erp.currencies.supported', ['USD', 'EUR', 'AFN']);
    }

    public function getCurrentFiscalYear(): ?FiscalYear
    {
        return $this->fiscalYears()->where('is_current', true)->first();
    }

    public function getLogoUrlAttribute(): ?string
    {
        if ($this->logo_path) {
            return asset('storage/' . $this->logo_path);
        }
        return null;
    }

    public function getLicenseUrlAttribute(): ?string
    {
        if ($this->license_path) {
            return asset('storage/' . $this->license_path);
        }
        return null;
    }

    public function isRegistrationExpired(): bool
    {
        if (!$this->registration_expiry_date) {
            return false;
        }
        return $this->registration_expiry_date->isPast();
    }

    public function isRegistrationExpiringSoon(int $days = 90): bool
    {
        if (!$this->registration_expiry_date) {
            return false;
        }
        return $this->registration_expiry_date->isBetween(now(), now()->addDays($days));
    }

    public function getYearsInOperation(): ?int
    {
        if (!$this->establishment_date) {
            return null;
        }
        return $this->establishment_date->diffInYears(now());
    }

    /**
     * Sequence column for auto-numbering. Legacy DBs may lack next_contra_voucher_number; share journal sequence then.
     *
     * @return array{0: string, 1: string} [prefixColumn, nextNumberColumn]
     */
    protected function voucherSequenceColumns(string $type): array
    {
        $prefixField = "{$type}_voucher_prefix";
        $numberField = "next_{$type}_voucher_number";
        if ($type === 'contra' && Schema::hasTable($this->getTable()) && ! Schema::hasColumn($this->getTable(), $numberField)) {
            if (Schema::hasColumn($this->getTable(), 'next_journal_voucher_number')) {
                $numberField = 'next_journal_voucher_number';
            }
        }

        return [$prefixField, $numberField];
    }

    /**
     * Generate the next voucher number for a given type
     */
    public function getNextVoucherNumber(string $type): string
    {
        [$prefixField, $numberField] = $this->voucherSequenceColumns($type);

        $prefix = $this->{$prefixField} ?? strtoupper(substr($type, 0, 2));
        $number = $this->{$numberField} ?? 1;
        
        $format = $this->voucher_number_format ?? 'PREFIX-YYYY-NNNN';
        $year = date('Y');
        $paddedNumber = str_pad($number, 4, '0', STR_PAD_LEFT);
        
        $voucherNumber = str_replace(
            ['PREFIX', 'YYYY', 'NNNN'],
            [$prefix, $year, $paddedNumber],
            $format
        );
        
        return $voucherNumber;
    }

    /**
     * Increment voucher number after use
     */
    public function incrementVoucherNumber(string $type): void
    {
        [, $numberField] = $this->voucherSequenceColumns($type);
        $table = $this->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $numberField)) {
            Log::warning('Voucher sequence increment skipped: column missing', [
                'type' => $type,
                'column' => $numberField,
            ]);

            return;
        }
        $this->increment($numberField);
    }

    /**
     * Check if amount requires approval (delegates to ApprovalWorkflowSetting).
     */
    public function requiresApproval(float $amount): bool
    {
        $settings = $this->approvalWorkflowSetting;
        if (!$settings) {
            return false;
        }
        return $settings->requiresApproval($amount);
    }

    /**
     * Get approval level needed for amount (delegates to ApprovalWorkflowSetting).
     */
    public function getApprovalLevel(float $amount): int
    {
        $settings = $this->approvalWorkflowSetting;
        if (!$settings) {
            return 1;
        }
        return $settings->getApprovalLevel($amount);
    }

    /**
     * Check if dual signature is required (delegates to ApprovalWorkflowSetting).
     */
    public function requiresDualSignature(float $amount): bool
    {
        $settings = $this->approvalWorkflowSetting;
        if (!$settings) {
            return false;
        }
        return $settings->requiresDualSignature($amount);
    }
}
