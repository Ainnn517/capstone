<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use MustVerifyEmail;
    use Notifiable;

    protected $table = 'USERS';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'full_name',
        'email',
        'password_hash',
        'contact_number',
        'address_location_id',
        'address_details',
        'email_notifications',
        'privacy_consent_at',
        'privacy_notice_version',
        'email_verified_at',
    ];

    protected $hidden = ['password_hash'];

    // Same as the column default: report-update emails are on unless the resident turns them off.
    protected $attributes = ['email_notifications' => true];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_notifications' => 'boolean',
        ];
    }

    // Two administrator levels: super (everything, including admin accounts) and staff
    // (shown as "Admin": works on reports and sign-ups). Both see the whole dashboard.
    public const ADMIN_LEVELS = ['super', 'staff'];

    // Does this account pass a route's role list? "resident" and "admin" are the account
    // roles; "staff" means an administrator who can make changes (super or staff level);
    // "super" means a super administrator only.
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            $passes = match ($role) {
                'super' => $this->role === 'admin' && $this->admin_level === 'super',
                'staff' => $this->role === 'admin' && in_array($this->admin_level, ['super', 'staff'], true),
                default => $this->role === $role,
            };
            if ($passes) {
                return true;
            }
        }

        return false;
    }

    public function adminLevelLabel(): ?string
    {
        return match ($this->admin_level) {
            'super' => 'Super admin',
            'staff' => 'Admin',
            default => null,
        };
    }

    // The street and area of the resident's home address.
    public function addressLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'address_location_id', 'location_id');
    }

    // Home address as one line, for administrators checking residency.
    public function addressLine(): ?string
    {
        $parts = array_filter([
            $this->address_details,
            $this->addressLocation && $this->addressLocation->name !== 'Area-wide' ? $this->addressLocation->name : null,
            $this->addressLocation?->area_name,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    // Tell Laravel which column stores this user's password hash.
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    // Fingerprint of the current password hash, stored in each session so a password
    // change or reset ends every other session on its next request.
    public function sessionPasswordFingerprint(): string
    {
        return hash_hmac('sha256', (string) $this->password_hash, (string) config('app.key'));
    }

    // Get reports submitted by this user.
    public function reports(): HasMany
    {
        return $this->hasMany(IssueReport::class, 'user_id', 'user_id');
    }

    // Get status changes made by this user.
    public function statusChanges(): HasMany
    {
        return $this->hasMany(StatusLog::class, 'changed_by', 'user_id');
    }
}
