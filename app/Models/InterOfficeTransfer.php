<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inter-office transfer metadata. Lives in central DB only.
 * sent_voucher_id / received_voucher_id reference vouchers in the respective office DBs (no FK).
 */
class InterOfficeTransfer extends Model
{
    protected $connection = 'mysql';

    protected $table = 'inter_office_transfers';

    protected $fillable = [
        'organization_id',
        'transfer_number',
        'transfer_date',
        'from_office_id',
        'to_office_id',
        'amount',
        'currency',
        'description',
        'transfer_method',
        'status',
        'sent_voucher_id',
        'received_voucher_id',
        'created_by',
        'received_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'received_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'from_office_id');
    }

    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'to_office_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
