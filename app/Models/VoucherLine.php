<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoucherLine extends Model
{
    use HasFactory, UsesOfficeConnection;

    protected $fillable = [
        'voucher_id',
        'account_id',
        'fund_id',
        'project_id',
        'donor_expenditure_code_id',
        'line_number',
        'description',
        'debit_amount',
        'credit_amount',
        'cost_center',
        'project_account_code',
    ];

    protected function casts(): array
    {
        return [
            'debit_amount' => 'decimal:2',
            'credit_amount' => 'decimal:2',
        ];
    }

    // Relationships
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function donorExpenditureCode(): BelongsTo
    {
        return $this->belongsTo(DonorExpenditureCode::class);
    }

    // Helpers
    public function isDebit(): bool
    {
        return (float) $this->debit_amount !== 0.0;
    }

    public function isCredit(): bool
    {
        return (float) $this->credit_amount !== 0.0;
    }

    public function getAmount(): float
    {
        return $this->isDebit() ? (float) $this->debit_amount : (float) $this->credit_amount;
    }
}
