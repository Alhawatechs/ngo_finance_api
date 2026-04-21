<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DonorExpenditureCode extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'donor_id',
        'code',
        'name',
        'parent_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(DonorExpenditureCode::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(DonorExpenditureCode::class, 'parent_id')->orderBy('sort_order');
    }
}
