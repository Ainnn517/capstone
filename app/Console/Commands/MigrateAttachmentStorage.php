<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MigrateAttachmentStorage extends Command
{
    protected $signature = 'attachments:migrate-storage {--dry-run : Report the number of BLOB attachments without moving them}';

    protected $description = 'Move legacy attachment BLOBs to private filesystem storage';

    public function handle(): int
    {
        $count = Attachment::query()->whereNotNull('file_data')->count();
        if ($this->option('dry-run')) {
            $this->info("{$count} attachment(s) are stored in the database.");

            return self::SUCCESS;
        }

        Attachment::query()
            ->whereNotNull('file_data')
            ->orderBy('attachment_id')
            ->chunkById(100, function ($attachments): void {
                foreach ($attachments as $attachment) {
                    $bytes = $attachment->file_data;
                    if (is_resource($bytes)) {
                        $bytes = stream_get_contents($bytes);
                    }
                    if (! is_string($bytes) || $bytes === '') {
                        throw new RuntimeException("Attachment {$attachment->attachment_id} has unreadable BLOB data.");
                    }

                    $extension = match ($attachment->mime_type) {
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        default => throw new RuntimeException("Attachment {$attachment->attachment_id} has an unsupported MIME type."),
                    };
                    $path = 'report-attachments/'.bin2hex(random_bytes(32)).'.'.$extension;
                    if (! Storage::disk('local')->put($path, $bytes)) {
                        throw new RuntimeException("Could not write attachment {$attachment->attachment_id} to private storage.");
                    }

                    try {
                        DB::transaction(function () use ($attachment, $path): void {
                            Attachment::query()
                                ->where('attachment_id', $attachment->attachment_id)
                                ->whereNotNull('file_data')
                                ->update(['file_path' => $path, 'file_data' => null]);
                        });
                    } catch (\Throwable $exception) {
                        Storage::disk('local')->delete($path);
                        throw $exception;
                    }
                }
            }, 'attachment_id');

        $this->info("Migrated {$count} attachment(s) to private filesystem storage.");

        return self::SUCCESS;
    }
}
