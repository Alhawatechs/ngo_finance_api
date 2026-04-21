<?php

namespace App\Models;

use App\Services\AccountCodeScheme;
use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'organization_id',
        'parent_id',
        'account_code',
        'account_code_sort',
        'account_name',
        'account_type',
        'normal_balance',
        'level',
        'is_header',
        'is_posting',
        'is_bank_account',
        'is_cash_account',
        'is_control_account',
        'fund_type',
        'currency_code',
        'description',
        'is_active',
        'opening_balance',
        'opening_balance_date',
    ];

    protected static function booted(): void
    {
        static::saving(function (ChartOfAccount $account) {
            $code = trim((string) $account->account_code);
            if ($code !== '') {
                $account->account_code_sort = AccountCodeScheme::sortKey($code);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_header' => 'boolean',
            'is_posting' => 'boolean',
            'is_bank_account' => 'boolean',
            'is_cash_account' => 'boolean',
            'is_control_account' => 'boolean',
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:2',
            'opening_balance_date' => 'date',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'account_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePosting($query)
    {
        return $query->where('is_posting', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('account_type', $type);
    }

    public function scopeByOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    // Helpers
    public function getFullPathAttribute(): string
    {
        $path = [$this->account_name];
        $parent = $this->parent;
        
        while ($parent) {
            array_unshift($path, $parent->account_name);
            $parent = $parent->parent;
        }
        
        return implode(' > ', $path);
    }

    public function isDebitBalance(): bool
    {
        return $this->normal_balance === 'debit';
    }

    public function isCreditBalance(): bool
    {
        return $this->normal_balance === 'credit';
    }

    public function getBalance(?string $startDate = null, ?string $endDate = null): float
    {
        $query = $this->journalEntryLines()
            ->whereHas('journalEntry', function ($q) {
                $q->where('status', 'posted');
            });

        if ($startDate) {
            $query->whereHas('journalEntry', function ($q) use ($startDate) {
                $q->where('entry_date', '>=', $startDate);
            });
        }

        if ($endDate) {
            $query->whereHas('journalEntry', function ($q) use ($endDate) {
                $q->where('entry_date', '<=', $endDate);
            });
        }

        $debits = $query->sum('base_currency_debit');
        $credits = $query->sum('base_currency_credit');

        if ($this->isDebitBalance()) {
            return $debits - $credits + $this->opening_balance;
        }
        
        return $credits - $debits + $this->opening_balance;
    }

    public function getAllDescendantIds(): array
    {
        $ids = [$this->id];
        
        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->getAllDescendantIds());
        }
        
        return $ids;
    }
}
