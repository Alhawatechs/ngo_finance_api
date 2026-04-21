<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'fiscal_year_id',
        'office_id',
        'project_id',
        'fund_id',
        'budget_format_template_id',
        'grant_id',
        'budget_code',
        'name',
        'description',
        'budget_type',
        'currency',
        'total_amount',
        'version',
        'status',
        'prepared_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function budgetFormatTemplate(): BelongsTo
    {
        return $this->belongsTo(BudgetFormatTemplate::class, 'budget_format_template_id');
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BudgetRevision::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    // Helpers
    public function getTotalBudgeted(): float
    {
        return $this->lines()->sum('annual_amount');
    }

    public function getTotalActual(): float
    {
        return $this->lines()->sum('actual_amount');
    }

    public function getTotalAvailable(): float
    {
        return $this->lines()->sum('available_amount');
    }

    public function getVariance(): float
    {
        return $this->getTotalBudgeted() - $this->getTotalActual();
    }

    public function getVariancePercentage(): float
    {
        $budgeted = $this->getTotalBudgeted();
        if ($budgeted == 0) {
            return 0;
        }
        return ($this->getVariance() / $budgeted) * 100;
    }

    public function updateActuals(): void
    {
        foreach ($this->lines as $line) {
            $line->updateActual();
        }
    }
}
