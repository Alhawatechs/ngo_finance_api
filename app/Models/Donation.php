<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'donor_id',
        'grant_id',
        'fund_id',
        'donation_number',
        'donation_date',
        'received_date',
        'donation_type',
        'description',
        'currency',
        'exchange_rate',
        'amount',
        'base_currency_amount',
        'receipt_method',
        'bank_reference',
        'check_number',
        'restriction_type',
        'restriction_description',
        'receipt_voucher_id',
        'bank_account_id',
        'status',
        'acknowledgment_date',
        'acknowledgment_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'donation_date' => 'date',
            'received_date' => 'date',
            'acknowledgment_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'amount' => 'decimal:2',
            'base_currency_amount' => 'decimal:2',
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

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
