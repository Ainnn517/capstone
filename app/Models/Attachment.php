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

    public const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];

    // The only shape a stored file path may have, so a bad database value never reaches the disk.
    public const STORED_PATH = '#\Areport-attachments/[a-f0-9]{64}\.(?:jpg|png|gif|webp|mp4|webm|mov)\z#';

    public function isVideo(): bool
    {
        return array_key_exists((string) $this->mime_type, self::VIDEO_TYPES);
    }

    // Get the report that owns this attachment.
    public function report(): BelongsTo
    {
        return $this->belongsTo(IssueReport::class, 'report_id', 'report_id');
    }
}
