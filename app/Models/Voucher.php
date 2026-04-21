<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'office_id',
        'province_code',
        'location_code',
        'project_id',
        'journal_id',
        'fund_id',
        'voucher_number',
        'voucher_type',
        'voucher_date',
        'payee_name',
        'description',
        'currency',
        'exchange_rate',
        'total_amount',
        'base_currency_amount',
        'payment_method',
        'check_number',
        'bank_reference',
        'status',
        'current_approval_level',
        'required_approval_level',
        'journal_entry_id',
        'created_by',
        'submitted_by',
        'submitted_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'total_amount' => 'decimal:2',
            'base_currency_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VoucherLine::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(VoucherApproval::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    // Scopes
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeByOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByOffice($query, $officeId)
    {
        return $query->where('office_id', $officeId);
    }

    // Helpers
    public function isPending(): bool
    {
        return in_array($this->status, ['draft', 'submitted', 'pending_approval']);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    public function canBeSubmitted(): bool
    {
        return $this->status === 'draft' && $this->lines()->count() > 0;
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function getNextApprovalLevel(): int
    {
        return $this->current_approval_level + 1;
    }

    public function needsMoreApproval(): bool
    {
        return $this->current_approval_level < $this->required_approval_level;
    }

    public function calculateRequiredApprovalLevel(): int
    {
        $thresholds = config('erp.approval.thresholds');
        $maxLevel = (int) config('erp.approval.levels', 4);
        $level = 1;

        foreach ($thresholds as $lvl => $threshold) {
            if ((int) $lvl > $maxLevel) {
                continue;
            }
            if ($this->base_currency_amount >= $threshold) {
                $level = (int) $lvl;
            }
        }

        return min($level, $maxLevel);
    }

    public function submit(User $user): bool
    {
        if (!$this->canBeSubmitted()) {
            return false;
        }

        $this->required_approval_level = $this->calculateRequiredApprovalLevel();
        $this->status = 'pending_approval';
        $this->current_approval_level = 0;
        $this->submitted_by = $user->id;
        $this->submitted_at = now();

        // Create approval records
        for ($i = 1; $i <= $this->required_approval_level; $i++) {
            $this->approvals()->create([
                'approval_level' => $i,
                'action' => 'pending',
            ]);
        }

        return $this->save();
    }

    public function approve(User $user, ?string $comments = null): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $nextLevel = $this->getNextApprovalLevel();

        // Record approval
        $approval = $this->approvals()->where('approval_level', $nextLevel)->first();
        if ($approval) {
            $approval->update([
                'approver_id' => $user->id,
                'action' => 'approved',
                'comments' => $comments,
                'action_at' => now(),
            ]);
        }

        $this->current_approval_level = $nextLevel;

        if (!$this->needsMoreApproval()) {
            $this->status = 'approved';
        }

        return $this->save();
    }

    public function reject(User $user, string $reason): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $nextLevel = $this->getNextApprovalLevel();

        // Record rejection
        $approval = $this->approvals()->where('approval_level', $nextLevel)->first();
        if ($approval) {
            $approval->update([
                'approver_id' => $user->id,
                'action' => 'rejected',
                'comments' => $reason,
                'action_at' => now(),
            ]);
        }

        $this->status = 'rejected';
        $this->rejection_reason = $reason;

        return $this->save();
    }
}
