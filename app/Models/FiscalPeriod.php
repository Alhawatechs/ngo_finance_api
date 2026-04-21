<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalPeriod extends Model
{
    use HasFactory, UsesOfficeConnection;

    protected $fillable = [
        'fiscal_year_id',
        'name',
        'period_number',
        'start_date',
        'end_date',
        'status',
        'is_adjustment_period',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_adjustment_period' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    // Relationships
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    // Helpers
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'locked']);
    }

    public function containsDate(string $date): bool
    {
        return $date >= $this->start_date && $date <= $this->end_date;
    }

    public function canPostEntry(): bool
    {
        return $this->isOpen();
    }
}
