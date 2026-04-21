<?php

namespace App\Models;

use App\Models\Concerns\UsesOfficeConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes, UsesOfficeConnection;

    protected $fillable = [
        'organization_id',
        'vendor_code',
        'name',
        'vendor_type',
        'tax_id',
        'contact_person',
        'email',
        'phone',
        'mobile',
        'address',
        'city',
        'country',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'payment_terms',
        'currency',
        'credit_limit',
        'current_balance',
        'ap_account_id',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function apAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'ap_account_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('vendor_type', $type);
    }

    // Helpers
    public function updateBalance(): void
    {
        $this->current_balance = $this->invoices()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->sum('balance_due');
        $this->save();
    }

    public function getOutstandingInvoices()
    {
        return $this->invoices()
            ->whereIn('status', ['approved', 'partially_paid'])
            ->orderBy('due_date')
            ->get();
    }

    public function isOverCreditLimit(): bool
    {
        if (!$this->credit_limit) {
            return false;
        }
        return $this->current_balance > $this->credit_limit;
    }
}
