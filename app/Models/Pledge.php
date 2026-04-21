<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pledge extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'donor_id',
        'grant_id',
        'pledge_number',
        'pledge_date',
        'description',
        'currency',
        'pledged_amount',
        'received_amount',
        'outstanding_amount',
        'expected_fulfillment_date',
        'payment_schedule',
        'payment_schedule_details',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pledge_date' => 'date',
            'expected_fulfillment_date' => 'date',
            'pledged_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'payment_schedule_details' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PledgePayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
