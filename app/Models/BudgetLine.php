<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $table = 'budget_lines';

    protected $fillable = [
        'budget_id',
        'sheet_key',
        'parent_line_id',
        'account_id',
        'donor_expenditure_code_id',
        'fund_id',
        'line_code',
        'description',
        'annual_amount',
        'q1_amount',
        'q2_amount',
        'q3_amount',
        'q4_amount',
        'revised_amount',
        'actual_amount',
        'committed_amount',
        'available_amount',
        'format_attributes',
    ];

    protected function casts(): array
    {
        return [
            'annual_amount' => 'decimal:2',
            'q1_amount' => 'decimal:2',
            'q2_amount' => 'decimal:2',
            'q3_amount' => 'decimal:2',
            'q4_amount' => 'decimal:2',
            'revised_amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'committed_amount' => 'decimal:2',
            'available_amount' => 'decimal:2',
            'format_attributes' => 'array',
        ];
    }

    public function parentLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'parent_line_id');
    }

    public function childLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BudgetLine::class, 'parent_line_id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function donorExpenditureCode(): BelongsTo
    {
        return $this->belongsTo(DonorExpenditureCode::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }
}
