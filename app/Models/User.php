<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    // Tell Laravel which column stores this user's password hash.
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
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
