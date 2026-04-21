<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grant extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $appends = ['locations_list'];

    protected $fillable = [
        'organization_id',
        'donor_id',
        'parent_grant_id',
        'grant_code',
        'grant_name',
        'description',
        'start_date',
        'end_date',
        'total_amount',
        'currency',
        'status',
        'terms_conditions',
        'contract_reference',
        'contract_date',
        'location',
        'locations',
        'document_type',
        'donor_contribution_amount',
        'partner_contribution_amount',
        'partner_name',
        'partner_details',
        'sub_partner_allocation_amount',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_amount' => 'decimal:2',
            'contract_date' => 'date',
            'donor_contribution_amount' => 'decimal:2',
            'partner_contribution_amount' => 'decimal:2',
            'sub_partner_allocation_amount' => 'decimal:2',
            'locations' => 'array',
        ];
    }

    /**
     * Get locations as array (supports legacy single location field).
     */
    public function getLocationsListAttribute(): array
    {
        $locations = $this->locations;
        if (is_array($locations) && count($locations) > 0) {
            return array_values(array_filter($locations, fn ($v) => is_string($v) && $v !== ''));
        }
        if (!empty($this->location)) {
            return [$this->location];
        }
        return [];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function parentGrant(): BelongsTo
    {
        return $this->belongsTo(Grant::class, 'parent_grant_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Grant::class, 'parent_grant_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function fundRequests(): HasMany
    {
        return $this->hasMany(FundRequest::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByDonor($query, int $donorId)
    {
        return $query->where('donor_id', $donorId);
    }

    // Helpers
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getReceivedAmount(): float
    {
        return $this->fundRequests()
            ->where('status', 'received')
            ->sum('received_amount');
    }

    public function getRemainingAmount(): float
    {
        return $this->total_amount - $this->getReceivedAmount();
    }

    public function getUtilizationPercentage(): float
    {
        if ($this->total_amount == 0) {
            return 0;
        }
        return ($this->getReceivedAmount() / $this->total_amount) * 100;
    }

    public function getDaysRemaining(): int
    {
        return max(0, now()->diffInDays($this->end_date, false));
    }
}
