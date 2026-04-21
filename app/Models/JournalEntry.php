<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'journal_id',
        'office_id',
        'fiscal_period_id',
        'entry_number',
        'voucher_number',
        'entry_date',
        'posting_date',
        'entry_type',
        'reference',
        'description',
        'currency',
        'exchange_rate',
        'total_debit',
        'total_credit',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
        'reversal_entry_id',
        'source_type',
        'source_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posting_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_entry_id');
    }

    public function reversedEntry(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reversal_entry_id');
    }

    public function voucher(): HasOne
    {
        return $this->hasOne(Voucher::class);
    }

    // Scopes
    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeByOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByPeriod($query, $fiscalPeriodId)
    {
        return $query->where('fiscal_period_id', $fiscalPeriodId);
    }

    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('entry_date', [$startDate, $endDate]);
    }

    // Helpers
    public function isBalanced(): bool
    {
        return bccomp($this->total_debit, $this->total_credit, 2) === 0;
    }

    public function canBePosted(): bool
    {
        return $this->status === 'draft' && $this->isBalanced();
    }

    public function canBeReversed(): bool
    {
        return $this->status === 'posted' && !$this->reversal_entry_id;
    }

    public function recalculateTotals(): void
    {
        $this->total_debit = $this->lines()->sum('base_currency_debit');
        $this->total_credit = $this->lines()->sum('base_currency_credit');
        $this->save();
    }

    public function post(User $user): bool
    {
        if (!$this->canBePosted()) {
            return false;
        }

        $this->status = 'posted';
        $this->posted_by = $user->id;
        $this->posted_at = now();
        $this->posting_date = now();
        
        return $this->save();
    }
}
