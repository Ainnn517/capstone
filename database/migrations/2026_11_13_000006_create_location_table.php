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
        if (! Schema::hasTable('LOCATION')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `LOCATION` (
  `location_id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `area_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uq_location_name_area` (`name`,`area_name`),
  KEY `idx_location_area` (`area_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('LOCATION');
    }
};
