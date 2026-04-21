<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Journal extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'office_id',
        'province_code',
        'location_code',
        'fund_id',
        'currency',
        'exchange_rate',
        'voucher_type',
        'payment_method',
        'default_payee_name',
        'voucher_description_template',
        'name',
        'code',
        'is_active',
    ];

    /**
     * Coding-block location (1/2/3) when stored on the journal row; otherwise derived from linked office (main vs sub).
     * Must not ignore the DB column — journal books can set a default location for vouchers.
     */
    public function getLocationCodeAttribute(?string $value): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        if (! $this->relationLoaded('office') && $this->office_id) {
            $this->load('office');
        }
        $office = $this->office;

        return ($office && $office->is_head_office) ? '1' : '2';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'exchange_rate' => 'decimal:8',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'journal_id');
    }

    /**
     * Resolve journal from route: same org only; include soft-deleted rows when user may manage deleted books.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field = $field ?? $this->getRouteKeyName();
        $query = $this->where($field, $value);

        if (auth()->hasUser()) {
            $query->where('organization_id', auth()->user()->organization_id);
        }

        if (auth()->user()?->can('delete-journal-books')) {
            $query->withTrashed();
        }

        return $query->firstOrFail();
    }
}
