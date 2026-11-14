<?php

namespace App\Console\Commands;

use App\Support\MysqlCredentials;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

// Nightly backup: a compressed database dump and an archive of uploaded photos, with old
// backups pruned. Restore with `php artisan backup:restore <file>`.
class BackupDatabase extends Command
{
    protected $signature = 'backup:run
        {--path= : Directory to write to (defaults to BACKUP_PATH)}
        {--no-attachments : Skip the uploaded photos archive}';

    protected $description = 'Back up the database and uploaded photos, then prune old backups';

    public function handle(): int
    {
        $directory = rtrim((string) ($this->option('path') ?: config('backup.path')), '/');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error("Cannot create the backup directory: {$directory}");

            return self::FAILURE;
        }

        $stamp = now()->format('Ymd-His');
        $database = (string) config('database.connections.'.config('backup.connection').'.database');
        $dump = "{$directory}/{$database}-{$stamp}.sql";

        $previousUmask = umask(0077);
        try {
            $error = MysqlCredentials::with(function (string $options) use ($database, $dump): ?string {
                $process = new Process([
                    'mysqldump', "--defaults-extra-file={$options}",
                    '--single-transaction', '--quick', '--no-tablespaces', '--routines', '--triggers',
                    '--default-character-set=utf8mb4', "--result-file={$dump}", $database,
                ]);
                $process->setTimeout(3600)->run();

                return $process->isSuccessful() ? null : trim($process->getErrorOutput());
            });
            if ($error !== null) {
                @unlink($dump);
                $this->error('mysqldump failed: '.$error);

                return self::FAILURE;
            }
            $compressed = $this->gzip($dump);
            $this->info('Database: '.$compressed.' ('.$this->size($compressed).')');

            $photos = storage_path('app/private');
            if (config('backup.include_attachments') && ! $this->option('no-attachments') && is_dir($photos)) {
                $archive = "{$directory}/attachments-{$stamp}.tar.gz";
                $process = new Process(['tar', '-czf', $archive, '-C', dirname($photos), basename($photos)]);
                $process->setTimeout(3600)->run();
                if (! $process->isSuccessful()) {
                    $this->error('Photo archive failed: '.trim($process->getErrorOutput()));

                    return self::FAILURE;
                }
                $this->info('Photos: '.$archive.' ('.$this->size($archive).')');
            }
        } finally {
            umask($previousUmask);
        }

        $this->prune($directory);

        return self::SUCCESS;
    }

    private function gzip(string $path): string
    {
        $target = $path.'.gz';
        $in = fopen($path, 'rb');
        $out = gzopen($target, 'wb6');
        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }
        fclose($in);
        gzclose($out);
        unlink($path);

        return $target;
    }

    private function prune(string $directory): void
    {
        $days = (int) config('backup.keep_days');
        if ($days <= 0) {
            return;
        }
        $cutoff = now()->subDays($days)->getTimestamp();
        foreach (array_merge(glob("{$directory}/*.sql.gz") ?: [], glob("{$directory}/attachments-*.tar.gz") ?: []) as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $this->line('Pruned '.basename($file));
            }
        }
    }

    private function size(string $path): string
    {
        $bytes = (int) filesize($path);

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
