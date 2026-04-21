<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'journal_entry_id',
        'transaction_date',
        'value_date',
        'transaction_type',
        'reference',
        'description',
        'debit_amount',
        'credit_amount',
        'running_balance',
        'check_number',
        'payee_payer',
        'is_reconciled',
        'reconciliation_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'value_date' => 'date',
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'is_reconciled' => 'boolean',
        ];
    }

    // Relationships
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }

    // Scopes
    public function scopeUnreconciled($query)
    {
        return $query->where('is_reconciled', false);
    }

    public function scopeReconciled($query)
    {
        return $query->where('is_reconciled', true);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    // Helpers
    public function getAmount(): float
    {
        return $this->credit_amount - $this->debit_amount;
    }

    public function isDebit(): bool
    {
        return $this->debit_amount > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit_amount > 0;
    }

    public function markAsReconciled(int $reconciliationId): void
    {
        $this->update([
            'is_reconciled' => true,
            'reconciliation_id' => $reconciliationId,
        ]);
    }
}
