<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    use HasFactory, UsesOfficeConnection;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'fund_id',
        'project_id',
        'donor_expenditure_code_id',
        'office_id',
        'line_number',
        'description',
        'debit_amount',
        'credit_amount',
        'currency',
        'exchange_rate',
        'base_currency_debit',
        'base_currency_credit',
        'reference',
        'cost_center',
        'dimensions',
    ];

    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_currency_debit' => 'decimal:2',
            'base_currency_credit' => 'decimal:2',
            'dimensions' => 'array',
        ];
    }

    // Relationships
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function donorExpenditureCode(): BelongsTo
    {
        return $this->belongsTo(DonorExpenditureCode::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    // Helpers
    public function isDebit(): bool
    {
        return $this->debit_amount > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit_amount > 0;
    }

    public function getAmount(): float
    {
        return $this->isDebit() ? $this->debit_amount : $this->credit_amount;
    }

    public function getBaseAmount(): float
    {
        return $this->isDebit() ? $this->base_currency_debit : $this->base_currency_credit;
    }
}
