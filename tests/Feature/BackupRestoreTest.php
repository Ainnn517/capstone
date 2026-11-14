<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $finder = new ExecutableFinder;
        if ($finder->find('mysqldump') === null || $finder->find('mysql') === null) {
            $this->markTestSkipped('The MySQL client tools are not installed.');
        }
        $this->directory = sys_get_temp_dir().'/barangay-backup-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_backup_can_be_restored(): void
    {
        Category::create(['name' => 'Road Damage', 'severity_weight' => 3]);

        $this->artisan('backup:run', ['--path' => $this->directory, '--no-attachments' => true])->assertSuccessful();
        $backups = glob($this->directory.'/*.sql.gz');
        $this->assertCount(1, $backups);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($backups[0])), -4), 'Backups must not be readable by other users.');

        // Something goes wrong after the backup.
        DB::table('CATEGORY')->update(['name' => 'Corrupted']);
        Category::create(['name' => 'Added later', 'severity_weight' => 1]);

        $this->artisan('backup:restore', ['file' => $backups[0], '--force' => true])->assertSuccessful();

        $this->assertSame(['Road Damage'], DB::table('CATEGORY')->pluck('name')->all());
    }

    public function test_restore_asks_for_the_database_name(): void
    {
        $this->artisan('backup:run', ['--path' => $this->directory, '--no-attachments' => true])->assertSuccessful();
        $backup = glob($this->directory.'/*.sql.gz')[0];

        $this->artisan('backup:restore', ['file' => $backup])
            ->expectsQuestion('Type the database name to continue', 'wrong-name')
            ->expectsOutput('Restore cancelled.')
            ->assertFailed();
    }

    public function test_old_backups_are_pruned(): void
    {
        File::ensureDirectoryExists($this->directory);
        $old = $this->directory.'/barangay_reporting_test-20200101-000000.sql.gz';
        file_put_contents($old, 'old');
        touch($old, now()->subDays(30)->getTimestamp());

        $this->artisan('backup:run', ['--path' => $this->directory, '--no-attachments' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertCount(1, glob($this->directory.'/*.sql.gz'));
    }
}
