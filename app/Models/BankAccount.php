<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankAccount extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'office_id',
        'gl_account_id',
        'bank_name',
        'branch_name',
        'account_number',
        'account_name',
        'account_type',
        'currency',
        'swift_code',
        'iban',
        'address',
        'contact_person',
        'contact_phone',
        'current_balance',
        'available_balance',
        'last_reconciled_date',
        'last_reconciled_balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'available_balance' => 'decimal:2',
            'last_reconciled_date' => 'date',
            'last_reconciled_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class);
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByOffice($query, $officeId)
    {
        return $query->where('office_id', $officeId);
    }

    public function scopeByCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    // Helpers
    public function updateBalance(float $debit, float $credit): void
    {
        $this->current_balance = $this->current_balance - $debit + $credit;
        $this->available_balance = $this->current_balance;
        $this->save();
    }

    public function hasUnreconciledTransactions(): bool
    {
        return $this->transactions()->where('is_reconciled', false)->exists();
    }

    public function getUnreconciledBalance(): float
    {
        $unreconciled = $this->transactions()->where('is_reconciled', false)->get();
        return $unreconciled->sum('credit_amount') - $unreconciled->sum('debit_amount');
    }

    public function getMaskedAccountNumber(): string
    {
        $length = strlen($this->account_number);
        if ($length <= 4) {
            return $this->account_number;
        }
        return str_repeat('*', $length - 4) . substr($this->account_number, -4);
    }
}
