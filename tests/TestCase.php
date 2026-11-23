<?php

namespace Tests;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
    }

    // The local disk with its test checks (assertExists, assertMissing). Storage::disk() is
    // documented as a plain filesystem, so editors flag those checks as unknown methods.
    protected function localDisk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        return $disk;
    }

    private function createTestSchema(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'barangay_reporting_test') {
            throw new RuntimeException('Automated tests must use the dedicated barangay_reporting_test MySQL database.');
        }

        // Rebuild the schema from the migrations (the source of truth for every environment).
        // SchemaGuardTest checks they match database/mysql_schema.sql exactly.
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
