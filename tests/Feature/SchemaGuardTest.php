<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaGuardTest extends TestCase
{
    // Verify the test database was loaded from the expected current schema.
    public function test_expected_schema_objects_and_constraints_are_loaded(): void
    {
        $this->assertTrue(Schema::hasTable('PASSWORD_RESET_TOKENS'));

        $userStatusDefault = DB::selectOne(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['USERS', 'account_status'],
        );
        $this->assertSame('pending', (string) $userStatusDefault->COLUMN_DEFAULT);

        $locationActiveDefault = DB::selectOne(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['LOCATION', 'is_active'],
        );
        $this->assertSame('1', (string) $locationActiveDefault->COLUMN_DEFAULT);

        $trackingCodeNullability = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['ISSUE_REPORT', 'tracking_code'],
        );
        $this->assertSame('NO', $trackingCodeNullability->IS_NULLABLE);

        $checkConstraintCount = DB::selectOne(
            'SELECT COUNT(*) AS aggregate FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = ?',
            ['CHECK'],
        );
        $this->assertGreaterThanOrEqual(10, (int) $checkConstraintCount->aggregate);
    }
}
