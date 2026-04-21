<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use HasFactory, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'is_current',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
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

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
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

    public function getCurrentPeriod(): ?FiscalPeriod
    {
        $today = now()->format('Y-m-d');
        
        return $this->periods()
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();
    }

    public function getOpenPeriod(): ?FiscalPeriod
    {
        return $this->periods()
            ->where('status', 'open')
            ->orderBy('period_number')
            ->first();
    }
}
