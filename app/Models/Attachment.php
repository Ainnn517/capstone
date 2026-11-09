<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $table = 'ATTACHMENT';

    protected $primaryKey = 'attachment_id';

    public $timestamps = false;

    protected $fillable = ['report_id', 'file_path', 'mime_type', 'file_data'];

    // Get the report that owns this attachment.
    public function report(): BelongsTo
    {
        return $this->belongsTo(IssueReport::class, 'report_id', 'report_id');
    }
}
