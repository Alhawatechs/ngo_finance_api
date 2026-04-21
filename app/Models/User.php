<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles;

    protected $fillable = [
        'organization_id',
        'office_id',
        'can_manage_all_offices',
        'employee_id',
        'name',
        'email',
        'password',
        'phone',
        'position',
        'department',
        'status',
        'approval_level',
        'approval_limit',
        'avatar_path',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Add initials and avatar_url to serialization without using $appends
     * (avoids "Cannot redeclare $appends" when traits also define it)
     */
    protected static function booted(): void
    {
        static::retrieved(function (User $user) {
            $user->append(['initials', 'avatar_url']);
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'approval_limit' => 'decimal:2',
            'can_manage_all_offices' => 'boolean',
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

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'created_by');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'created_by');
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class, 'employee_id');
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByOffice($query, $officeId)
    {
        return $query->where('office_id', $officeId);
    }

    // Helper methods
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canApproveAmount(float $amount): bool
    {
        if ($this->approval_limit === null) {
            return true;
        }
        return $amount <= $this->approval_limit;
    }

    public function getApprovalLevel(): int
    {
        return $this->approval_level ?? 0;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if ($this->avatar_path) {
            return asset('storage/' . $this->avatar_path);
        }
        return null;
    }

    public function getInitialsAttribute(): string
    {
        $names = explode(' ', $this->name ?? '');
        $initials = '';
        foreach ($names as $name) {
            if ($name !== '') {
                $initials .= strtoupper(substr($name, 0, 1));
            }
        }
        return substr($initials, 0, 2) ?: '?';
    }
}
