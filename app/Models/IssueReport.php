<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IssueReport extends Model
{
    protected $table = 'ISSUE_REPORT';

    public const STATUSES = ['Submitted', 'Validated', 'Dispatched', 'Resolved', 'Rejected', 'Withdrawn'];

    // Finished reports: read-only for administrators and "past" for residents.
    public const CLOSED_STATUSES = ['Resolved', 'Rejected', 'Withdrawn'];

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
        'resident_confirmed_at',
        'reopen_count',
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

    // ----- Resident confirmation of a resolved report -----

    // Last moment the resident can confirm or reopen; null unless the report is Resolved.
    public function confirmationDeadline(): ?CarbonImmutable
    {
        if ($this->status !== 'Resolved' || $this->date_resolved === null) {
            return null;
        }

        return CarbonImmutable::parse($this->date_resolved, config('app.timezone'))
            ->addDays((int) config('dss.resident_confirmation_days'));
    }

    // 'confirmed' (resident said it is fixed), 'awaiting' (resident can still answer),
    // 'accepted' (no answer in time, so the fix stands), or null when not Resolved.
    public function confirmationState(): ?string
    {
        if ($this->status !== 'Resolved') {
            return null;
        }
        if ($this->resident_confirmed_at !== null) {
            return 'confirmed';
        }

        return $this->confirmationDeadline()?->isFuture() ? 'awaiting' : 'accepted';
    }

    // Last moment the resident can reopen a resolved report; null unless it is Resolved.
    public function reopenDeadline(): ?CarbonImmutable
    {
        if ($this->status !== 'Resolved' || $this->date_resolved === null) {
            return null;
        }

        return CarbonImmutable::parse($this->date_resolved, config('app.timezone'))
            ->addDays((int) config('dss.reopen_window_days'));
    }

    // The resident can still say the problem is back: inside the window and under the limit.
    public function canReopen(): bool
    {
        return (bool) $this->reopenDeadline()?->isFuture()
            && $this->reopen_count < (int) config('dss.max_reopens');
    }

    // Resolved reports the resident has not answered yet and can still answer.
    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->where('status', 'Resolved')
            ->whereNull('resident_confirmed_at')
            ->where('date_resolved', '>', now()->subDays((int) config('dss.resident_confirmation_days')));
    }
}
