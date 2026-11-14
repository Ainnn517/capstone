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

    // The migrations and database/mysql_schema.sql must describe exactly the same database,
    // so both new installs (migrate) and manual imports (SQL file) end up identical.
    public function test_migrations_match_the_reference_sql_schema(): void
    {
        $fromMigrations = $this->schemaSnapshot();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (DB::select('SHOW TABLES') as $row) {
            Schema::dropIfExists(array_values((array) $row)[0]);
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::unprepared(explode('-- ===== SEED DATA', file_get_contents(base_path('database/mysql_schema.sql')))[0]);
        $fromSqlFile = $this->schemaSnapshot();

        $this->assertSame($fromSqlFile, $fromMigrations);
    }

    /**
     * @return array<string, list<string>>
     */
    private function schemaSnapshot(): array
    {
        $rows = fn (string $sql): array => array_map(fn ($row): string => implode(' | ', array_map('strval', (array) $row)), DB::select($sql));

        return [
            'columns' => $rows("SELECT table_name, column_name, column_type, is_nullable, IFNULL(column_default, 'NULL'), extra, IFNULL(collation_name, '')
                FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name <> 'migrations' ORDER BY 1, 2"),
            'indexes' => $rows("SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index)
                FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name <> 'migrations'
                GROUP BY table_name, index_name, non_unique ORDER BY 1, 2"),
            'foreign_keys' => $rows('SELECT rc.table_name, rc.constraint_name, rc.referenced_table_name, rc.update_rule, rc.delete_rule
                FROM information_schema.referential_constraints rc WHERE rc.constraint_schema = DATABASE() ORDER BY 1, 2'),
            'checks' => $rows('SELECT tc.table_name, cc.constraint_name, cc.check_clause
                FROM information_schema.check_constraints cc
                JOIN information_schema.table_constraints tc ON tc.constraint_schema = cc.constraint_schema AND tc.constraint_name = cc.constraint_name
                WHERE cc.constraint_schema = DATABASE() ORDER BY 1, 2'),
            'tables' => $rows("SELECT table_name, engine, table_collation FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name <> 'migrations' ORDER BY 1"),
        ];
    }
}
