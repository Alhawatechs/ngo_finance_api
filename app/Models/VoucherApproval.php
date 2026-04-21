<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'voucher_id',
        'approval_level',
        'approver_id',
        'action',
        'comments',
        'action_at',
    ];

    protected function casts(): array
    {
        return [
            'action_at' => 'datetime',
        ];
    }

    // Relationships
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    // Helpers
    public function isPending(): bool
    {
        return $this->action === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->action === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->action === 'rejected';
    }

    public function getLevelName(): string
    {
        $roles = config('erp.approval.roles');
        return $roles[$this->approval_level] ?? 'Level ' . $this->approval_level;
    }
}
