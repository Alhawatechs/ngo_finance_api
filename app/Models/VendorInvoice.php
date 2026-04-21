<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorInvoice extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'office_id',
        'vendor_id',
        'project_id',
        'invoice_number',
        'vendor_invoice_number',
        'invoice_date',
        'due_date',
        'received_date',
        'description',
        'currency',
        'exchange_rate',
        'subtotal',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'balance_due',
        'status',
        'voucher_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'received_date' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorInvoiceLine::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    // Scopes
    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', ['approved', 'partially_paid']);
    }

    public function scopeOverdue($query)
    {
        return $query->unpaid()->where('due_date', '<', now());
    }

    public function scopeByVendor($query, int $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    // Helpers
    public function recalculateTotals(): void
    {
        $this->subtotal = $this->lines()->sum('amount');
        $this->tax_amount = $this->lines()->sum('tax_amount');
        $this->total_amount = $this->subtotal + $this->tax_amount;
        $this->balance_due = $this->total_amount - $this->paid_amount;
        $this->save();
    }

    public function applyPayment(float $amount): void
    {
        $this->paid_amount += $amount;
        $this->balance_due = $this->total_amount - $this->paid_amount;
        
        if ($this->balance_due <= 0) {
            $this->status = 'paid';
        } else {
            $this->status = 'partially_paid';
        }
        
        $this->save();
    }

    public function isOverdue(): bool
    {
        return $this->due_date < now() && $this->balance_due > 0;
    }

    public function getDaysOverdue(): int
    {
        if (!$this->isOverdue()) {
            return 0;
        }
        return now()->diffInDays($this->due_date);
    }
}
