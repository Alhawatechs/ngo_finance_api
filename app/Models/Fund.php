<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fund extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'fund_type',
        'donor_id',
        'restriction_start_date',
        'restriction_end_date',
        'restriction_purpose',
        'initial_amount',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'restriction_start_date' => 'date',
            'restriction_end_date' => 'date',
            'initial_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Legacy / API aliases — DB columns are `code` and `name`.
     */
    public function getFundCodeAttribute(): ?string
    {
        return $this->attributes['code'] ?? null;
    }

    public function getFundNameAttribute(): ?string
    {
        return $this->attributes['name'] ?? null;
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function voucherLines(): HasMany
    {
        return $this->hasMany(VoucherLine::class);
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('fund_type', $type);
    }

    public function scopeRestricted($query)
    {
        return $query->whereIn('fund_type', ['restricted', 'temporarily_restricted']);
    }

    public function scopeUnrestricted($query)
    {
        return $query->where('fund_type', 'unrestricted');
    }

    // Helpers
    public function isRestricted(): bool
    {
        return in_array($this->fund_type, ['restricted', 'temporarily_restricted']);
    }

    public function isUnrestricted(): bool
    {
        return $this->fund_type === 'unrestricted';
    }

    public function getBalance(): float
    {
        $debits = $this->journalEntryLines()
            ->whereHas('journalEntry', fn($q) => $q->where('status', 'posted'))
            ->sum('base_currency_debit');

        $credits = $this->journalEntryLines()
            ->whereHas('journalEntry', fn($q) => $q->where('status', 'posted'))
            ->sum('base_currency_credit');

        return $this->initial_amount + $credits - $debits;
    }

    public function isRestrictionExpired(): bool
    {
        if (!$this->restriction_end_date) {
            return false;
        }
        return now()->isAfter($this->restriction_end_date);
    }
}
