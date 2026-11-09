<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
