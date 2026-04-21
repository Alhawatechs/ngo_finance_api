<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donor extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'short_name',
        'donor_type',
        'contact_person',
        'email',
        'phone',
        'address',
        'country',
        'website',
        'notes',
        'reporting_currency',
        'reporting_frequency',
        'default_budget_format_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(Grant::class);
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }

    public function donorExpenditureCodes(): HasMany
    {
        return $this->hasMany(DonorExpenditureCode::class);
    }

    public function defaultBudgetFormat(): BelongsTo
    {
        return $this->belongsTo(BudgetFormatTemplate::class, 'default_budget_format_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('donor_type', $type);
    }

    // Helpers
    public function getTotalDonations(): float
    {
        return $this->donations()
            ->where('status', 'received')
            ->sum('base_currency_amount');
    }

    public function getTotalPledges(): float
    {
        return $this->pledges()
            ->where('status', 'active')
            ->sum('pledged_amount');
    }

    public function getOutstandingPledges(): float
    {
        return $this->pledges()
            ->whereIn('status', ['active', 'partially_fulfilled'])
            ->sum('outstanding_amount');
    }

    public function getActiveGrantsCount(): int
    {
        return $this->grants()
            ->where('status', 'active')
            ->count();
    }
}
