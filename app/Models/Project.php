<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $appends = ['total_budget', 'spent_amount', 'committed_amount', 'locations_list'];

    protected $fillable = [
        'organization_id',
        'grant_id',
        'parent_project_id',
        'office_id',
        'cost_center_id',
        'project_code',
        'project_name',
        'description',
        'start_date',
        'end_date',
        'budget_amount',
        'currency',
        'status',
        'project_manager',
        'sector',
        'location',
        'locations',
        'beneficiaries_target',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'budget_amount' => 'decimal:2',
            'locations' => 'array',
        ];
    }

    /** Get locations as array (supports legacy single location). */
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

    /** API/frontend expect total_budget; table may have budget_amount. */
    public function getTotalBudgetAttribute(): float
    {
        return (float) ($this->attributes['total_budget'] ?? $this->attributes['budget_amount'] ?? 0);
    }

    /** When API sends total_budget, persist as budget_amount for DB. */
    public function setTotalBudgetAttribute($value): void
    {
        $this->attributes['budget_amount'] = $value;
    }

    /** Table may have spent_amount column; otherwise 0. */
    public function getSpentAmountAttribute(): float
    {
        return (float) ($this->attributes['spent_amount'] ?? 0);
    }

    /** Table may have committed_amount column; otherwise 0. */
    public function getCommittedAmountAttribute(): float
    {
        return (float) ($this->attributes['committed_amount'] ?? 0);
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_project_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    /** Class list (cost centers) linked to this project. */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'project_id');
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(ProjectBudget::class);
    }

    public function donorExpenditureCodes(): HasMany
    {
        return $this->hasMany(DonorExpenditureCode::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
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

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // Helpers
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getSpentAmount(): float
    {
        return $this->journalEntryLines()
            ->whereHas('journalEntry', function ($q) {
                $q->where('status', 'posted');
            })
            ->whereHas('account', function ($q) {
                $q->where('account_type', 'expense');
            })
            ->sum('base_currency_debit');
    }

    public function getRemainingBudget(): float
    {
        return $this->budget_amount - $this->getSpentAmount();
    }

    public function getBudgetUtilization(): float
    {
        if ($this->budget_amount == 0) {
            return 0;
        }
        return ($this->getSpentAmount() / $this->budget_amount) * 100;
    }

    public function getDaysRemaining(): int
    {
        return max(0, now()->diffInDays($this->end_date, false));
    }
}
