<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'position_id',
        'user_id',
        'start_date',
        'end_date',
        'is_primary',
        'is_acting',
        'employment_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_primary' => 'boolean',
            'is_acting' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Helpers
    public function isExpired(): bool
    {
        if (!$this->end_date) {
            return false;
        }
        return $this->end_date->isPast();
    }

    public function getDuration(): ?int
    {
        if (!$this->start_date) {
            return null;
        }
        
        $endDate = $this->end_date ?? now();
        return $this->start_date->diffInDays($endDate);
    }
}
