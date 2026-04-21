<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalWorkflowSetting extends Model
{
    protected $fillable = [
        'organization_id',
        'enable_approval_workflow',
        'approval_levels',
        'approval_limit_level1',
        'approval_limit_level2',
        'approval_limit_level3',
        'require_dual_signature',
        'dual_signature_threshold',
        'allow_self_approval',
        'auto_approve_below',
        'require_supporting_documents',
    ];

    protected $casts = [
        'enable_approval_workflow' => 'boolean',
        'require_dual_signature' => 'boolean',
        'allow_self_approval' => 'boolean',
        'require_supporting_documents' => 'boolean',
        'approval_levels' => 'integer',
        'approval_limit_level1' => 'decimal:2',
        'approval_limit_level2' => 'decimal:2',
        'approval_limit_level3' => 'decimal:2',
        'dual_signature_threshold' => 'decimal:2',
        'auto_approve_below' => 'decimal:2',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requiresApproval(float $amount): bool
    {
        if (!$this->enable_approval_workflow) {
            return false;
        }
        return $amount >= (float) ($this->auto_approve_below ?? 0);
    }

    public function getApprovalLevel(float $amount): int
    {
        if ($amount <= (float) ($this->approval_limit_level1 ?? 0)) {
            return 1;
        }
        if ($amount <= (float) ($this->approval_limit_level2 ?? 0)) {
            return 2;
        }
        return 3;
    }

    public function requiresDualSignature(float $amount): bool
    {
        if (!$this->require_dual_signature) {
            return false;
        }
        return $amount >= (float) ($this->dual_signature_threshold ?? 0);
    }
}
