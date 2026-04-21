<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_account_id',
        'journal_entry_id',
        'voucher_id',
        'transaction_number',
        'transaction_date',
        'transaction_type',
        'description',
        'amount',
        'currency',
        'exchange_rate',
        'running_balance',
        'payee_payer',
        'reference',
        'related_transaction_id',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'running_balance' => 'decimal:2',
        ];
    }

    // Relationships
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(CashTransaction::class, 'related_transaction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    // Helpers
    public function isDebit(): bool
    {
        return in_array($this->transaction_type, ['withdrawal', 'transfer_out']);
    }

    public function isCredit(): bool
    {
        return in_array($this->transaction_type, ['deposit', 'transfer_in']);
    }
}
