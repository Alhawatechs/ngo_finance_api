<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    use HasFactory;

    /**
     * Slug for a class name segment: spaces to hyphens, keep alphanumeric and hyphens.
     * Used when a compact slug is needed (e.g. default class on project create).
     */
    public static function slugForName(string $name): string
    {
        $slug = trim($name);
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/[^a-zA-Z0-9\-]/', '', $slug);
        $slug = preg_replace('/-+/', '-', trim($slug, '-'));
        return $slug !== '' ? $slug : 'class';
    }

    /**
     * Segment for code block: name as-is (trimmed), colons replaced so path stays unambiguous.
     * e.g. "DH" -> "DH", "Main Office" -> "Main Office", "A:B" -> "A-B"
     */
    public static function segmentForCode(string $name): string
    {
        $segment = trim($name);
        $segment = str_replace(':', '-', $segment);
        $segment = preg_replace('/\s+/', ' ', $segment);
        return $segment !== '' ? $segment : 'class';
    }

    /**
     * Generate a unique hierarchical code for a cost center (code block format).
     * All classes of a project use the project code as prefix (e.g. 0F:Main Office, 0F:Sub Office).
     * Root: project_code:segment(name). Child: parent->code:segment(name). Subclasses keep the same prefix (e.g. 0F:Main Office:Subclass).
     * ExcludeId: when updating, exclude current record from uniqueness check.
     */
    public static function generateCode(
        string $name,
        ?int $parentId,
        ?int $projectId,
        int $organizationId,
        ?int $excludeId = null
    ): string {
        $segment = self::segmentForCode($name);
        $baseCode = null;

        if ($parentId) {
            $parent = self::where('organization_id', $organizationId)->find($parentId);
            if ($parent) {
                $baseCode = $parent->code . ':' . $segment;
            }
        }
        if ($baseCode === null && $projectId) {
            $project = Project::on(\App\Services\OfficeContext::connection())
                ->where('organization_id', $organizationId)
                ->find($projectId);
            if ($project) {
                $baseCode = $project->project_code . ':' . $segment;
            }
        }
        if ($baseCode === null) {
            $baseCode = 'GLOBAL-' . $segment;
        }

        $code = $baseCode;
        $suffix = 0;
        while (true) {
            $query = self::where('organization_id', $organizationId)->where('code', $code);
            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }
            if (! $query->exists()) {
                break;
            }
            $code = $baseCode . '-' . (++$suffix);
        }

        return $code;
    }

    /**
     * Regenerate code for this record and all descendants (e.g. after parent code changed).
     */
    public function regenerateCodeRecursive(): void
    {
        $this->refresh();
        $oldCode = $this->code;
        $newCode = self::generateCode(
            $this->name,
            $this->parent_id,
            $this->project_id,
            $this->organization_id,
            $this->id
        );
        if ($newCode !== $oldCode) {
            $this->update(['code' => $newCode]);
            foreach ($this->children as $child) {
                $child->regenerateCodeRecursive();
            }
        }
    }

    protected $fillable = [
        'organization_id',
        'parent_id',
        'project_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'parent_id')->orderBy('code');
    }

    /** Project this cost center is linked to (part of that project's class list). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Projects that use this cost center as their primary cost center (project.cost_center_id). */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Check if the given id is this node or any descendant (prevents circular parent).
     */
    public function isDescendantOf(int $id): bool
    {
        if ($this->id === $id) {
            return true;
        }
        $current = $this->parent;
        while ($current) {
            if ($current->id === $id) {
                return true;
            }
            $current = $current->parent;
        }
        return false;
    }
}
