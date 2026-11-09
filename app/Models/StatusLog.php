<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StatusLog extends Model
{
    protected $table = 'STATUS_LOG';

    protected $primaryKey = 'log_id';

    public $timestamps = false;

    protected $fillable = ['report_id', 'changed_by', 'old_status', 'new_status', 'remarks'];

    // Get the report linked to this status change.
    public function report(): BelongsTo
    {
        return $this->belongsTo(IssueReport::class, 'report_id', 'report_id');
    }

    // Get the user who made this change.
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
