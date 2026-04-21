<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundRequest extends Model
{
    use SoftDeletes;

    protected $table = 'fund_requests';

    protected $fillable = [
        'organization_id',
        'grant_id',
        'project_id',
        'request_number',
        'request_date',
        'request_type',
        'description',
        'currency',
        'requested_amount',
        'approved_amount',
        'received_amount',
        'expected_receipt_date',
        'received_date',
        'status',
        'rejection_reason',
        'donation_id',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'expected_receipt_date' => 'date',
            'received_date' => 'date',
            'approved_at' => 'datetime',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
