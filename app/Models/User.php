<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $table = 'USERS';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    protected $fillable = [
        'username',
        'full_name',
        'email',
        'password_hash',
        'role',
        'account_status',
        'contact_number',
    ];

    protected $hidden = ['password_hash'];

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
