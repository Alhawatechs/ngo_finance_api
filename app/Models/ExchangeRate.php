<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'from_currency',
        'to_currency',
        'rate',
        'effective_date',
        'source',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'effective_date' => 'date',
        ];
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Static helpers
    public static function getRate(int $organizationId, string $fromCurrency, string $toCurrency, ?string $date = null): ?float
    {
        $date = $date ?? now()->format('Y-m-d');

        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        $rate = self::where('organization_id', $organizationId)
            ->where('from_currency', $fromCurrency)
            ->where('to_currency', $toCurrency)
            ->where('effective_date', '<=', $date)
            ->orderBy('effective_date', 'desc')
            ->first();

        if ($rate) {
            return $rate->rate;
        }

        // Try reverse rate
        $reverseRate = self::where('organization_id', $organizationId)
            ->where('from_currency', $toCurrency)
            ->where('to_currency', $fromCurrency)
            ->where('effective_date', '<=', $date)
            ->orderBy('effective_date', 'desc')
            ->first();

        if ($reverseRate && $reverseRate->rate != 0) {
            return 1 / $reverseRate->rate;
        }

        return null;
    }

    public static function convert(float $amount, string $fromCurrency, string $toCurrency, int $organizationId, ?string $date = null): ?float
    {
        $rate = self::getRate($organizationId, $fromCurrency, $toCurrency, $date);
        
        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }
}
