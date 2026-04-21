<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetFormatTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'donor_id',
        'structure_type',
        'column_definition',
        'google_spreadsheet_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'column_definition' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'budget_format_template_id');
    }
}
