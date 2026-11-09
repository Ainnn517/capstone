<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    protected $table = 'ADMIN_AUDIT_LOG';

    protected $primaryKey = 'audit_id';

    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'action', 'details'];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];
}
