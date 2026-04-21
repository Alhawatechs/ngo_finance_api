<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * Override users() to use User::class directly.
     * Must match Spatie's morphedByMany (not morphToMany) for model_has_roles pivot shape.
     */
    public function users(): MorphToMany
    {
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);

        return $this->morphedByMany(
            User::class,
            'model',
            config('permission.table_names.model_has_roles'),
            $registrar->pivotRole,
            config('permission.column_names.model_morph_key'),
        );
    }
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'guard_name',
        'organization_id',
        'office_id',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function scopeByOffice($query, $officeId)
    {
        return $query->where('office_id', $officeId);
    }

    public function scopeOrgLevel($query)
    {
        return $query->whereNull('office_id');
    }
}
