<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashCount extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_account_id',
        'count_date',
        'expected_balance',
        'actual_balance',
        'difference',
        'denomination_details',
        'notes',
        'counted_by',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'expected_balance' => 'decimal:2',
            'actual_balance' => 'decimal:2',
            'difference' => 'decimal:2',
            'denomination_details' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    // Relationships
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // Helpers
    public function hasDiscrepancy(): bool
    {
        return abs($this->difference) > 0.01;
    }

    public function isVerified(): bool
    {
        return $this->verified_by !== null;
    }

    public function calculateDifference(): float
    {
        return $this->actual_balance - $this->expected_balance;
    }
}
