<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IssueReport extends Model
{
    protected $table = 'ISSUE_REPORT';

    protected $primaryKey = 'report_id';

    public $timestamps = false;

    protected $fillable = [
        'tracking_code',
        'user_id',
        'category_id',
        'team_id',
        'description',
        'location_id',
        'landmark',
        'latitude',
        'longitude',
        'status',
        'date_submitted',
        'date_resolved',
        'archived_at',
        'archived_by',
    ];

    // Get the resident who submitted this report.
    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Get the report category.
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'category_id');
    }

    // Get the assigned response team.
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id', 'team_id');
    }

    // Get the report location.
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id', 'location_id');
    }

    // Get the full status history.
    public function statusLogs(): HasMany
    {
        return $this->hasMany(StatusLog::class, 'report_id', 'report_id');
    }

    // Get the most recent status change.
    public function latestStatusLog(): HasOne
    {
        return $this->hasOne(StatusLog::class, 'report_id', 'report_id')->latestOfMany('log_id');
    }

    // Get the most recently uploaded attachment.
    public function latestAttachment(): HasOne
    {
        return $this->hasOne(Attachment::class, 'report_id', 'report_id')->latestOfMany('attachment_id');
    }
}
