<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// Repackage stored MOV videos as MP4 so every browser can play them (Chrome, Brave and Edge
// refuse video/quicktime). The picture and sound are copied, not re-encoded. Safe to re-run:
// a video that cannot be repackaged is left as it is.
class ConvertMovAttachments extends Command
{
    protected $signature = 'attachments:convert-mov {--dry-run : List the MOV videos without converting them}';

    protected $description = 'Repackage stored MOV videos as MP4 so all browsers can play them';

    public function handle(): int
    {
        $configured = trim((string) config('security.uploads.video_ffmpeg'));
        $ffmpeg = $configured === '' ? null : (is_executable($configured) ? $configured : (new ExecutableFinder)->find($configured));
        if ($ffmpeg === null) {
            $this->error('ffmpeg is not installed, so videos cannot be converted.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $videos = Attachment::query()
            ->where('mime_type', 'video/quicktime')
            ->whereNotNull('file_path')
            ->get();
        $this->line("MOV videos found: {$videos->count()}");
        if ($this->option('dry-run') || $videos->isEmpty()) {
            return self::SUCCESS;
        }

        $converted = 0;
        foreach ($videos as $video) {
            if (! preg_match(Attachment::STORED_PATH, $video->file_path) || ! $disk->exists($video->file_path)) {
                $this->warn("Skipped attachment {$video->attachment_id}: file not found.");

                continue;
            }

            $newPath = 'report-attachments/'.bin2hex(random_bytes(32)).'.mp4';
            $process = new Process([
                $ffmpeg, '-v', 'error', '-y', '-i', $disk->path($video->file_path),
                '-map', '0:v?', '-map', '0:a?', '-map_metadata', '-1', '-map_chapters', '-1',
                '-c', 'copy', '-movflags', '+faststart', '-f', 'mp4', $disk->path($newPath),
            ]);
            $process->setTimeout(300)->run();
            if (! $process->isSuccessful() || ! $disk->exists($newPath) || $disk->size($newPath) === 0) {
                $disk->delete($newPath);
                $this->warn("Skipped attachment {$video->attachment_id}: it could not be repackaged as MP4.");

                continue;
            }

            // Point the record at the new file first; the old file is removed only after that.
            $oldPath = $video->file_path;
            $video->forceFill(['mime_type' => 'video/mp4', 'file_path' => $newPath])->save();
            $disk->delete($oldPath);
            $converted++;
        }

        $this->info("Converted {$converted} video(s) to MP4.");

        return self::SUCCESS;
    }
}
