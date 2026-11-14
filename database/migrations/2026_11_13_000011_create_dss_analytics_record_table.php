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
        if (! Schema::hasTable('DSS_ANALYTICS_RECORD')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `DSS_ANALYTICS_RECORD` (
  `snapshot_id` int unsigned NOT NULL AUTO_INCREMENT,
  `created_by` int unsigned DEFAULT NULL,
  `source_report_count` int unsigned NOT NULL DEFAULT '0',
  `recommendations` json NOT NULL,
  `generated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`snapshot_id`),
  KEY `idx_dss_generated_at` (`generated_at`),
  KEY `fk_dss_created_by` (`created_by`),
  CONSTRAINT `fk_dss_created_by` FOREIGN KEY (`created_by`) REFERENCES `USERS` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('DSS_ANALYTICS_RECORD');
    }
};
