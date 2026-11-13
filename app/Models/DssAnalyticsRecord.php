<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DssAnalyticsRecord extends Model
{
    protected $table = 'DSS_ANALYTICS_RECORD';

    protected $primaryKey = 'snapshot_id';

    public $timestamps = false;

    protected $fillable = ['created_by', 'source_report_count', 'recommendations'];

    protected $casts = [
        'recommendations' => 'array',
        'generated_at' => 'datetime',
    ];

    // The administrator who saved the snapshot (null if that account was deleted).
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }
}
