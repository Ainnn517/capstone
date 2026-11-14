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
        if (! Schema::hasTable('NOTIFICATION')) {
            DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS `NOTIFICATION` (
  `notif_id` int unsigned NOT NULL AUTO_INCREMENT,
  `report_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('submitted','assigned','status_update','resolved') COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`notif_id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`),
  KEY `idx_notif_user_time` (`user_id`,`timestamp`),
  KEY `idx_notif_report` (`report_id`),
  CONSTRAINT `fk_notification_report` FOREIGN KEY (`report_id`) REFERENCES `ISSUE_REPORT` (`report_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `USERS` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_notification_is_read` CHECK ((`is_read` in (0,1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('NOTIFICATION');
    }
};
