<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'organizational_unit_id',
        'reports_to_id',
        'title',
        'code',
        'level',
        'description',
        'responsibilities',
        'qualifications',
        'grade',
        'headcount',
        'min_salary',
        'max_salary',
        'is_supervisory',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_supervisory' => 'boolean',
            'is_active' => 'boolean',
            'headcount' => 'integer',
            'grade' => 'integer',
            'sort_order' => 'integer',
            'min_salary' => 'decimal:2',
            'max_salary' => 'decimal:2',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'reports_to_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Position::class, 'reports_to_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(PositionAssignment::class)->where('is_active', true);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'position_assignments')
            ->withPivot(['start_date', 'end_date', 'is_primary', 'is_acting', 'employment_type', 'is_active'])
            ->wherePivot('is_active', true);
    }

    public function reportingLinesAsSubordinate(): HasMany
    {
        return $this->hasMany(ReportingLine::class, 'subordinate_position_id');
    }

    public function reportingLinesAsSupervisor(): HasMany
    {
        return $this->hasMany(ReportingLine::class, 'supervisor_position_id');
    }

    // Helpers
    public function getCurrentHolder(): ?User
    {
        $assignment = $this->activeAssignments()
            ->where('is_primary', true)
            ->where('is_acting', false)
            ->first();
        
        return $assignment?->user;
    }

    public function getActingHolder(): ?User
    {
        $assignment = $this->activeAssignments()
            ->where('is_acting', true)
            ->first();
        
        return $assignment?->user;
    }

    public function isVacant(): bool
    {
        return $this->activeAssignments()->count() === 0;
    }

    public function getFilledCount(): int
    {
        return $this->activeAssignments()
            ->where('is_acting', false)
            ->count();
    }

    public function getVacancyCount(): int
    {
        return max(0, $this->headcount - $this->getFilledCount());
    }

    public function getAllReportingPositionIds(): array
    {
        $ids = [];
        
        foreach ($this->directReports as $report) {
            $ids[] = $report->id;
            $ids = array_merge($ids, $report->getAllReportingPositionIds());
        }
        
        return $ids;
    }

    public function getSupervisorChain(): array
    {
        $chain = [];
        $supervisor = $this->reportsTo;
        
        while ($supervisor) {
            $chain[] = $supervisor;
            $supervisor = $supervisor->reportsTo;
        }
        
        return $chain;
    }

    public function getLevelLabel(): string
    {
        return match($this->level) {
            'executive' => 'Executive',
            'senior_management' => 'Senior Management',
            'middle_management' => 'Middle Management',
            'supervisory' => 'Supervisory',
            'professional' => 'Professional',
            'support' => 'Support Staff',
            default => 'Unknown',
        };
    }
}
