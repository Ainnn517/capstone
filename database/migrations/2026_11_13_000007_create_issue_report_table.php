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
        if (! Schema::hasTable('ISSUE_REPORT')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ISSUE_REPORT` (
  `report_id` int unsigned NOT NULL AUTO_INCREMENT,
  `tracking_code` varchar(23) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `category_id` int unsigned NOT NULL,
  `team_id` int unsigned DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_id` int unsigned NOT NULL,
  `landmark` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `status` enum('Submitted','Validated','Dispatched','Resolved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Submitted',
  `date_submitted` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_resolved` datetime DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`report_id`),
  UNIQUE KEY `uq_report_tracking_code` (`tracking_code`),
  KEY `idx_report_user_archived` (`user_id`,`archived_at`),
  KEY `idx_report_archive_status_submitted` (`archived_at`,`status`,`date_submitted`),
  KEY `idx_report_submitted` (`date_submitted`),
  KEY `idx_report_category` (`category_id`),
  KEY `idx_report_team` (`team_id`),
  KEY `idx_report_location` (`location_id`),
  KEY `idx_report_archived_by` (`archived_by`),
  CONSTRAINT `fk_report_archived_by` FOREIGN KEY (`archived_by`) REFERENCES `USERS` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_report_category` FOREIGN KEY (`category_id`) REFERENCES `CATEGORY` (`category_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_report_location` FOREIGN KEY (`location_id`) REFERENCES `LOCATION` (`location_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_report_team` FOREIGN KEY (`team_id`) REFERENCES `TEAM` (`team_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_report_user` FOREIGN KEY (`user_id`) REFERENCES `USERS` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `chk_report_date_order` CHECK (((`date_resolved` is null) or (`date_resolved` >= `date_submitted`))),
  CONSTRAINT `chk_report_description` CHECK ((char_length(trim(`description`)) between 1 and 500)),
  CONSTRAINT `chk_report_gps_pair` CHECK (((`latitude` is null) = (`longitude` is null))),
  CONSTRAINT `chk_report_latitude` CHECK (((`latitude` is null) or (`latitude` between -(90) and 90))),
  CONSTRAINT `chk_report_longitude` CHECK (((`longitude` is null) or (`longitude` between -(180) and 180))),
  CONSTRAINT `chk_report_resolved_date` CHECK (((`status` <> _utf8mb4'Resolved') or (`date_resolved` is not null))),
  CONSTRAINT `chk_report_resolved_only` CHECK (((`status` = _utf8mb4'Resolved') or (`date_resolved` is null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ISSUE_REPORT');
    }
};
