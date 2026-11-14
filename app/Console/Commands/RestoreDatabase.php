<?php

namespace App\Console\Commands;

use App\Support\MysqlCredentials;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

// Restores a backup made by backup:run. This replaces the current data, so it asks you to
// type the database name first unless --force is given.
class RestoreDatabase extends Command
{
    protected $signature = 'backup:restore
        {file : The .sql.gz (or .sql) file from backup:run}
        {--force : Do not ask for confirmation}';

    protected $description = 'Restore the database from a backup (replaces the current data)';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Backup file not found: {$file}");

            return self::FAILURE;
        }
        $database = (string) config('database.connections.'.config('backup.connection').'.database');

        if (! $this->option('force')) {
            $this->warn("This replaces ALL current data in \"{$database}\" with the backup ".basename($file).'.');
            $this->warn('Take a fresh backup first (php artisan backup:run) if you might need the current data.');
            if ($this->ask('Type the database name to continue') !== $database) {
                $this->info('Restore cancelled.');

                return self::FAILURE;
            }
        }

        $error = MysqlCredentials::with(function (string $options) use ($database, $file): ?string {
            $process = new Process(['mysql', "--defaults-extra-file={$options}", '--default-character-set=utf8mb4', $database]);
            $process->setInput($this->chunks($file));
            $process->setTimeout(3600)->run();

            return $process->isSuccessful() ? null : trim($process->getErrorOutput());
        });
        if ($error !== null) {
            $this->error('Restore failed: '.$error);

            return self::FAILURE;
        }

        $this->call('cache:clear');
        $this->info("Restored \"{$database}\" from ".basename($file).'.');
        $this->line('Photos are restored separately: extract the matching attachments-*.tar.gz into storage/app/.');

        return self::SUCCESS;
    }

    /**
     * @return \Generator<int, string>
     */
    private function chunks(string $file): \Generator
    {
        $handle = str_ends_with($file, '.gz') ? gzopen($file, 'rb') : fopen($file, 'rb');
        try {
            while (! (str_ends_with($file, '.gz') ? gzeof($handle) : feof($handle))) {
                $chunk = str_ends_with($file, '.gz') ? gzread($handle, 1024 * 1024) : fread($handle, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                yield $chunk;
            }
        } finally {
            str_ends_with($file, '.gz') ? gzclose($handle) : fclose($handle);
        }
    }
}
