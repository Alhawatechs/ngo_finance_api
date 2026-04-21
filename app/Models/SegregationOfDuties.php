<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SegregationOfDuties extends Model
{
    use HasFactory;

    protected $table = 'segregation_of_duties';

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'rule_type',
        'position_a_id',
        'position_b_id',
        'function_a',
        'function_b',
        'severity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function positionA(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_a_id');
    }

    public function positionB(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_b_id');
    }

    // Helpers
    public function checkViolation(int $userId): bool
    {
        if ($this->rule_type === 'incompatible_positions') {
            // Check if user holds both positions
            $positionAUsers = $this->positionA?->users->pluck('id')->toArray() ?? [];
            $positionBUsers = $this->positionB?->users->pluck('id')->toArray() ?? [];
            
            return in_array($userId, $positionAUsers) && in_array($userId, $positionBUsers);
        }
        
        return false;
    }

    public function getRuleTypeLabel(): string
    {
        return match($this->rule_type) {
            'incompatible_positions' => 'Incompatible Positions',
            'incompatible_functions' => 'Incompatible Functions',
            'approval_separation' => 'Approval Separation',
            'custom' => 'Custom Rule',
            default => 'Unknown',
        };
    }

    public function getSeverityLabel(): string
    {
        return match($this->severity) {
            'warning' => 'Warning',
            'block' => 'Block',
            default => 'Unknown',
        };
    }
}
