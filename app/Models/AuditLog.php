<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'organization_id',
        'user_id',
        'user_name',
        'action',
        'model_type',
        'model_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
        'method',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeByOrg(Builder $query, ?int $organizationId): Builder
    {
        if ($organizationId === null) {
            return $query;
        }
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByUser(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query;
        }
        return $query->where('user_id', $userId);
    }

    public function scopeByModel(Builder $query, ?string $modelType): Builder
    {
        if ($modelType === null || $modelType === '') {
            return $query;
        }
        return $query->where('model_type', $modelType);
    }

    public function scopeByAction(Builder $query, ?string $action): Builder
    {
        if ($action === null || $action === '') {
            return $query;
        }
        return $query->where('action', $action);
    }

    public function scopeByDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }
        return $query;
    }
}
