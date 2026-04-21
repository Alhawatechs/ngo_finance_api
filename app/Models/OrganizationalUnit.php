<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrganizationalUnit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'parent_id',
        'name',
        'code',
        'type',
        'description',
        'head_title',
        'head_user_id',
        'level',
        'sort_order',
        'color',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'level' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(OrganizationalUnit::class, 'parent_id')->orderBy('sort_order');
    }

    public function allChildren(): HasMany
    {
        return $this->children()->with('allChildren');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    // Helpers
    public function getAncestors(): array
    {
        $ancestors = [];
        $unit = $this->parent;
        
        while ($unit) {
            array_unshift($ancestors, $unit);
            $unit = $unit->parent;
        }
        
        return $ancestors;
    }

    public function getFullPath(): string
    {
        $ancestors = $this->getAncestors();
        $names = array_map(fn($a) => $a->name, $ancestors);
        $names[] = $this->name;
        
        return implode(' > ', $names);
    }

    public function getAllDescendantIds(): array
    {
        $ids = [$this->id];
        
        foreach ($this->children as $child) {
            $ids = array_merge($ids, $child->getAllDescendantIds());
        }
        
        return $ids;
    }

    public function getTotalPositions(): int
    {
        $count = $this->positions()->count();
        
        foreach ($this->children as $child) {
            $count += $child->getTotalPositions();
        }
        
        return $count;
    }

    public function getTotalStaff(): int
    {
        $count = $this->positions()->whereHas('assignments', function ($q) {
            $q->where('is_active', true);
        })->count();
        
        foreach ($this->children as $child) {
            $count += $child->getTotalStaff();
        }
        
        return $count;
    }
}
