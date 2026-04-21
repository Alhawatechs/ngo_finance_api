<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-project overlay on fiscal periods: a row means the project has closed or locked
 * posting for that calendar period (when the organization fiscal period is still open).
 * Absence of a row means project posting is allowed for that period.
 */
class ProjectFiscalPeriodStatus extends Model
{
    use UsesOfficeConnection;

    protected $table = 'project_fiscal_period_statuses';

    protected $fillable = [
        'organization_id',
        'project_id',
        'fiscal_period_id',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function blocksPosting(): bool
    {
        return in_array($this->status, ['closed', 'locked'], true);
    }
}
