<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'NOTIFICATION';

    protected $primaryKey = 'notif_id';

    public $timestamps = false;

    protected $fillable = ['report_id', 'user_id', 'message', 'type', 'is_read'];

    // Get the report this notification is about.
    public function report(): BelongsTo
    {
        return $this->belongsTo(IssueReport::class, 'report_id', 'report_id');
    }

    // Get the user who receives this notification.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
