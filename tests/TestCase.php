<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestSchema();
    }

    private function createTestSchema(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'barangay_reporting_test') {
            throw new RuntimeException('Automated tests must use the dedicated barangay_reporting_test MySQL database.');
        }

        // Start from an empty database so the real schema is the only source of truth.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) {
            Schema::dropIfExists(array_values((array) $row)[0]);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        // Load only the table definitions from database/mysql_schema.sql (no database name, no seed data).
        $sql = explode('-- ===== SEED DATA', file_get_contents(base_path('database/mysql_schema.sql')))[0];
        $sql = preg_replace('/CREATE DATABASE[^;]*;/', '', $sql, 1);
        $sql = preg_replace('/^USE [^;]*;/m', '', $sql, 1);
        DB::unprepared($sql);

        DB::table('REGISTRATION_CAPACITY_LOCK')->insert(['id' => 1]);
    }
}
