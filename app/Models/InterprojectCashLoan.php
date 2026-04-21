<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterprojectCashLoan extends Model
{
    use UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'lender_project_id',
        'borrower_project_id',
        'loan_number',
        'effective_date',
        'due_date',
        'principal',
        'currency',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'due_date' => 'date',
            'principal' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function lenderProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'lender_project_id');
    }

    public function borrowerProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'borrower_project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
