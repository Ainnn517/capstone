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
        if (! Schema::hasTable('STATUS_LOG')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `STATUS_LOG` (
  `log_id` int unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int unsigned NOT NULL,
  `changed_by` int unsigned DEFAULT NULL,
  `old_status` enum('Submitted','Validated','Dispatched','Resolved','Rejected') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status` enum('Submitted','Validated','Dispatched','Resolved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_log_report_time` (`report_id`,`changed_at`),
  KEY `idx_log_changed_by` (`changed_by`),
  CONSTRAINT `fk_log_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `USERS` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_log_report` FOREIGN KEY (`report_id`) REFERENCES `ISSUE_REPORT` (`report_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('STATUS_LOG');
    }
};
