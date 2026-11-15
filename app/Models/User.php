<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmailContract
{
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
        'privacy_consent_at',
        'privacy_notice_version',
        'email_verified_at',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
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
