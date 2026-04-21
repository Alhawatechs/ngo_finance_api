<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportingLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'subordinate_position_id',
        'supervisor_position_id',
        'relationship_type',
        'description',
        'is_primary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subordinatePosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'subordinate_position_id');
    }

    public function supervisorPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'supervisor_position_id');
    }

    // Helpers
    public function getRelationshipTypeLabel(): string
    {
        return match($this->relationship_type) {
            'direct' => 'Direct Report',
            'dotted' => 'Dotted Line',
            'functional' => 'Functional',
            'project' => 'Project-based',
            default => 'Unknown',
        };
    }
}
