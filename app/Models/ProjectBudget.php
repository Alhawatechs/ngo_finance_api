<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectBudget extends Model
{
    protected $table = 'project_budgets';

    protected $fillable = [
        'project_id',
        'account_id',
        'budget_line_code',
        'description',
        'budget_amount',
        'revised_amount',
        'spent_amount',
        'committed_amount',
    ];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:2',
            'revised_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
            'committed_amount' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
