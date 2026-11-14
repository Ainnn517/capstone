<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Baseline schema (generated from database/mysql_schema.sql). On a database that already has
// this table the migration only records itself as done, so existing installations can run
// `php artisan migrate` safely. Make later schema changes in NEW migrations.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('REGISTRATION_CAPACITY_LOCK')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `REGISTRATION_CAPACITY_LOCK` (
  `id` tinyint unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
        // The single row that serialises registrations (see AuthController::register).
        DB::table('REGISTRATION_CAPACITY_LOCK')->insertOrIgnore(['id' => 1]);

    }

    public function down(): void
    {
        Schema::dropIfExists('REGISTRATION_CAPACITY_LOCK');
    }
};
