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
        if (! Schema::hasTable('CATEGORY')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `CATEGORY` (
  `category_id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `severity_weight` tinyint unsigned NOT NULL DEFAULT '1',
  `recommended_team_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_category_name` (`name`),
  KEY `idx_category_recommended_team` (`recommended_team_id`),
  CONSTRAINT `fk_category_recommended_team` FOREIGN KEY (`recommended_team_id`) REFERENCES `TEAM` (`team_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_category_severity` CHECK ((`severity_weight` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('CATEGORY');
    }
};
